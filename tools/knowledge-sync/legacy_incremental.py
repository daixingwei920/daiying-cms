"""Reused Phase 7.1 classification and PHP symbol extraction.

Original tool: Daiying Git incremental corpus updater, 2026-09-14.
Extracted helpers only; the new runner reads immutable Git blobs, never raw
working-tree files, and never promotes these symbols into public facts.
"""
#!/usr/bin/env python3
import json
import re
import subprocess
from datetime import datetime, timezone
from pathlib import Path

TEXT_EXTS = {".md", ".txt", ".json", ".html", ".htm"}
SOURCE_EXTS = {".php"}
IGNORED_PARTS = {".git", "vendor", "node_modules", "storage", "cache", "tmp", "uploads"}

CLASS_RE = re.compile(r"^\s*(?:(?:abstract|final|readonly)\s+)*(class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)", re.M)
FUNCTION_RE = re.compile(
    r"^\s*(?:(public|protected|private)\s+)?(?:(static)\s+)?function\s+(&?\s*[A-Za-z_][A-Za-z0-9_]*)\s*\(([^)]*)\)\s*([^;{]*)",
    re.M,
)
NAMESPACE_RE = re.compile(r"^\s*namespace\s+([^;{]+)\s*;", re.M)


def run(cmd, cwd=None):
    return subprocess.check_output(cmd, cwd=cwd, text=True, stderr=subprocess.DEVNULL).strip()


def now_iso():
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def line_no(text, pos):
    return text.count("\n", 0, pos) + 1


def rel(path, root):
    return path.relative_to(root).as_posix()


def front_matter(meta):
    lines = ["---"]
    for key, value in meta.items():
        if isinstance(value, list):
            lines.append(f"{key}: {json.dumps(value, ensure_ascii=False)}")
        elif value is None:
            lines.append(f"{key}: null")
        else:
            lines.append(f"{key}: {json.dumps(value, ensure_ascii=False)}")
    lines.append("---")
    return "\n".join(lines) + "\n\n"


def category_for(path):
    p = path.lower()
    cats = set()
    if "system/core" in p or p in {"public/index.php", "cli.php", "config/app.php"}:
        cats.add("Core architecture")
    if "publicapiregistry" in p or "interface.php" in p or "system/core/plugin/plugincontext.php" in p or "migrationinterface" in p:
        cats.add("Core public APIs")
    if "plugin" in p or "content/plugins" in p:
        cats.add("Plugin development")
    if "theme" in p or "content/themes" in p:
        cats.add("Theme development")
    if "commerce" in p or "order" in p or "product" in p:
        cats.add("Commerce")
    if "payment" in p or "stripe" in p or "paypal" in p:
        cats.add("Payment")
    if "ai" in p or "provider" in p:
        cats.add("AI Provider")
    if "market" in p or "license" in p or "licensing" in p:
        cats.add("Marketplace / licensing")
    if "update" in p or "release" in p or "version" in p:
        cats.add("Update Server")
    if "seo" in p or "slug" in p or "sitemap" in p:
        cats.add("SEO")
    if "decision" in p or "history" in p or "changelog" in p:
        cats.add("Historical decisions")
    if "deprecated" in p or "legacy" in p or "obsolete" in p:
        cats.add("Deprecated designs")
    if "wrong" in p or "bug" in p or "fix" in p or "security" in p or "regression" in p:
        cats.add("Known wrong implementations")
    return sorted(cats or {"Historical decisions"})


def doc_type_for(categories, suffix):
    if "Core public APIs" in categories:
        return "core_public_api"
    if "Plugin development" in categories:
        return "plugin_development"
    if "Theme development" in categories:
        return "theme_development"
    if "Payment" in categories:
        return "payment"
    if "Marketplace / licensing" in categories:
        return "marketplace_licensing"
    if "Update Server" in categories:
        return "update_server"
    if "Known wrong implementations" in categories:
        return "known_wrong_implementation"
    if "Deprecated designs" in categories:
        return "deprecated_design"
    if suffix == ".php":
        return "source_symbol_index"
    return "technical_document"


def status_for(git_status, path):
    # A commit or a path named security/fix is not test or review evidence.
    return "experimental"


def plugin_id_for(path):
    parts = path.split("/")
    if len(parts) >= 3 and parts[0] == "content" and parts[1] == "plugins":
        return parts[2]
    return None


def theme_id_for(path):
    parts = path.split("/")
    if len(parts) >= 3 and parts[0] == "content" and parts[1] == "themes":
        return parts[2]
    return None


def extract_symbols(repo, path, version, git_status):
    full = repo / path
    if not full.exists() or full.suffix.lower() != ".php":
        return []
    text = full.read_text(encoding="utf-8", errors="replace")
    ns_match = NAMESPACE_RE.search(text)
    namespace = ns_match.group(1).strip() if ns_match else ""
    classes = []
    for m in CLASS_RE.finditer(text):
        name = m.group(2)
        fqcn = f"{namespace}\\{name}" if namespace else name
        classes.append({"kind": m.group(1), "name": name, "fqcn": fqcn, "line": line_no(text, m.start())})
    class_lines = sorted((c["line"], c) for c in classes)
    symbols = []
    for c in classes:
        symbols.append({**c, "symbol": c["fqcn"], "file_path": path, "signature": f"{c['kind']} {c['fqcn']}", "version": version, "git_status": git_status})
    for m in FUNCTION_RE.finditer(text):
        fn_line = line_no(text, m.start())
        name = re.sub(r"\s+", "", m.group(3))
        args = re.sub(r"\s+", " ", m.group(4).strip())
        ret = re.sub(r"\s+", " ", m.group(5).strip())
        owner = None
        for c_line, c in class_lines:
            if c_line <= fn_line:
                owner = c
            else:
                break
        visibility = m.group(1) or ("public" if owner else "")
        static = " static" if m.group(2) else ""
        signature = f"{visibility}{static} function {name}({args}) {ret}".strip()
        symbols.append(
            {
                "kind": "method" if owner else "function",
                "name": name,
                "symbol": f"{owner['fqcn']}::{name}" if owner else (f"{namespace}\\{name}" if namespace else name),
                "file_path": path,
                "line": fn_line,
                "signature": signature,
                "version": version,
                "git_status": git_status,
            }
        )
    return symbols
