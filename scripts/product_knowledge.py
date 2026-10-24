#!/usr/bin/env python3
"""Check pinned V1 facts; optionally refresh Markdown and the public projection.

Offline documentation tooling only. Does not import knowledge, call a model,
change an index, configure a scheduler or publish a release.
"""
import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
import subprocess


ROOT = Path(__file__).resolve().parents[1]
NAME = "DAIYING_CMS_AI_PRODUCT_KNOWLEDGE_V1"


def encode(value):
    return json.dumps(value, ensure_ascii=False, indent=2) + "\n"


def public_records(package):
    return [r for r in package["records"] if r["enabled_for_customer_service"]]


def validate(package, root=ROOT):
    def require(condition, message):
        if not condition:
            raise ValueError(message)

    require(package["knowledge_version"] == NAME, "V1 identity changed")
    commit = package["source_commit"]
    require(re.fullmatch(r"[0-9a-f]{40}", commit) is not None, "full commit required")
    require(package["verification_scope"] == "SOURCE_AND_DOCUMENT_REVIEW_ONLY", "review scope changed")
    blobs = {}

    def blob(path):
        parts = PurePosixPath(path)
        require(not parts.is_absolute() and ".." not in parts.parts, "unsafe evidence path")
        require(parts.parts and parts.parts[0] in {"docs", "system", "scripts", "config", "SECURITY.md", "CHANGELOG.md"}, "unapproved evidence root")
        require(not any(p in {".git", "storage", "uploads", "secrets"} for p in parts.parts), "runtime evidence forbidden")
        require(path != "config/app.php", "private config forbidden")
        if path not in blobs:
            blobs[path] = subprocess.check_output(["git", "show", f"{commit}:{path}"], cwd=root)
            require((root / path).read_bytes() == blobs[path], f"source drift: {path}")
        return blobs[path]

    version = package["verified_version"]
    require(f"'version' => '{version}'" in blob("config/app.example.php").decode(), "version differs from source")
    tagged = subprocess.check_output(["git", "rev-parse", f"{package['source_tag']}^{{commit}}"], cwd=root, text=True).strip()
    require(tagged == commit, "tag/commit mismatch")
    seen = set()
    for record in package["records"]:
        rid = record["id"]
        require(rid not in seen, f"duplicate id: {rid}")
        seen.add(rid)
        require(record["verified_version"] == version and record["source_commit"] == commit, f"record version: {rid}")
        require(record["test_status"] == "NOT_RUN_THIS_TASK", f"source review cannot claim runtime PASS: {rid}")
        require(record["review_status"] == "SOURCE_REVIEWED", f"review status: {rid}")
        require(record["last_verified_at"] == package["last_verified_at"], f"review date: {rid}")
        require(record["priority"] == "P0", f"official package tier: {rid}")
        require(record["visibility"] in {"USER-FACING", "INTERNAL", "ADMIN_DEVELOPER"}, f"visibility: {rid}")
        public = record["visibility"] == "USER-FACING" and record["user_facing"] is True
        require(record["enabled_for_customer_service"] is public, f"public eligibility: {rid}")
        if record["audience"] in {"Internal", "Developer"}:
            require(not public, f"internal/developer leak: {rid}")
        require(bool(record["description"].strip()) and bool(record["source_evidence"]), f"empty fact/evidence: {rid}")
        for evidence in record["source_evidence"]:
            raw = blob(evidence["path"])
            require(hashlib.sha256(raw).hexdigest() == evidence["sha256"], f"source hash: {rid}")
            start, end = evidence["line_start"], evidence["line_end"]
            lines = raw.decode().splitlines()
            require(1 <= start <= end <= len(lines), f"source lines: {rid}")
            require(evidence["anchor"] in "\n".join(lines[start - 1:end]), f"source anchor: {rid}")
    require(package["integration_status"]["automatic_sync"] == "DESIGN_ONLY", "sync completion lacks validation")
    require(package["integration_status"]["core_optimizer_shared_knowledge"] == "NOT_IMPLEMENTED", "optimizer integration lacks validation")
    return len(blobs)


def derived(package):
    projected = []
    for record in public_records(package):
        item = dict(record)
        # Keep provenance without placing PHP implementation excerpts in the export.
        item["source_evidence"] = [{k: v for k, v in evidence.items() if k != "anchor"}
                                   for evidence in record["source_evidence"]]
        projected.append(item)
    public = dict(package, export_scope="USER-FACING_ONLY", records=projected)
    # The consumer projection omits internal inventory and workstation recovery details.
    public.pop("original_qa", None)
    public.pop("known_document_conflicts", None)
    lines = [
        "# Daiying CMS AI Product Knowledge V1", "",
        f"知识包：`{NAME}`；修订：`{package['package_revision']}`。", "",
        f"源码基线：Core **{package['verified_version']}**，`{package['source_tag']}`，`{package['source_commit']}`。", "",
        f"复核时间：{package['last_verified_at']}。本轮仅作源码和文档复核，所有记录的运行测试均为 NOT_RUN_THIS_TASK。", "",
        "JSON 为事实源；本文件与 USER-FACING 导出由 `python3 scripts/product_knowledge.py --write` 生成。", "",
        "客服仅使用 USER-FACING 导出；完整包包含 INTERNAL/ADMIN_DEVELOPER，禁止直接送入普通客服上下文。现有旧插件不能被假定已执行这些过滤。", "",
        "要求 P0 官方知识 → P1 已审核已发布文章 → P2 普通已发布文章；Draft/Pending/C/D、内部资料及分类未知内容默认禁用。参考知识与待优化原文分开。", "",
        "自动同步仍为 DESIGN_ONLY；原 Core 优化器共享知识接入为 NOT_IMPLEMENTED。源码提交不代表已测试、已部署或生产验收通过。", "",
    ]
    if package.get("candidate_evidence", {}).get("status") == "UNPUBLISHED":
        lines.extend(["**当前分支知识源是未发布候选；不代表生产可用。** 正式发布事实保留在 release_evidence；候选运行证据见 docs/issue9-delivery.md。禁止自动导入生产客服或索引。", ""])
    for record in package["records"]:
        lines.extend([
            f"## {record['capability']}", "",
            f"`{record['id']}` · {record['visibility']} · P0 · {record['implementation_status']} · 测试 NOT_RUN_THIS_TASK", "",
            record["description"], "",
        ])
        for evidence in record["source_evidence"]:
            path = evidence["path"]
            url = f"{package['source_repository']}/blob/{package['source_commit']}/{path}#L{evidence['line_start']}"
            lines.append(f"- 来源：[{path}:{evidence['line_start']}]({url})")
        lines.append("")
    return {
        NAME + ".md": "\n".join(lines).rstrip() + "\n",
        NAME + ".user-facing.json": encode(public),
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--write", action="store_true", help="refresh the two derived files after validation")
    args = parser.parse_args()
    package = json.loads((ROOT / (NAME + ".json")).read_text())
    sources = validate(package)
    for path, content in derived(package).items():
        target = ROOT / path
        if args.write:
            target.write_text(content)
        elif not target.exists() or target.read_text() != content:
            raise ValueError(f"derived file out of sync: {path}; run with --write")
    print(f"PASS: {len(package['records'])} facts, {len(public_records(package))} public facts, {sources} pinned source files; runtime NOT RUN")


if __name__ == "__main__":
    try:
        main()
    except (KeyError, ValueError, OSError, subprocess.CalledProcessError) as error:
        raise SystemExit(f"FAIL: {error}")
