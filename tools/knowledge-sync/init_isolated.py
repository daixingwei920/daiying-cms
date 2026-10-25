#!/usr/bin/env python3
"""Create a NEW, explicitly marked sandbox. Never attach an existing site DB."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess

from sync import HERE, Sync, blob, canonical, require, resolve


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", required=True)
    parser.add_argument("--repository", required=True)
    parser.add_argument("--knowledge-ref", default="origin/main")
    parser.add_argument("--plugin-root", help="optional locked 1.1.0 plugin, read-only")
    args = parser.parse_args()
    root, repo = Path(args.root).resolve(), Path(args.repository).resolve()
    require(not root.exists(), "isolation root must be NEW; existing sites are never initialized")
    commit = resolve(repo, args.knowledge_ref)
    package = json.loads(blob(repo, commit, "DAIYING_CMS_AI_PRODUCT_KNOWLEDGE_V1.json"))
    consumer = {"id": "customer-service", "kind": "file", "destination": str(root / "customer-service")}
    if args.plugin_root:
        plugin = Path(args.plugin_root).resolve()
        lock = json.loads((HERE / "reuse-lock.json").read_text())
        for path, expected in lock["sha256"].items():
            require(hashlib.sha256((plugin / path).read_bytes()).hexdigest() == expected, "plugin baseline mismatch")
        consumer = {"id": "customer-service", "kind": "customer_service_sqlite", "database": str(root / "site.sqlite3"),
                    "site_id": "knowledge-isolated-site", "plugin_root": str(plugin), "core_version": package["verified_version"]}
    root.mkdir(parents=True)
    (root / ".daiying-knowledge-isolated").write_text("ISOLATED\n")
    if args.plugin_root:
        Path(consumer["database"] + ".isolated.json").write_text(canonical({"environment": "isolated", "site_id": consumer["site_id"]}))
        subprocess.run(["php", str(HERE / "tests/plugin_fixture.php"), "init", consumer["database"], consumer["plugin_root"], consumer["site_id"]],
                       check=True, stdout=subprocess.DEVNULL)
    config = {"environment": "isolated", "isolation_root": str(root), "state_dir": str(root / "state"),
              "daily_seconds": 86400, "weekly_seconds": 604800, "max_attempts": 3, "retry_delay_seconds": 30,
              "sources": [{"id": "cms", "repository": str(repo), "github_repository": "daixingwei920/daiying-cms",
                           "initial_tag": package["source_tag"], "version_file": "config/app.example.php",
                           "knowledge_ref": args.knowledge_ref, "watch_ref": args.knowledge_ref,
                           "package_path": "DAIYING_CMS_AI_PRODUCT_KNOWLEDGE_V1.json",
                           "include": ["system/core", "system/migrations", "docs", "content/plugins", "content/themes",
                                       "CHANGELOG.md", "PLUGIN_API_V1.md", "THEME_API_V1.md", "REST_API_V1.md"]}],
              "consumers": [consumer, {"id": "article-optimizer-context", "kind": "file", "destination": str(root / "optimizer-context")}]}
    (root / "config.json").write_text(json.dumps(config, indent=2) + "\n")
    sync = Sync(config)
    sync.report()
    print(root / "config.json")


if __name__ == "__main__":
    main()
