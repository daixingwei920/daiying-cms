#!/usr/bin/env python3
"""Durable, isolated V1 knowledge synchronization. No model or network calls."""
import argparse
from contextlib import contextmanager
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path, PurePosixPath
import re
import sqlite3
import subprocess
import tempfile
import time

from legacy_incremental import category_for, extract_symbols, plugin_id_for, theme_id_for

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
spec = importlib.util.spec_from_file_location("product_knowledge", ROOT / "scripts/product_knowledge.py")
product = importlib.util.module_from_spec(spec)
spec.loader.exec_module(product)


class ReviewRequired(ValueError):
    pass


def canonical(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))


def digest(value):
    return hashlib.sha256(canonical(value).encode()).hexdigest()


def require(condition, message):
    if not condition:
        raise ValueError(message)


def git(repo, *args, binary=False):
    return subprocess.check_output(["git", "-C", str(repo), *args],
                                   stderr=subprocess.DEVNULL, text=not binary)


def resolve(repo, ref):
    require(isinstance(ref, str) and not ref.startswith("-"), "invalid Git ref")
    return git(repo, "rev-parse", "--verify", ref + "^{commit}").strip()


def blob(repo, commit, path):
    parts = PurePosixPath(path)
    require(not parts.is_absolute() and ".." not in parts.parts, "unsafe source path")
    return git(repo, "show", f"{commit}:{path}", binary=True)


def ancestor(repo, old, new):
    return subprocess.run(["git", "-C", str(repo), "merge-base", "--is-ancestor", old, new],
                          stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0


def atomic(path, data):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, name = tempfile.mkstemp(dir=path.parent, prefix=".kb-")
    try:
        with os.fdopen(fd, "w") as handle:
            handle.write(canonical(data) + "\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


class Sync:
    def __init__(self, config):
        self.cfg = config
        require(config["environment"] == "isolated", "only isolated operation is implemented")
        self.root = Path(config["isolation_root"]).resolve()
        require((self.root / ".daiying-knowledge-isolated").read_text().strip() == "ISOLATED", "isolation marker missing")
        self.state = self.output(config["state_dir"])
        self.state.mkdir(parents=True, exist_ok=True)
        self.sources = {s["id"]: s for s in config["sources"]}
        require(len(self.sources) == len(config["sources"]) > 0, "duplicate or empty sources")
        for key in self.sources:
            require(re.fullmatch(r"[a-zA-Z0-9._-]{1,64}", key) is not None, "invalid source id")
        consumers = config["consumers"]
        require(len({c["id"] for c in consumers}) == len(consumers) > 0, "duplicate or empty consumers")
        for c in consumers:
            require(c["kind"] in {"file", "customer_service_sqlite"}, "unsupported consumer")
            self.output(c["destination"] if c["kind"] == "file" else c["database"])
        self.db = sqlite3.connect(self.state / "queue.sqlite3", timeout=5)
        self.db.row_factory = sqlite3.Row
        self.db.executescript("""
          PRAGMA journal_mode=WAL;
          CREATE TABLE IF NOT EXISTS jobs (
            id TEXT PRIMARY KEY, source TEXT NOT NULL, plan TEXT NOT NULL,
            status TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0,
            next_at REAL NOT NULL DEFAULT 0, error TEXT, snapshot TEXT, counts TEXT);
          CREATE TABLE IF NOT EXISTS events (id TEXT PRIMARY KEY, hash TEXT NOT NULL, job TEXT NOT NULL);
          CREATE TABLE IF NOT EXISTS sources (id TEXT PRIMARY KEY, desired TEXT, current_job TEXT);
          CREATE TABLE IF NOT EXISTS deliveries (
            job TEXT, consumer TEXT, status TEXT, result TEXT,
            PRIMARY KEY(job, consumer));
          CREATE TABLE IF NOT EXISTS consumers (id TEXT, source TEXT, job TEXT, PRIMARY KEY(id, source));
          CREATE TABLE IF NOT EXISTS timers (id TEXT PRIMARY KEY, last_at REAL NOT NULL);
          CREATE TABLE IF NOT EXISTS audits (id INTEGER PRIMARY KEY, at REAL, kind TEXT, source TEXT, detail TEXT);
          CREATE TABLE IF NOT EXISTS poll_failures (id TEXT PRIMARY KEY, attempts INTEGER, next_at REAL, status TEXT);
        """)
        self.max_attempts = int(config.get("max_attempts", 3))
        self.retry_delay = float(config.get("retry_delay_seconds", 30))
        require(1 <= self.max_attempts <= 10 and self.retry_delay >= 0, "invalid retry policy")

    def output(self, path):
        p = Path(path).resolve()
        require(p.is_relative_to(self.root) and p != self.root, "write outside isolation root")
        return p

    @contextmanager
    def locked(self):
        with (self.state / "runner.lock").open("a") as handle:
            fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
            yield

    def audit(self, kind, source, detail):
        self.db.execute("INSERT INTO audits(at,kind,source,detail) VALUES(?,?,?,?)",
                        (time.time(), kind, source, canonical(detail)))
        self.db.commit()

    def version(self, source, commit):
        data = blob(source["repository"], commit, source["version_file"]).decode()
        if source["version_file"].endswith(".json"):
            return str(json.loads(data)["version"])
        found = re.search(r"'version'\s*=>\s*'([^']+)'", data)
        require(found is not None, "source version missing")
        return found.group(1)

    def target(self, source, tag):
        require(isinstance(tag, str) and re.fullmatch(r"v?\d+\.\d+\.\d+", tag) is not None, "stable semantic tag required")
        commit = resolve(source["repository"], "refs/tags/" + tag)
        version = self.version(source, commit)
        require(tag.removeprefix("v") == version, "release version/tag mismatch")
        return {"commit": commit, "tag": tag, "version": version}

    def enqueue(self, source_id, target, event_id, event_hash):
        source = self.sources[source_id]
        old = self.db.execute("SELECT hash,job FROM events WHERE id=?", (event_id,)).fetchone()
        if old:
            require(old["hash"] == event_hash, "event id reused with different payload")
            return old["job"]
        row = self.db.execute("SELECT desired FROM sources WHERE id=?", (source_id,)).fetchone()
        desired = json.loads(row[0]) if row else None
        if desired and desired["commit"] != target["commit"]:
            require(ancestor(source["repository"], desired["commit"], target["commit"]), "stale or divergent release rejected")
        plan = dict(target, source=source_id, source_contract=source, consumers=self.cfg["consumers"],
                    knowledge_commit=resolve(source["repository"], source.get("knowledge_ref", "HEAD")))
        job = digest(plan)
        self.db.execute("INSERT OR IGNORE INTO jobs(id,source,plan,status) VALUES(?,?,?,'pending')",
                        (job, source_id, canonical(plan)))
        self.db.execute("INSERT INTO events VALUES(?,?,?)", (event_id, event_hash, job))
        self.db.execute("INSERT INTO sources(id,desired) VALUES(?,?) ON CONFLICT(id) DO UPDATE SET desired=excluded.desired",
                        (source_id, canonical(target)))
        self.db.commit()
        return job

    def release(self, source_id, payload):
        source = self.sources[source_id]
        require(payload.get("action") == "published", "only published release events accepted")
        release = payload["release"]
        require(release.get("draft") is False and release.get("prerelease") is False
                and bool(release.get("published_at")), "draft/failed/unpublished release rejected")
        require(payload["repository"]["full_name"] == source["github_repository"], "foreign release rejected")
        require(re.fullmatch(r"v?\d+\.\d+\.\d+", release["tag_name"]) is not None, "stable semantic tag required")
        # Spool before taking the worker lock or opening the source repository.
        # Keep only public release metadata, never the full event body or tokens.
        normalized = {"action": "published", "repository": {"full_name": source["github_repository"]},
                      "release": {key: release[key] for key in ("id", "tag_name", "draft", "prerelease", "published_at")}}
        path = self.state / "inbox" / (digest({"source": source_id, "event": normalized}) + ".json")
        if path.exists():
            receipt = json.loads(path.read_text())
        else:
            receipt = {"source": source_id, "event": normalized, "status": "pending", "attempts": 0, "next_at": 0, "job": None}
            atomic(path, receipt)
        try:
            with self.locked():
                self.accept_receipt(path, receipt, time.time())
        except BlockingIOError:
            return None
        if receipt["status"] == "rejected":
            raise ValueError(receipt["error"])
        return receipt["job"]

    def accept_receipt(self, path, receipt, now):
        if receipt["status"] not in {"pending", "retry_wait"} or receipt["next_at"] > now:
            return
        receipt["attempts"] += 1
        try:
            source_id, payload = receipt["source"], receipt["event"]
            target = self.target(self.sources[source_id], payload["release"]["tag_name"])
            receipt["job"] = self.enqueue(source_id, target, f"release:{source_id}:{payload['release']['id']}", digest(payload))
            receipt["status"] = "accepted"
        except ValueError as error:
            receipt.update(status="rejected", error=str(error))
        except (OSError, subprocess.SubprocessError):
            receipt["status"] = "failed" if receipt["attempts"] >= self.max_attempts else "retry_wait"
            receipt["next_at"] = now + self.retry_delay * 2 ** (receipt["attempts"] - 1)
            receipt["error"] = "source unavailable; fetch/availability requires operator attention"
        atomic(path, receipt)

    def drain_inbox(self, now):
        for path in sorted((self.state / "inbox").glob("*.json")):
            self.accept_receipt(path, json.loads(path.read_text()), now)

    def inventory(self, source, target, previous):
        repo, commit = source["repository"], target["commit"]
        result, reads, reused = {}, 0, 0
        entries = git(repo, "ls-tree", "-r", "-z", commit, binary=True).split(b"\0")
        allowed = source["include"]
        with tempfile.TemporaryDirectory(prefix="kb-symbols-") as temp:
            stage = Path(temp)
            for entry in entries:
                if not entry:
                    continue
                metadata, raw_path = entry.split(b"\t", 1)
                path = raw_path.decode()
                mode = metadata.split()[0]
                if mode not in {b"100644", b"100755"}:
                    continue
                if not any(path == prefix or path.startswith(prefix.rstrip("/") + "/") for prefix in allowed):
                    continue
                parts = PurePosixPath(path)
                if any(p.startswith(".") or p in {"storage", "uploads", "vendor", "node_modules", "secrets", "backups"} for p in parts.parts):
                    continue
                if path.startswith("config/") or parts.suffix not in {".php", ".md", ".json", ".txt"}:
                    continue
                oid = metadata.split()[2].decode()
                old = previous.get(path)
                if old and old.get("git_blob") == oid:
                    result[path] = old
                    reused += 1
                    continue
                raw = blob(repo, commit, path)
                reads += 1
                require(len(raw) <= 2_000_000, "oversized source file")
                symbols = []
                if parts.suffix == ".php":
                    p = stage / path
                    p.parent.mkdir(parents=True, exist_ok=True)
                    p.write_bytes(raw)
                    symbols = extract_symbols(stage, path, target["version"], "committed")
                result[path] = {"sha256": hashlib.sha256(raw).hexdigest(), "git_blob": oid, "categories": category_for(path),
                                "plugin_id": plugin_id_for(path), "theme_id": theme_id_for(path),
                                "symbols": symbols, "visibility": "INTERNAL", "status": "pending_review"}
        return result, {"files_read": reads, "files_reused": reused}

    def build(self, plan):
        source = plan["source_contract"]
        repo = Path(source["repository"])
        previous = self.db.execute("SELECT current_job FROM sources WHERE id=?", (plan["source"],)).fetchone()
        old = self.load_snapshot(previous[0]) if previous and previous[0] else {"records": [], "inventory": {}}
        inventory, inventory_stats = self.inventory(source, plan, old["inventory"])
        records, excluded = [], 0
        package_path = source.get("package_path")
        if package_path:
            try:
                package = json.loads(blob(repo, plan["knowledge_commit"], package_path))
                product.validate(package, root=repo, check_worktree=False)
                require(package["verified_version"] == plan["version"], "knowledge version needs review")
                for record in package["records"]:
                    for evidence in record["source_evidence"]:
                        if hashlib.sha256(blob(repo, plan["commit"], evidence["path"])).hexdigest() != evidence["sha256"]:
                            raise ReviewRequired("official evidence changed: " + evidence["path"])
                for record in product.public_records(package):
                    records.append({"id": record["id"], "title": record["capability"], "content": record["description"],
                                    "topic": record["topic"], "priority": "P0", "visibility": "USER-FACING",
                                    "source_path": package_path, "fact_commit": record["source_commit"],
                                    "evidence": [{k: v for k, v in e.items() if k != "anchor"} for e in record["source_evidence"]]})
                excluded += len(package["records"]) - len(records)
            except ReviewRequired:
                raise
            except (ValueError, KeyError, subprocess.CalledProcessError) as error:
                raise ReviewRequired("official package or changed source requires review") from error
        if source.get("articles_path"):
            rows = json.loads(blob(repo, plan["knowledge_commit"], source["articles_path"]))
            for row in rows:
                eligible = (row.get("status") == "published" and row.get("grade") in {"A", "B"}
                            and row.get("visibility") == "USER-FACING" and row.get("user_facing") is True
                            and row.get("review_status") in {"reviewed", "ordinary"})
                if not eligible:
                    excluded += 1
                    continue
                require(bool(row.get("id")) and bool(row.get("content", "").strip()), "invalid article record")
                records.append({"id": "article." + str(row["id"]), "title": row["title"], "content": row["content"],
                                "topic": row.get("topic", ""), "priority": "P1" if row["review_status"] == "reviewed" else "P2",
                                "visibility": "USER-FACING", "source_path": source["articles_path"],
                                "fact_commit": plan["knowledge_commit"], "url": row.get("url", "")})
        seen, texts, unique = set(), set(), []
        for record in sorted(records, key=lambda r: (r["priority"], r["id"])):
            require(record["id"] not in seen, "duplicate logical knowledge id")
            seen.add(record["id"])
            key = hashlib.sha256(" ".join(record["content"].split()).encode()).hexdigest()
            if key in texts:
                excluded += 1
                continue
            texts.add(key)
            record["content_hash"] = digest(record)
            unique.append(record)
        snapshot = dict(plan, knowledge_version=product.NAME, records=unique, inventory=inventory,
                        inventory_stats=inventory_stats, excluded=excluded,
                        environment="isolated", runtime_validation="NO_MODEL_OR_PRODUCTION_VALIDATION")
        before, after = ({r["id"]: r["content_hash"] for r in s["records"]} for s in (old, snapshot))
        counts = {"added": len(after.keys() - before.keys()), "withdrawn": len(before.keys() - after.keys()),
                  "updated": sum(before[k] != after[k] for k in before.keys() & after.keys()),
                  "unchanged": sum(before[k] == after[k] for k in before.keys() & after.keys()), "excluded": excluded}
        counts["changed_sources"] = [p for p in sorted(set(old["inventory"]) | set(inventory))
                                     if old["inventory"].get(p, {}).get("sha256") != inventory.get(p, {}).get("sha256")]
        counts.update(inventory_stats)
        return snapshot, counts

    def load_snapshot(self, job):
        row = self.db.execute("SELECT snapshot FROM jobs WHERE id=?", (job,)).fetchone()
        require(row and row[0], "snapshot missing")
        capsule = json.loads(self.output(row[0]).read_text())
        require(digest(capsule["snapshot"]) == capsule["sha256"], "snapshot integrity failure")
        return capsule["snapshot"]

    @staticmethod
    def projection(snapshot):
        return {k: v for k, v in snapshot.items() if k not in {"inventory", "inventory_stats", "source_contract", "consumers"}}

    def deliver(self, consumer, job, snapshot, verify=False):
        public = self.projection(snapshot)
        if consumer["kind"] == "file":
            target = self.output(consumer["destination"]) / snapshot["source"] / (job + ".json")
            # Consumer projection excludes the internal symbol/source inventory.
            pointer = {"job": job, "sha256": digest(public), "file": target.name}
            if verify:
                require(json.loads(target.read_text()) == public, "file consumer drift")
                require(json.loads((target.parent / "current.json").read_text()) == pointer, "consumer pointer drift")
            else:
                atomic(target, public)
                atomic(target.parent / "current.json", pointer)
            return {"records": len(public["records"]), "sha256": digest(public)}
        database = self.output(consumer["database"])
        marker = json.loads(database.with_suffix(database.suffix + ".isolated.json").read_text())
        require(marker == {"environment": "isolated", "site_id": consumer["site_id"]}, "consumer database marker mismatch")
        proc = subprocess.run([consumer.get("php", "php"), str(HERE / "customer_service.php"),
                               *(["--verify"] if verify else []),
                               str(database), str(Path(consumer["plugin_root"]).resolve()), consumer["site_id"]],
                              input=canonical(public), text=True, capture_output=True, timeout=30)
        require(proc.returncode == 0, "customer-service adapter failed; no credentials or stderr retained")
        ack = json.loads(proc.stdout)
        require(ack["sha256"] == digest(public) and ack["site_id"] == consumer["site_id"], "invalid consumer acknowledgement")
        return ack

    def work(self, now=None):
        now = time.time() if now is None else now
        with self.locked():
            self.drain_inbox(now)
            # A killed worker leaves durable running state. Reclaim under the exclusive lock.
            self.db.execute("UPDATE jobs SET status='retry_wait',next_at=? WHERE status='running'", (now,))
            row = self.db.execute("SELECT * FROM jobs WHERE status IN ('pending','retry_wait') AND next_at<=? ORDER BY rowid LIMIT 1", (now,)).fetchone()
            if not row:
                self.db.commit()
                return None
            job, plan, attempts = row["id"], json.loads(row["plan"]), row["attempts"] + 1
            if attempts > self.max_attempts:
                self.db.execute("UPDATE jobs SET status='failed',error='attempt limit after interruption' WHERE id=?", (job,))
                self.db.commit()
                return job
            current = self.db.execute("SELECT desired,current_job FROM sources WHERE id=?", (plan["source"],)).fetchone()
            desired = json.loads(current["desired"])
            if plan["commit"] != desired["commit"]:
                self.db.execute("UPDATE jobs SET status='superseded' WHERE id=?", (job,))
                self.db.commit()
                return job
            if current["current_job"]:
                last = self.load_snapshot(current["current_job"])
                if not ancestor(plan["source_contract"]["repository"], last["knowledge_commit"], plan["knowledge_commit"]):
                    self.db.execute("UPDATE jobs SET status='superseded',error='older knowledge revision' WHERE id=?", (job,))
                    self.db.commit()
                    return job
            self.db.execute("UPDATE jobs SET status='running',attempts=? WHERE id=?", (attempts, job))
            self.db.commit()
            active_consumer = None
            try:
                if row["snapshot"]:
                    snapshot = self.load_snapshot(job)
                else:
                    snapshot, counts = self.build(plan)
                    path = self.state / "snapshots" / (job + ".json")
                    atomic(path, {"sha256": digest(snapshot), "snapshot": snapshot})
                    self.db.execute("UPDATE jobs SET snapshot=?,counts=? WHERE id=?", (str(path), canonical(counts), job))
                    self.db.commit()
                for consumer in plan["consumers"]:
                    active_consumer = consumer["id"]
                    ack = self.deliver(consumer, job, snapshot)
                    self.db.execute("INSERT INTO deliveries VALUES(?,?,?,?) ON CONFLICT(job,consumer) DO UPDATE SET status=excluded.status,result=excluded.result",
                                    (job, active_consumer, "succeeded", canonical(ack)))
                    self.db.execute("INSERT INTO consumers VALUES(?,?,?) ON CONFLICT(id,source) DO UPDATE SET job=excluded.job",
                                    (active_consumer, plan["source"], job))
                    self.db.commit()
                self.db.execute("UPDATE sources SET current_job=? WHERE id=?", (job, plan["source"]))
                self.db.execute("UPDATE jobs SET status='succeeded',error=NULL WHERE id=?", (job,))
                self.db.commit()
            except Exception as error:
                review = isinstance(error, ReviewRequired)
                state = "needs_review" if review else "failed" if attempts >= self.max_attempts else "retry_wait"
                message = str(error) if review else "consumer " + str(active_consumer) + " or isolated build failed"
                self.db.execute("UPDATE jobs SET status=?,next_at=?,error=? WHERE id=?",
                                (state, now + self.retry_delay * 2 ** (attempts - 1), message, job))
                if active_consumer:
                    self.db.execute("INSERT INTO deliveries VALUES(?,?,?,?) ON CONFLICT(job,consumer) DO UPDATE SET status=excluded.status,result=excluded.result",
                                    (job, active_consumer, state, "{}"))
                self.db.commit()
            self.report()
            return job

    def tick(self, now=None, force=False):
        now = time.time() if now is None else now
        with self.locked():
            self.drain_inbox(now)
            for name, interval in (("daily", float(self.cfg.get("daily_seconds", 86400))),
                                   ("weekly", float(self.cfg.get("weekly_seconds", 604800)))):
                require(interval > 0, "invalid timer interval")
                for key, source in self.sources.items():
                    check_id = name + ":" + key
                    last = self.db.execute("SELECT last_at FROM timers WHERE id=?", (check_id,)).fetchone()
                    if not force and last and now - last[0] < interval:
                        continue
                    failure = self.db.execute("SELECT * FROM poll_failures WHERE id=?", (check_id,)).fetchone()
                    if failure and (failure["status"] == "failed" or failure["next_at"] > now):
                        continue
                    try:
                        previous = self.db.execute("SELECT desired FROM sources WHERE id=?", (key,)).fetchone()
                        target = json.loads(previous[0]) if previous else self.target(source, source["initial_tag"])
                        if name == "daily":
                            revision = resolve(source["repository"], source.get("knowledge_ref", "HEAD"))
                            contract = digest({"source": source, "consumers": self.cfg["consumers"]})
                            self.enqueue(key, target, f"daily:{key}:{int(now // interval)}:{revision}:{contract}",
                                         digest({"target": target, "revision": revision, "contract": contract, "slot": int(now // interval)}))
                            self.check_consumers(key)
                        watched = resolve(source["repository"], source.get("watch_ref", "HEAD"))
                        changes = git(source["repository"], "diff", "--name-only", "--no-renames", "-z", target["commit"], watched).split("\0")
                        paths = [p for p in changes if p and any(p == prefix or p.startswith(prefix.rstrip("/") + "/") for prefix in source["include"])]
                        self.audit(name + "_consistency", key, {"approved_commit": target["commit"], "watched_commit": watched,
                                                              "changed_paths": paths,
                                                              "needs_release_or_review": watched != target["commit"]})
                        self.db.execute("DELETE FROM poll_failures WHERE id=?", (check_id,))
                        self.db.execute("INSERT INTO timers VALUES(?,?) ON CONFLICT(id) DO UPDATE SET last_at=excluded.last_at", (check_id, now))
                    except Exception:
                        attempts = failure["attempts"] + 1 if failure else 1
                        status = "failed" if attempts >= self.max_attempts else "retry_wait"
                        self.db.execute("INSERT OR REPLACE INTO poll_failures VALUES(?,?,?,?)",
                                        (check_id, attempts, now + self.retry_delay * 2 ** (attempts - 1), status))
                        self.audit("source_check_failed", key, {"check": name, "attempts": attempts, "status": status})
                self.db.commit()
            self.report()

    def check_consumers(self, source):
        for cursor in self.db.execute("SELECT * FROM consumers WHERE source=?", (source,)).fetchall():
            snapshot = self.load_snapshot(cursor["job"])
            consumer = next(c for c in snapshot["consumers"] if c["id"] == cursor["id"])
            try:
                self.deliver(consumer, cursor["job"], snapshot, verify=True)
            except Exception:
                # Bounded repair of a previously acknowledged snapshot, not a new version.
                self.db.execute("UPDATE jobs SET status='pending',attempts=0,next_at=0,error='consumer drift' WHERE id=? AND status='succeeded'", (cursor["job"],))
                self.audit("consumer_drift", source, {"consumer": cursor["id"], "job": cursor["job"]})

    def retry(self, job):
        with self.locked():
            row = self.db.execute("SELECT status,attempts,source FROM jobs WHERE id=?", (job,)).fetchone()
            require(row and row["status"] == "failed", "only failed jobs can be manually retried; review requires new knowledge")
            self.db.execute("UPDATE jobs SET status='pending',attempts=0,next_at=0 WHERE id=?", (job,))
            self.audit("manual_retry", row["source"], {"job": job, "previous_attempts": row["attempts"]})

    def retry_checks(self):
        with self.locked():
            self.db.execute("DELETE FROM poll_failures")
            self.audit("manual_retry_checks", "all", {})

    def retry_events(self):
        with self.locked():
            for path in (self.state / "inbox").glob("*.json"):
                receipt = json.loads(path.read_text())
                if receipt["status"] == "failed":
                    receipt.update(status="pending", attempts=0, next_at=0)
                    atomic(path, receipt)
            self.audit("manual_retry_events", "all", {})

    def report(self):
        jobs = []
        for row in self.db.execute("SELECT * FROM jobs ORDER BY rowid"):
            item = dict(row)
            item["plan"] = json.loads(item["plan"])
            item["counts"] = json.loads(item["counts"]) if item["counts"] else None
            item["deliveries"] = [dict(x) for x in self.db.execute("SELECT consumer,status,result FROM deliveries WHERE job=?", (row["id"],))]
            jobs.append(item)
        report = {"environment": "isolated", "production_deployed": False, "jobs": jobs,
                  "sources": [dict(x) for x in self.db.execute("SELECT * FROM sources")],
                  "consumer_cursors": [dict(x) for x in self.db.execute("SELECT * FROM consumers")],
                  "source_check_failures": [dict(x) for x in self.db.execute("SELECT * FROM poll_failures")],
                  "release_inbox": [json.loads(p.read_text()) for p in sorted((self.state / "inbox").glob("*.json"))],
                  "consistency_issues": [dict(x) for x in self.db.execute("SELECT * FROM audits ORDER BY id DESC LIMIT 100")]}
        atomic(self.state / "status.json", report)
        return report

    def query(self, source, version, question):
        row = self.db.execute("SELECT current_job FROM sources WHERE id=?", (source,)).fetchone()
        require(row and row[0], "no fully acknowledged snapshot")
        require(self.db.execute("SELECT status FROM jobs WHERE id=?", (row[0],)).fetchone()[0] == "succeeded", "snapshot awaiting consumer repair")
        snapshot = self.load_snapshot(row[0])
        require(version == snapshot["version"], "query version does not match applied knowledge")
        terms = re.findall(r"[a-z0-9_-]{2,}|[\u4e00-\u9fff]{2,}", question.lower())
        terms += [t[i:i + 2] for t in terms if re.fullmatch(r"[\u4e00-\u9fff]+", t) for i in range(len(t) - 1)]
        found = []
        for r in snapshot["records"]:
            score = sum(len(t) for t in set(terms) if t in (r["title"] + r["content"] + r["topic"]).lower())
            if score:
                found.append(dict(r, score=score))
        found.sort(key=lambda r: (r["priority"], -r["score"], r["id"]))
        return {"version": version, "commit": snapshot["commit"], "matches": found[:8], "grounded": bool(found)}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--config", required=True)
    sub = parser.add_subparsers(dest="action", required=True)
    event = sub.add_parser("release")
    event.add_argument("--source", required=True)
    event.add_argument("--event-file", required=True)
    sub.add_parser("tick")
    sub.add_parser("check-now")
    sub.add_parser("work")
    sub.add_parser("status")
    retry = sub.add_parser("retry")
    retry.add_argument("--job", required=True)
    sub.add_parser("retry-checks")
    sub.add_parser("retry-events")
    daemon = sub.add_parser("daemon")
    daemon.add_argument("--interval", type=float, default=30)
    daemon.add_argument("--ticks", type=int, default=0, help="0 runs continuously")
    query = sub.add_parser("query")
    query.add_argument("--source", required=True)
    query.add_argument("--version", required=True)
    query.add_argument("question")
    args = parser.parse_args()
    sync = Sync(json.loads(Path(args.config).read_text()))
    if args.action == "release":
        result = {"job": sync.release(args.source, json.loads(Path(args.event_file).read_text()))}
    elif args.action in {"tick", "check-now"}:
        sync.tick(force=args.action == "check-now")
        result = sync.report()
    elif args.action == "work":
        result = {"job": sync.work()}
    elif args.action == "status":
        result = sync.report()
    elif args.action == "query":
        result = sync.query(args.source, args.version, args.question)
    elif args.action == "retry":
        sync.retry(args.job)
        result = sync.report()
    elif args.action == "retry-checks":
        sync.retry_checks()
        result = sync.report()
    elif args.action == "retry-events":
        sync.retry_events()
        result = sync.report()
    else:
        require(args.interval > 0 and args.ticks >= 0, "invalid daemon bounds")
        count = 0
        while args.ticks == 0 or count < args.ticks:
            sync.tick()
            sync.work()
            count += 1
            if args.ticks == 0 or count < args.ticks:
                time.sleep(args.interval)
        result = sync.report()
    print(canonical(result))


if __name__ == "__main__":
    try:
        main()
    except (ValueError, KeyError, OSError, subprocess.SubprocessError) as error:
        raise SystemExit("FAIL: " + str(error))
