"""Native admin/AI/SEO acceptance in a disposable site; AI is loopback mock only."""
import argparse
import hashlib
import html
import http.cookiejar
import json
from pathlib import Path
import re
import socket
import sqlite3
import subprocess
import tempfile
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import urllib.error
import urllib.parse
import urllib.request

SOURCE = Path(__file__).resolve().parents[1]
TEXT = "隔离 AI 写作正文。\n\n这是供管理员审核并保存的第二段。"
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("--commit", default="HEAD")
parser.add_argument("--output", type=Path)
args = parser.parse_args()
commit = subprocess.check_output(["git", "rev-parse", args.commit + "^{commit}"], cwd=SOURCE, text=True).strip()
checks, calls = [], []


def check(ok, label):
    if not ok:
        raise AssertionError(label)
    checks.append(label)
    print("PASS " + label, flush=True)


class MockAI(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def do_POST(self):
        if self.path != "/v1/chat/completions":
            self.send_error(404)
            return
        calls.append(json.loads(self.rfile.read(int(self.headers["Content-Length"]))))
        body = json.dumps({"id": "isolated-mock", "choices": [{"message": {"content": TEXT}}],
                           "usage": {"total_tokens": 5}}, ensure_ascii=False).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


with tempfile.TemporaryDirectory(prefix="cms-retirement-http-") as temporary:
    site = Path(temporary)
    with tempfile.TemporaryFile() as archive:
        subprocess.run(["git", "archive", commit], cwd=SOURCE, stdout=archive, check=True)
        archive.seek(0)
        subprocess.run(["tar", "-xf", "-", "-C", str(site)], stdin=archive, check=True)
    (site / "sessions").mkdir(mode=0o700)
    mock = ThreadingHTTPServer(("127.0.0.1", 0), MockAI)
    thread = threading.Thread(target=mock.serve_forever, daemon=True)
    thread.start()
    server = None
    try:
        initialize = r'''
$root=$argv[1]; require $root.'/system/core/Bootstrap/autoload.php';
$config=require $root.'/config/app.example.php';
$config['app']['secure_cookies']=false; $config['site']['url']='http://127.0.0.1';
$config['database']=['dsn'=>'sqlite:'.$root.'/storage/fixture.sqlite'];
$config['security']['encryption_key']=bin2hex(random_bytes(32));
$config['market']['enabled']=false; $config['seo']['robots_index']=true;
file_put_contents($root.'/config/app.php', "<?php\nreturn ".var_export($config,true).";\n");
chmod($root.'/config/app.php',0600);
$settings=Cms\Core\Config\Settings::fromArray($config);
$pdo=Cms\Core\Database\ConnectionFactory::make($settings);
$files=glob($root.'/system/migrations/*.php'); sort($files,SORT_STRING);
(new Cms\Core\Migration\MigrationRunner($pdo,array_map(static fn($f)=>require $f,$files)))->run();
(new Cms\Core\Auth\AdminAuthenticator($pdo))->createAdmin('review@example.invalid','Isolated-review-only!','Review');
$contents=new Cms\Core\Content\ContentRepository($pdo,Cms\Core\Content\ContentTypeRegistry::defaults(),[],$root);
$id=$contents->create('article','Legacy article','legacy-article',[
    ['type'=>'paragraph','data'=>['text'=>'Original owner body']]],'published',[
    'seo_title'=>'Owner SEO title','seo_description'=>'Owner description','seo_keywords'=>'owner,manual',
    'target_keywords'=>'Owner keyword','canonical_url'=>'http://127.0.0.1/articles/legacy-article',
    'robots_index'=>true,'robots_follow'=>true]);
$pdo->prepare("INSERT INTO cms_historical_article_seo_jobs(content_id,status,proposed_meta_json,proposed_blocks_json,created_at,updated_at) VALUES (?,'generated',?,?,'historical','historical')")
    ->execute([$id,'{"seo_title":"Pending old proposal"}','[{"type":"paragraph","data":{"text":"Pending old draft"}}]']);
$pdo->prepare("INSERT INTO cms_historical_article_seo_logs(content_id,action,status,message,created_at) VALUES (?,'generate_ai','ok','Historical log','historical')")->execute([$id]);
(new Cms\Core\Ai\SiteAiSettingsRepository($pdo,$config['security']['encryption_key']))->save([
    'enabled'=>true,'provider'=>'local_model','adapter'=>'openai_compatible','base_url'=>$argv[2],
    'model'=>'llama3.1','timeout_seconds'=>5,'max_tokens'=>512,'temperature'=>0.2,
    'allow_cloud_fallback'=>false], '', true);
file_put_contents($root.'/storage/installed.lock','ISOLATED REVIEW ONLY');
echo json_encode(['id'=>$id,'integrity'=>(new Cms\Core\Recovery\IntegrityChecker())->check($root)]);
'''
        fixture = json.loads(subprocess.check_output(["php", "-r", initialize, str(site),
                            f"http://127.0.0.1:{mock.server_port}"], text=True))
        check(fixture["integrity"] == {"status": "ok", "missing": [], "changed": [], "unknown": []},
              "exact archived Core snapshot has valid integrity")
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        base = f"http://127.0.0.1:{port}"
        jar = http.cookiejar.CookieJar()
        opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

        def request(path, fields=None):
            data = urllib.parse.urlencode(fields).encode() if fields is not None else None
            try:
                with opener.open(urllib.request.Request(base + path, data=data), timeout=15) as response:
                    return response.status, response.read().decode()
            except urllib.error.HTTPError as error:
                return error.code, error.read().decode()

        def csrf(body):
            return html.unescape(re.search(r'name="_csrf" value="([^"]+)"', body)[1])

        def rows(table, where="", parameters=()):
            with sqlite3.connect(f"file:{site}/storage/fixture.sqlite?mode=ro", uri=True) as db:
                db.row_factory = sqlite3.Row
                return [dict(row) for row in db.execute("SELECT * FROM " + table + where + " ORDER BY 1", parameters)]

        def history():
            return {table: rows(table) for table in ["cms_historical_article_seo_jobs",
                    "cms_historical_article_seo_logs", "cms_core_migrations"]}

        def form(body, title, slug, text, meta, status="draft"):
            return {"_csrf": csrf(body), "title": title, "slug": slug, "content_type": "article",
                    "content_action": "save", "status": status, "blocks[0][type]": "paragraph",
                    "blocks[0][data][text]": text, "robots_index": "1", "robots_follow": "1", **meta}

        with (site / "http.log").open("w") as log:
            server = subprocess.Popen(["php", "-d", "session.save_path=" + str(site / "sessions"),
                "-S", f"127.0.0.1:{port}", "-t", str(site / "public"), str(site / "public/index.php")],
                stdout=log, stderr=log)
            for _ in range(50):
                try:
                    with socket.create_connection(("127.0.0.1", port), timeout=.1):
                        break
                except OSError:
                    time.sleep(.1)
            before, article = history(), rows("cms_contents")[0]
            status, _ = request("/admin/content/ai-write", {"title": "Unauthorized"})
            check(status == 401 and not calls, "AI writing still requires native admin login")
            _, body = request("/admin/login")
            request("/admin/login", {"_csrf": csrf(body), "email": "review@example.invalid", "password": "Isolated-review-only!"})
            status, body = request("/admin/content/new")
            check(status == 200 and "data-content-ai-write" in body, "native article editor keeps its AI writing button")
            token = csrf(body)
            status, _ = request("/admin/content/ai-write", {"_csrf": "wrong", "title": "No CSRF"})
            check(status == 403 and not calls, "AI writing CSRF refusal makes no provider request")
            status, _ = request("/admin/content/ai-write", {"_csrf": token})
            check(status == 422 and not calls, "empty AI input is rejected before dispatch")
            for kind in ["article", "page"]:
                status, generated = request("/admin/content/ai-write", {"_csrf": token,
                    "title": "Review " + kind, "content_type": kind, "brief": "Isolated review"})
                check(status == 200 and json.loads(generated) == {"ok": True, "content": TEXT},
                      kind + " AI writing returns editable text through the real HTTP handler")
            check(len(calls) == 2 and all(call["model"] == "llama3.1" for call in calls),
                  "AI writer uses only the configured loopback mock provider")
            check(rows("cms_contents") == [article] and history() == before,
                  "AI generation alone neither saves/publishes nor changes historical data")
            for path in ["/admin/seo/historical-backfill", "/admin/seo/content-quality"]:
                status, retired = request(path)
                check(status == 410 and "已退役" in retired, "seeded old GET is retired: " + path)
            for action in ["scan", "generate_batch", "generate_ai_batch", "generate", "generate_ai", "apply"]:
                status, _ = request("/admin/seo/historical-backfill/action", {"_csrf": token,
                    "action": action, "content_id": fixture["id"], "regenerate": "1"})
                check(status == 410, "seeded legacy action is blocked: " + action)
            check(rows("cms_contents") == [article] and history() == before and len(calls) == 2,
                  "old actions retain article/SEO/pending drafts/logs/migrations and dispatch no AI")
            new_meta = {"seo_title": "Draft SEO", "seo_description": "Draft description", "seo_keywords": "draft, manual",
                        "target_keywords": "Draft keyword", "canonical_url": "http://127.0.0.1/articles/ai-draft"}
            status, _ = request("/admin/content", form(body, "AI draft", "ai-draft", TEXT, new_meta))
            draft = rows("cms_contents", " WHERE slug=?", ("ai-draft",))
            check(status == 200 and len(draft) == 1 and draft[0]["status"] == "draft", "AI text saves as draft only after native editor submission")
            if json.loads(draft[0]["blocks_json"])[0]["data"]["text"] != TEXT or any(
                    json.loads(draft[0]["meta_json"])[key] != value for key, value in new_meta.items()):
                raise AssertionError({"blocks": json.loads(draft[0]["blocks_json"]),
                    "changed_meta": {key: json.loads(draft[0]["meta_json"])[key]
                                     for key, value in new_meta.items() if json.loads(draft[0]["meta_json"])[key] != value}})
            check(json.loads(draft[0]["blocks_json"])[0]["data"]["text"] == TEXT and
                  all(json.loads(draft[0]["meta_json"])[key] == value for key, value in new_meta.items()),
                  "native draft save persists AI text and manual SEO exactly")
            status, body = request("/admin/content/edit/" + str(fixture["id"]))
            check(status == 200 and "Original owner body" in body, "ordinary edit loads the pre-existing article")
            owner_meta = {key: json.loads(article["meta_json"])[key] for key in new_meta}
            status, _ = request("/admin/content/edit/" + str(fixture["id"]),
                form(body, "Manually edited", "legacy-article", "Manually edited body", owner_meta, "published"))
            saved = rows("cms_contents", " WHERE id=?", (fixture["id"],))[0]
            check(status == 200 and saved["slug"] == article["slug"] and saved["status"] == "published" and
                  json.loads(saved["blocks_json"])[0]["data"]["text"] == "Manually edited body",
                  "ordinary native editing changes only the submitted article and keeps URL/status")
            check(all(json.loads(saved["meta_json"])[key] == value for key, value in owner_meta.items()) and history() == before,
                  "ordinary editing preserves manual SEO and all historical optimizer records")
            saved_before = saved.copy()
            status, _ = request("/admin/content/edit/" + str(fixture["id"]), {"_csrf": "wrong", "title": "Forged"})
            check(status == 400 and rows("cms_contents", " WHERE id=?", (fixture["id"],))[0] == saved_before,
                  "ordinary editor CSRF refusal preserves saved content")
            status, body = request("/admin/seo/keywords")
            check(status == 200 and "historical-backfill" not in body and "content-quality" not in body,
                  "authenticated sidebar omits retired tools and keyword center still renders")
            status, _ = request("/admin/seo/keywords/save", {"_csrf": csrf(body), "keyword": "Review keyword",
                "primary_url": "/articles/legacy-article", "status": "active", "source": "manual", "notes": "Isolated"})
            check(status == 200 and rows("cms_seo_keywords", " WHERE keyword=?", ("Review keyword",))[0]["primary_url"] == "/articles/legacy-article",
                  "keyword center still saves manual landing-page assignments")
            for path in ["/admin/seo/crawlers", "/admin/seo/search-engines"]:
                status, _ = request(path)
                check(status == 200, "other native SEO page remains available: " + path)
            status, public = request("/articles/legacy-article")
            check(status == 200 and "Owner SEO title" in public and "Owner description" in public and
                  'href="http://127.0.0.1/articles/legacy-article"' in public, "public page retains manual SEO title/description/canonical output")
            status, sitemap = request("/sitemap.xml")
            check(status == 200 and "/articles/legacy-article" in sitemap and "/articles/ai-draft" not in sitemap,
                  "sitemap retains published article and excludes AI draft")
            check(history() == before and len(calls) == 2, "all shared workflows retain old optimizer data and make no extra AI calls")
    finally:
        if server is not None:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait()
        mock.shutdown()
        mock.server_close()
        thread.join(timeout=5)
    paths = ["system/core/Admin/AdminController.php", "system/core/Bootstrap/Application.php",
             "system/core/Support/View.php", "system/core-manifest.json"]
    result = {"status": "PASS", "source_commit": commit, "checks": checks, "assertion_count": len(checks),
              "loopback_mock_calls": len(calls), "external_model_calls": 0, "production_modified": False,
              "historical_records_retained": True, "runtime_sha256": {
                  path: hashlib.sha256((site / path).read_bytes()).hexdigest() for path in paths}}
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n")
    print("Historical optimizer native review:", len(checks), "PASS")
