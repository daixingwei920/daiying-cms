import copy
import hashlib
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import sys
import tempfile
import time
import unittest

HERE = Path(__file__).resolve().parents[1]
ROOT = HERE.parents[1]
sys.path.insert(0, str(HERE))
from sync import Sync, canonical

PLUGIN = os.environ.get("KNOWLEDGE_PLUGIN_ROOT")


class Pipeline(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="daiying-knowledge-test-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / ".daiying-knowledge-isolated").write_text("ISOLATED")
        self.repo = self.root / "repo"
        self.repo.mkdir()
        self.g("init", "-b", "main")
        self.write("config/app.example.php", "<?php return ['app'=>['version' => '1.2.83']];")
        self.write("docs/guide.md", "# SQLite 安装\nSQLite installation\n")
        self.write("docs/obsolete.md", "old source")
        self.write("system/core/Fixture.php", "<?php namespace Fixture; final class Example {\n public function go(): void {}\n private function hidden(): void {}\n}")
        self.write("storage/secret.txt", "DO NOT COPY")
        self.write(".env", "DO NOT COPY")
        self.base = self.commit("source")
        self.g("tag", "v1.2.83")
        self.package(self.base, "1.2.83")
        self.articles = [self.article("reviewed", "reviewed", "A", "安装审核文章"),
                         self.article("normal", "ordinary", "B", "安装安装安装普通文章"),
                         self.article("duplicate", "reviewed", "A", "SQLite 安装资料")]
        for status in ("draft", "pending"):
            self.articles.append(dict(self.article(status, "reviewed", "A", status), status=status))
        for grade in ("C", "D", "UNKNOWN"):
            self.articles.append(self.article(grade, "reviewed", grade, grade))
        self.articles.append(dict(self.article("internal", "reviewed", "A", "PRIVATE"), visibility="INTERNAL"))
        self.articles.append(self.article("unknown_review", "unknown", "A", "UNKNOWN"))
        self.write("articles.json", canonical(self.articles))
        self.knowledge = self.commit("knowledge")
        self.cfg = {"environment": "isolated", "isolation_root": str(self.root), "state_dir": str(self.root / "state"),
                    "max_attempts": 3, "retry_delay_seconds": 2,
                    "sources": [{"id": "cms", "repository": str(self.repo), "github_repository": "fixture/cms",
                                 "version_file": "config/app.example.php", "initial_tag": "v1.2.83",
                                 "knowledge_ref": "main", "watch_ref": "main", "package_path": "knowledge.json",
                                 "articles_path": "articles.json", "include": ["docs", "system/core", "system/migrations", "storage", ".env"]}],
                    "consumers": [{"id": "customer", "kind": "file", "destination": str(self.root / "consumer-a")},
                                  {"id": "optimizer", "kind": "file", "destination": str(self.root / "consumer-b")}]}
        self.engine = Sync(self.cfg)
        self.addCleanup(self.engine.db.close)

    def g(self, *args):
        return subprocess.check_output(["git", "-C", str(self.repo), *args], stderr=subprocess.DEVNULL, text=True).strip()

    def write(self, path, text):
        p = self.repo / path
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_text(text)

    def commit(self, message):
        self.g("add", ".")
        self.g("-c", "user.name=Isolated Test", "-c", "user.email=fixture@example.invalid", "commit", "-m", message)
        return self.g("rev-parse", "HEAD")

    def package(self, source, version):
        data = {"knowledge_version": "DAIYING_CMS_AI_PRODUCT_KNOWLEDGE_V1", "verified_version": version,
                "source_commit": source, "source_tag": "v" + version,
                "verification_scope": "SOURCE_AND_DOCUMENT_REVIEW_ONLY", "last_verified_at": "2026-10-14",
                "integration_status": {"automatic_sync": "DESIGN_ONLY", "core_optimizer_shared_knowledge": "NOT_IMPLEMENTED"}, "records": []}
        raw = subprocess.check_output(["git", "-C", str(self.repo), "show", source + ":docs/guide.md"])
        for name, visibility, audience in (("install", "USER-FACING", "Administrator"), ("internal", "INTERNAL", "Internal")):
            data["records"].append({"id": name, "description": "SQLite 安装资料" if name == "install" else "PRIVATE",
                                    "capability": "安装", "topic": "安装", "priority": "P0", "visibility": visibility,
                                    "user_facing": name == "install", "enabled_for_customer_service": name == "install",
                                    "implementation_status": "implemented",
                                    "audience": audience, "verified_version": version, "source_commit": source,
                                    "test_status": "NOT_RUN_THIS_TASK", "review_status": "SOURCE_REVIEWED",
                                    "last_verified_at": "2026-10-14", "source_evidence": [{"path": "docs/guide.md",
                                    "sha256": hashlib.sha256(raw).hexdigest(), "line_start": 1, "line_end": 1,
                                    "anchor": raw.decode().splitlines()[0]}]})
        self.write("knowledge.json", canonical(data))

    def article(self, key, review, grade, content):
        return {"id": key, "title": "安装", "content": content, "status": "published", "grade": grade,
                "review_status": review, "visibility": "USER-FACING", "user_facing": True}

    def event(self, tag="v1.2.83", rid=1):
        return {"action": "published", "repository": {"full_name": "fixture/cms"},
                "release": {"id": rid, "tag_name": tag, "draft": False, "prerelease": False, "published_at": "2026-10-14T00:00:00Z"}}

    def run_job(self, event=None):
        job = self.engine.release("cms", event or self.event())
        self.engine.work()
        return job

    def state(self, job):
        return dict(self.engine.db.execute("SELECT * FROM jobs WHERE id=?", (job,)).fetchone())

    def test_release_cli_and_replay_dedupe(self):
        path, event = self.root / "config.json", self.root / "event.json"
        path.write_text(canonical(self.cfg))
        event.write_text(canonical(self.event()))
        cmd = [sys.executable, "-B", str(HERE / "sync.py"), "--config", str(path)]
        result = json.loads(subprocess.check_output(cmd + ["release", "--source", "cms", "--event-file", str(event)], text=True))
        subprocess.check_output(cmd + ["work"], text=True)
        job = result["job"]
        self.assertEqual(self.state(job)["status"], "succeeded")
        self.assertEqual(self.engine.release("cms", self.event()), job)
        self.assertIsNone(self.engine.work())
        self.assertEqual(self.state(job)["attempts"], 1)
        conflict = self.event(); conflict["release"]["published_at"] = "other"
        with self.assertRaisesRegex(ValueError, "reused"):
            self.engine.release("cms", conflict)

    def test_ineligible_events_and_production_rejected(self):
        for mutation in ({"draft": True}, {"prerelease": True}, {"published_at": None}, {"tag_name": "v1.2.84-rc1"}):
            event = self.event(); event["release"].update(mutation)
            with self.assertRaises((ValueError, subprocess.CalledProcessError)):
                self.engine.release("cms", event)
        event = self.event(); event["action"] = "failed"
        with self.assertRaises(ValueError):self.engine.release("cms", event)
        event = self.event();event["repository"]["full_name"] = "foreign/repo"
        with self.assertRaises(ValueError):self.engine.release("cms", event)
        cfg = copy.deepcopy(self.cfg);cfg["environment"] = "production"
        with self.assertRaisesRegex(ValueError, "only isolated"):Sync(cfg)
        with self.assertRaisesRegex(ValueError, "outside"):
            self.engine.output(self.root.parent / "production.sqlite")

    def test_policy_dedupe_priority_and_unknown(self):
        job = self.run_job()
        snapshot = self.engine.load_snapshot(job)
        self.assertEqual({r["id"] for r in snapshot["records"]}, {"install", "article.reviewed", "article.normal"})
        matches = self.engine.query("cms", "1.2.83", "安装")["matches"]
        self.assertEqual([r["priority"] for r in matches], ["P0", "P1", "P2"])
        self.assertFalse(self.engine.query("cms", "1.2.83", "quantum teleportation")["grounded"])
        with self.assertRaisesRegex(ValueError, "version"):
            self.engine.query("cms", "1.2.84", "安装")
        self.assertNotIn("storage/secret.txt", snapshot["inventory"])
        self.assertNotIn(".env", snapshot["inventory"])
        self.assertTrue(snapshot["inventory"]["system/core/Fixture.php"]["symbols"])
        public = json.loads((self.root / "consumer-a/cms" / (job + ".json")).read_text())
        self.assertNotIn("inventory", public)
        self.assertNotIn("PRIVATE", canonical(public))

    def test_daily_detects_update_and_withdrawal(self):
        old = self.run_job()
        self.articles[0]["status"] = "pending"
        self.articles[1]["content"] = "更新后的安装普通文章"
        self.write("articles.json", canonical(self.articles));self.commit("article changes")
        self.engine.tick(now=1000000);self.engine.work()
        current = self.engine.db.execute("SELECT current_job FROM sources").fetchone()[0]
        self.assertNotEqual(current, old)
        self.assertEqual(json.loads(self.state(current)["counts"])["withdrawn"], 1)
        self.assertNotIn("article.reviewed", {r["id"] for r in self.engine.load_snapshot(current)["records"]})
        self.assertEqual(json.loads(self.state(current)["counts"])["files_read"],0)
        self.assertGreater(json.loads(self.state(current)["counts"])["files_reused"],0)
        before = self.engine.db.execute("SELECT count(*) FROM jobs").fetchone()[0]
        self.engine.tick(now=1000001)
        self.assertEqual(self.engine.db.execute("SELECT count(*) FROM jobs").fetchone()[0], before)
        self.engine.tick(now=1604801)
        self.assertEqual(self.engine.db.execute("SELECT count(*) FROM audits WHERE kind='weekly_consistency'").fetchone()[0], 2)

    def test_real_timer_process_and_restart(self):
        self.cfg.update(daily_seconds=.02, weekly_seconds=.02)
        path = self.root / "config.json";path.write_text(canonical(self.cfg))
        result = json.loads(subprocess.check_output([sys.executable, "-B", str(HERE / "sync.py"), "--config", str(path),
                                                  "daemon", "--interval", ".03", "--ticks", "3"], text=True))
        self.assertEqual(len(result["jobs"]), 1)
        self.assertEqual(result["jobs"][0]["status"], "succeeded")
        self.assertGreaterEqual(len(result["consistency_issues"]), 6)
        restarted = Sync(self.cfg)
        self.addCleanup(restarted.db.close)
        self.assertEqual(len(restarted.report()["jobs"]), 1)

    def test_retry_backoff_partial_ack_and_recovery(self):
        previous = self.run_job()
        self.articles[1]["content"] = "retry 安装文章"
        self.write("articles.json", canonical(self.articles));self.commit("retry knowledge")
        bad = self.root / "broken";bad.write_text("blocking file")
        self.cfg["consumers"][1]["destination"] = str(bad)
        job = self.engine.release("cms", self.event(rid=2))
        self.engine.work(now=100)
        self.assertEqual(self.state(job)["status"], "retry_wait")
        self.assertEqual(self.state(job)["next_at"], 102)
        self.assertIsNone(self.engine.work(now=101))
        self.assertEqual(self.engine.db.execute("SELECT current_job FROM sources").fetchone()[0], previous)
        report = self.engine.report()
        self.assertEqual({c["job"] for c in report["consumer_cursors"]}, {job, previous})
        bad.unlink();self.engine.work(now=102)
        self.assertEqual(self.state(job)["status"], "succeeded")
        self.assertEqual(self.state(job)["attempts"], 2)

    def test_retry_limit_and_crash_reclaim(self):
        bad = self.root / "broken";bad.write_text("blocking file")
        self.cfg["consumers"][0]["destination"] = str(bad)
        job = self.engine.release("cms", self.event())
        self.engine.work(now=100);self.engine.work(now=102);self.engine.work(now=106)
        self.assertEqual(self.state(job)["status"], "failed")
        self.assertIsNone(self.engine.work(now=1000))
        bad.unlink();self.engine.retry(job);self.engine.work(now=1000)
        self.assertEqual(self.state(job)["status"], "succeeded")
        self.assertTrue(any(x['kind']=='manual_retry' for x in self.engine.report()['consistency_issues']))
        self.engine.db.execute("UPDATE jobs SET status='running',attempts=1 WHERE id=?", (job,));self.engine.db.commit()
        self.engine.work(now=1001)
        self.assertEqual(self.state(job)["status"], "succeeded")

    def test_no_worktree_promotion_lock_and_integrity(self):
        self.write("docs/guide.md", "dirty unreviewed security fix")
        job = self.run_job()
        self.assertEqual(self.state(job)["status"], "succeeded")
        self.assertNotIn("dirty", canonical(self.engine.load_snapshot(job)))
        with self.engine.locked():
            with self.assertRaises(BlockingIOError):self.engine.work()
        path = Path(self.state(job)["snapshot"])
        data = json.loads(path.read_text());data["snapshot"]["records"][0]["content"] = "tampered"
        path.write_text(canonical(data))
        with self.assertRaisesRegex(ValueError, "integrity"):self.engine.query("cms", "1.2.83", "安装")

    def test_version_change_review_gate_and_source_deletion(self):
        previous = self.run_job()
        self.write("config/app.example.php", "<?php return ['app'=>['version' => '1.2.84']];")
        self.write("docs/guide.md", "# MySQL 安装\nMySQL\n")
        self.write("system/migrations/fixture.php", "<?php return ['id' => 'isolated_migration'];")
        self.write("system/core/Fixture.php", "<?php namespace Fixture; final class Example {\n public function newApi(): void {}\n}")
        (self.repo / "docs/obsolete.md").rename(self.repo / "docs/renamed.md")
        source = self.commit("new version sources");self.g("tag", "v1.2.84")
        blocked = self.run_job(self.event("v1.2.84", rid=2))
        self.assertEqual(self.state(blocked)["status"], "needs_review")
        self.assertEqual(self.engine.db.execute("SELECT current_job FROM sources").fetchone()[0], previous)
        self.package(source, "1.2.84");self.commit("reviewed v2 knowledge")
        job = self.run_job(self.event("v1.2.84", rid=3))
        self.assertEqual(self.state(job)["status"], "succeeded")
        changed = json.loads(self.state(job)["counts"])["changed_sources"]
        self.assertIn("docs/obsolete.md", changed);self.assertIn("docs/renamed.md", changed)
        self.assertIn("system/migrations/fixture.php",changed)
        self.assertIn("system/core/Fixture.php",changed)
        with self.assertRaisesRegex(ValueError, "stale"):
            self.engine.release("cms", self.event(rid=4))

    def test_independent_component_source(self):
        self.write("plugin.json", canonical({"version": "2.0.0"}));source = self.commit("component")
        self.g("tag", "v2.0.0")
        item = dict(self.cfg["sources"][0], id="plugin", github_repository="fixture/plugin", version_file="plugin.json", initial_tag="v2.0.0")
        item.pop("package_path");item.pop("articles_path");item["include"] = ["plugin.json"]
        self.engine.sources["plugin"] = item
        event = self.event("v2.0.0");event["repository"]["full_name"] = "fixture/plugin"
        job = self.engine.release("plugin", event);self.engine.work()
        self.assertEqual(self.state(job)["status"], "succeeded")
        self.assertEqual(self.engine.load_snapshot(job)["records"], [])
        self.assertEqual(self.engine.load_snapshot(job)["commit"], source)

    def test_new_consumer_and_immutable_contract(self):
        previous = self.run_job()
        self.cfg['consumers'].append({'id': 'new-reader', 'kind': 'file', 'destination': str(self.root/'new-reader')})
        job = self.engine.release('cms', self.event(rid=2))
        self.assertNotEqual(job, previous)
        self.engine.sources['cms']['include'] = []
        self.cfg['consumers'][-1]['destination'] = str(self.root/'wrong-target')
        self.engine.work()
        self.assertIn('docs/guide.md', self.engine.load_snapshot(job)['inventory'])
        self.assertTrue((self.root/'new-reader/cms/current.json').exists())
        self.assertFalse((self.root/'wrong-target').exists())

    def test_bounded_source_check_retries_and_manual_recovery(self):
        self.engine.sources['cms']['knowledge_ref'] = 'missing-ref'
        for now in (100, 102, 106):self.engine.tick(now=now)
        failures = self.engine.report()['source_check_failures']
        self.assertTrue(failures)
        self.assertTrue(all(x['status']=='failed' and x['attempts']==3 for x in failures))
        self.engine.sources['cms']['knowledge_ref'] = 'main'
        self.engine.tick(now=200)
        self.assertEqual(self.engine.db.execute('SELECT count(*) FROM jobs').fetchone()[0], 0)
        self.engine.retry_checks();self.engine.tick(now=201);self.engine.work()
        self.assertFalse(self.engine.report()['source_check_failures'])
        self.assertEqual(self.engine.report()['jobs'][0]['status'], 'succeeded')

    def test_consumer_drift_detected_and_repaired(self):
        job = self.run_job()
        path = self.root/'consumer-a/cms'/f'{job}.json'
        path.write_text('{}')
        self.engine.tick(now=1000000)
        self.assertEqual(self.state(job)['status'], 'pending')
        with self.assertRaisesRegex(ValueError,'repair'):
            self.engine.query('cms','1.2.83','安装')
        self.engine.work()
        self.assertEqual(self.state(job)['status'], 'succeeded')
        self.assertTrue(self.engine.query('cms','1.2.83','安装')['grounded'])
        self.assertTrue(any(x['kind']=='consumer_drift' for x in self.engine.report()['consistency_issues']))

    def test_planned_public_fact_is_not_promoted(self):
        p=json.loads((self.repo/'knowledge.json').read_text())
        p['records'][0]['implementation_status']='planned'
        self.write('knowledge.json',canonical(p));self.commit('unverified planned claim')
        job=self.run_job()
        self.assertEqual(self.state(job)['status'],'needs_review')

    def test_release_inbox_survives_busy_worker_and_source_outage(self):
        with self.engine.locked():
            self.assertIsNone(self.engine.release('cms',self.event()))
        self.assertEqual(self.engine.report()['release_inbox'][0]['status'],'pending')
        self.engine.work()
        self.assertEqual(self.engine.report()['jobs'][0]['status'],'succeeded')
        actual=self.engine.sources['cms']['repository']
        self.engine.sources['cms']['repository']=str(self.root/'offline-source')
        self.assertIsNone(self.engine.release('cms',self.event(rid=2)))
        receipt=next(r for r in self.engine.report()['release_inbox'] if r['event']['release']['id']==2)
        self.assertEqual(receipt['status'],'retry_wait')
        self.engine.sources['cms']['repository']=actual
        self.engine.work(now=receipt['next_at'])
        self.assertTrue(all(r['status']=='accepted' for r in self.engine.report()['release_inbox']))

    def test_one_offline_source_does_not_recheck_healthy_source_and_change_hook(self):
        self.engine.sources['offline'] = dict(self.engine.sources['cms'], id='offline', repository=str(self.root/'offline'))
        self.engine.tick(now=100)
        self.engine.tick(now=101)
        self.assertEqual(self.engine.db.execute("SELECT count(*) FROM audits WHERE source='cms' AND kind='daily_consistency'").fetchone()[0],1)
        self.engine.work()
        self.articles[0]['status']='pending'
        self.write('articles.json',canonical(self.articles));self.commit('published article withdrawal')
        self.engine.tick(now=101,force=True);self.engine.work()
        self.assertNotIn('article.reviewed',{r['id'] for r in self.engine.query('cms','1.2.83','安装')['matches']})

    @unittest.skipUnless(PLUGIN, "set KNOWLEDGE_PLUGIN_ROOT to the locked 1.1.0 plugin for native integration")
    def test_native_plugin_repository_retriever_idempotence_and_rollback(self):
        database = self.root / "site.sqlite3"
        marker = {"environment": "isolated", "site_id": "test-site"}
        database.with_suffix('.sqlite3.isolated.json').write_text(canonical(marker))
        harness = str(HERE / 'tests/plugin_fixture.php')
        def php(action, *args):
            return json.loads(subprocess.check_output(['php', harness, action, str(database), PLUGIN, 'test-site', *args], text=True))
        php('init');php('foreign')
        self.cfg['consumers'][0] = {'id': 'customer', 'kind': 'customer_service_sqlite', 'database': str(database), 'plugin_root': PLUGIN, 'site_id': 'test-site'}
        job = self.run_job()
        self.assertEqual(self.state(job)['status'], 'succeeded')
        self.assertTrue(php('query', 'SQLite'))
        self.assertFalse(php('query', 'Oracle'))
        connection = sqlite3.connect(database);self.addCleanup(connection.close)
        before = connection.execute('SELECT COUNT(*) FROM ai_customer_service_knowledge_items').fetchone()[0]
        ack = self.engine.deliver(self.cfg['consumers'][0], job, self.engine.load_snapshot(job))
        self.assertEqual(ack['counts']['unchanged'], 3)
        self.assertEqual(connection.execute('SELECT COUNT(*) FROM ai_customer_service_knowledge_items').fetchone()[0], before)
        self.articles[0]['status'] = 'pending'
        self.write('articles.json', canonical(self.articles));self.commit('withdraw article')
        self.run_job(self.event(rid=2))
        self.assertEqual(connection.execute("SELECT COUNT(*) FROM ai_customer_service_knowledge_items WHERE site_id='test-site' AND enabled=1").fetchone()[0], 2)
        connection.execute("UPDATE ai_customer_service_knowledge_items SET content='tampered' WHERE site_id='test-site' AND enabled=1")
        connection.commit()
        self.engine.tick(now=1000000);self.engine.work()
        self.assertEqual(connection.execute("SELECT COUNT(*) FROM ai_customer_service_knowledge_items WHERE content='tampered'").fetchone()[0],0)
        snapshot = self.engine.load_snapshot(job)
        snapshot['records'][0]['title'] = 'must roll back'
        snapshot['records'][1]['visibility'] = 'INTERNAL'
        proc = subprocess.run(['php', str(HERE/'customer_service.php'), str(database), PLUGIN, 'test-site'], input=canonical(snapshot), text=True, capture_output=True)
        self.assertNotEqual(proc.returncode, 0)
        self.assertEqual(connection.execute("SELECT COUNT(*) FROM ai_customer_service_knowledge_items WHERE title='must roll back'").fetchone()[0], 0)


    def native_customer(self):
        database = self.root / 'native.sqlite3'
        Path(str(database)+'.isolated.json').write_text(canonical({'environment':'isolated','site_id':'test-site'}))
        def php(action, *args):
            return json.loads(subprocess.check_output(['php',str(HERE/'tests/plugin_fixture.php'),action,str(database),PLUGIN,'test-site',*args],text=True))
        php('init')
        self.cfg['consumers'][0] = {'id':'customer','kind':'customer_service_sqlite','database':str(database),'plugin_root':PLUGIN,'site_id':'test-site'}
        return database, php

    @unittest.skipUnless(PLUGIN, 'locked candidate plugin required')
    def test_managed_receipt_priority_crowding_and_runtime_version(self):
        database, php = self.native_customer()
        self.run_job(); php('noise')
        matched = php('query','SQLite 安装')
        self.assertTrue(matched)
        self.assertEqual({m['priority'] for m in matched},{'P0'})
        self.assertEqual({m['source_type'] for m in matched},{'knowledge_sync'})
        receipt = php('receipt')
        self.assertTrue(receipt['mode'])
        self.assertEqual(matched[0]['receipt_sha256'],receipt['receipt']['snapshot_sha256'])
        self.assertEqual(matched[0]['knowledge_revision'],self.knowledge)
        self.assertFalse(php('query-version','SQLite','9.9.9'))
        with sqlite3.connect(database) as db:
            db.execute("UPDATE ai_customer_service_knowledge_items SET content='tampered' WHERE source_type='knowledge_sync'")
        self.assertFalse(php('query','SQLite'))

    @unittest.skipUnless(PLUGIN, 'locked candidate plugin required')
    def test_native_failed_import_keeps_receipt_and_answer_then_recovers(self):
        database, php = self.native_customer()
        old = self.run_job(); before = php('receipt')
        self.articles[0]['content'] = 'nebula approved revision 47'
        self.write('articles.json',canonical(self.articles)); self.commit('reviewed update')
        with sqlite3.connect(database) as db:
            db.execute("CREATE TRIGGER fail_import BEFORE UPDATE ON ai_customer_service_knowledge_items BEGIN SELECT RAISE(ABORT,'isolated write fault'); END")
        job = self.run_job(self.event(rid=91))
        self.assertEqual(self.state(job)['status'],'retry_wait')
        self.assertEqual(php('receipt'),before)
        self.assertTrue(php('query','SQLite')); self.assertFalse(php('query','nebula'))
        self.assertEqual(self.engine.db.execute('SELECT current_job FROM sources').fetchone()[0],old)
        with sqlite3.connect(database) as db:
            db.execute('DROP TRIGGER fail_import')
        self.engine.work(now=time.time()+20)
        self.assertEqual(self.state(job)['status'],'succeeded')
        self.assertTrue(php('query','nebula'))
        self.assertNotEqual(php('receipt')['receipt']['knowledge_commit'],before['receipt']['knowledge_commit'])

    @unittest.skipUnless(PLUGIN, 'locked candidate plugin required')
    def test_other_consumer_failure_cannot_activate_customer_batch(self):
        database, php = self.native_customer()
        old = self.run_job(); before = php('receipt')
        blocked = self.root/'blocked'; blocked.write_text('not a directory')
        self.cfg['consumers'][1]['destination'] = str(blocked)
        job = self.run_job(self.event(rid=92))
        self.assertEqual(self.state(job)['status'],'retry_wait')
        self.assertEqual(php('receipt'),before)
        self.assertEqual(self.engine.db.execute('SELECT current_job FROM sources').fetchone()[0],old)

    @unittest.skipUnless(PLUGIN, 'locked candidate plugin required')
    def test_capacity_and_visibility_rejection_preserve_active_batch(self):
        database, php = self.native_customer()
        job = self.run_job(); before = php('receipt')
        snapshot = self.engine.projection(self.engine.load_snapshot(job))
        for bad in ['capacity','visibility']:
            mutated = copy.deepcopy(snapshot)
            if bad == 'capacity': mutated['records'] = [mutated['records'][0]] * 257
            else: mutated['records'][0]['visibility'] = 'INTERNAL'
            proc = subprocess.run(['php',str(HERE/'customer_service.php'),str(database),PLUGIN,'test-site'],input=canonical(mutated),capture_output=True,text=True)
            self.assertNotEqual(proc.returncode,0)
            self.assertEqual(php('receipt'),before)
            self.assertTrue(php('query','SQLite'))


if __name__ == '__main__':
    unittest.main(verbosity=2)
