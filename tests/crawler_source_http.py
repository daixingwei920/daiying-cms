"""Native HTTP/CLI crawler verification acceptance. Synthetic addresses/feeds; loopback only."""
import argparse
import http.cookiejar
import html
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--output', type=Path)
args = parser.parse_args()
source = Path(__file__).resolve().parents[1]
checks = []


def check(ok, label):
    if not ok:
        raise AssertionError(label)
    checks.append(label)
    print('PASS ' + label, flush=True)


with tempfile.TemporaryDirectory(prefix='cms-crawler-http-') as tmp:
    site = Path(tmp)
    files = subprocess.check_output(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], cwd=source).decode().split('\0')
    for name in filter(None, files):
        origin = source / name
        if origin.is_file():
            target = site / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(origin, target)
    (site / 'sessions').mkdir(mode=0o700)
    initialize = r'''
define('CMS_ROOT',$argv[1]); require CMS_ROOT.'/system/core/Bootstrap/autoload.php';
$c=require CMS_ROOT.'/config/app.example.php';
$c['app']['secure_cookies']=false; $c['site']['url']='http://127.0.0.1';
$c['database']=['dsn'=>'sqlite:'.CMS_ROOT.'/storage/fixture.sqlite'];
$c['security']['encryption_key']='synthetic-http-fixture-only'; $c['seo']['crawler_log_enabled']=true;
file_put_contents(CMS_ROOT.'/config/app.php','<?php return '.var_export($c,true).';');
$s=Cms\Core\Config\Settings::fromArray($c); $p=Cms\Core\Database\ConnectionFactory::make($s);
$files=glob(CMS_ROOT.'/system/migrations/*.php');sort($files,SORT_STRING);
(new Cms\Core\Migration\MigrationRunner($p,array_map(static fn($f)=>require $f,$files)))->run();
(new Cms\Core\Auth\AdminAuthenticator($p))->createAdmin('crawler@example.invalid','Synthetic-only!','隔离来源核验');
$r=new Cms\Core\Content\ContentRepository($p,Cms\Core\Content\ContentTypeRegistry::defaults(),[],CMS_ROOT);
foreach(['source-fixture','proxy-fixture','unsupported-fixture'] as $slug){$r->create('article','隔离测试 '.$slug,$slug,[],'published');}
file_put_contents(CMS_ROOT.'/storage/installed.lock','SYNTHETIC CRAWLER TEST ONLY');
echo json_encode((new Cms\Core\Recovery\IntegrityChecker())->check(CMS_ROOT));
'''
    integrity = json.loads(subprocess.check_output(['php', '-r', initialize, str(site)], text=True))
    check(integrity == {'status': 'ok', 'missing': [], 'changed': [], 'unknown': []}, 'native integrity check passes without bypass')
    # Only this disposable router simulates a trusted web-server peer. Never use visitor headers.
    (site / 'fixture-router.php').write_text("<?php $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH); if(str_starts_with($path,'/assets/')&&!str_contains($path,'..')&&is_file(__DIR__.'/public'.$path)){return false;} if(in_array($path,['/articles/source-fixture','/articles/unsupported-fixture'],true)){$_SERVER['REMOTE_ADDR']='8.8.8.8';} require __DIR__.'/public/index.php';")
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def request(path, fields=None, headers=None):
        data = urllib.parse.urlencode(fields).encode() if fields is not None else None
        try:
            with opener.open(urllib.request.Request(f'http://127.0.0.1:{port}' + path, data=data, headers=headers or {}), timeout=10) as response:
                return response.status, response.read().decode(), response.url
        except urllib.error.HTTPError as error:
            return error.code, error.read().decode(), error.url

    def cli(command):
        return json.loads(subprocess.check_output(['php', str(site / 'cli.php'), command], cwd=site, text=True, timeout=15))

    def rows():
        return json.loads((site / 'storage/logs/seo-crawlers.json').read_text())

    server = None
    with (site / 'http.log').open('w') as log:
        try:
            server = subprocess.Popen(['php', '-d', 'session.save_path=' + str(site / 'sessions'), '-S', f'127.0.0.1:{port}', '-t', str(site / 'public'), str(site / 'fixture-router.php')], stdout=log, stderr=log)
            for _ in range(50):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1):
                        break
                except OSError:
                    time.sleep(.1)
            _, _, url = request('/admin/seo/crawlers')
            check('/admin/login' in url, 'anonymous source evidence requires administrator authentication')
            status, _, _ = request('/articles/source-fixture', headers={'User-Agent': 'GPTBot/1.0'})
            check(status == 200 and rows()[0]['verification'] == 'source_pending', 'native public HTTP request queues source evidence without a network lookup')
            status, _, _ = request('/articles/proxy-fixture', headers={'User-Agent': 'GPTBot/1.0', 'X-Forwarded-For': '8.8.8.8'})
            check(status == 200 and rows()[-1]['verification'] == 'source_unavailable', 'visitor forwarded address cannot authenticate the loopback peer')
            request('/articles/unsupported-fixture', headers={'User-Agent': 'Bytespider/1.0'})
            check(rows()[-1]['verification'] == 'source_unsupported', 'unsupported crawler gets an explicit truthful status')
            _, login, _ = request('/admin/login')
            token = html.unescape(re.search(r'name="_csrf" value="([^"]+)"', login)[1])
            request('/admin/login', {'_csrf': token, 'email': 'crawler@example.invalid', 'password': 'Synthetic-only!'})
            status, pending, _ = request('/admin/seo/crawlers')
            check(status == 200 and '待后台核验' in pending and '已验证 · 官方 IP 段' not in pending, 'native admin UI exposes pending and never fabricates an initial success')
            state_file = site / 'storage/cache/seo-crawler-sources.json'
            state = json.loads(state_file.read_text())
            state['feeds'] = dict(state['feeds'])
            state['feeds']['openai_training'] = {'prefixes': ['8.8.8.0/24'], 'fetched_at': int(time.time()), 'expires_at': int(time.time()) + 86400}
            state_file.write_text(json.dumps(state))
            check(cli('seo:crawlers:verify')['verified'] == 1 and rows()[0]['verification'] == 'source_verified_ip', 'actual CLI worker applies only the matching pending batch from a synthetic fresh range')
            before = sum(r['hits'] for r in rows())
            check(cli('seo:crawlers:verify')['processed'] == 0 and sum(r['hits'] for r in rows()) == before, 'CLI retry is idempotent and preserves request counts')
            request('/articles/source-fixture', headers={'User-Agent': 'GPTBot/1.0'})
            check(rows()[0]['hits'] == 2 and rows()[0]['verification'] == 'source_verified_ip', 'subsequent native HTTP request uses only the local fresh range')
            raw = state_file.read_text() + (site / 'storage/logs/seo-crawlers.json').read_text()
            check('ciphertext' not in raw and '8.8.8.8' not in raw, 'completed native task retains neither the source IP nor encrypted pending IP')
            registered = cli('run-scheduled-tasks')
            check(registered['failed'] == 0 and registered['missing'] == 0, 'existing CLI runner registers the Core verification handler')
            subprocess.run(['php', '-r', "$p=new PDO('sqlite:'.$argv[1].'/storage/fixture.sqlite');$p->exec(\"UPDATE cms_core_scheduled_tasks SET next_run_at='2000-01-01T00:00:00+00:00' WHERE task_id='core.seo.crawlers.verify'\");", str(site)], check=True)
            due = cli('run-scheduled-tasks')
            check(due['processed'] == 1 and due['succeeded'] == 1 and due['missing'] == 0, 'minute scheduler executes the real verification task successfully')
            # Add historical and DNS-positive fixture results only for mixed-state presentation checks.
            ledger = rows()
            ledger += [{'day': time.strftime('%Y-%m-%d', time.gmtime()), 'agent': 'GPTBot', 'route': '/articles/{slug}', 'status': 200, 'hits': 5, 'verification': 'source_unverified'},
                       {'day': time.strftime('%Y-%m-%d', time.gmtime()), 'agent': 'PetalBot', 'route': '/articles/{slug}', 'status': 200, 'hits': 3, 'verification': 'source_verified_dns', 'verification_method': 'forward_confirmed_dns', 'verification_evidence': 'PetalBot', 'verification_checked_at': int(time.time())}]
            (site / 'storage/logs/seo-crawlers.json').write_text(json.dumps(ledger))
            status, final, _ = request('/admin/seo/crawlers')
            check(status == 200 and '已验证 2 / 8' in final and '已验证 · 双向 DNS' in final and '历史未验证' in final, 'native statistics separates mixed, historical and DNS evidence')
            check('8.8.8.8' not in final and 'source_key' not in final, 'native admin page never exposes peer addresses or internal batch keys')
            request('/articles/source-fixture', headers={'User-Agent': 'Baiduspider/1.0'})
            check('ciphertext' in state_file.read_text(), 'new DNS-only source has encrypted pending work before collection is disabled')
            subprocess.run(['php', '-r', "$r=$argv[1];$c=require $r.'/config/app.php';$c['seo']['crawler_log_enabled']=false;file_put_contents($r.'/config/app.php','<?php return '.var_export($c,true).';');$p=new PDO('sqlite:'.$r.'/storage/fixture.sqlite');$p->exec(\"UPDATE cms_core_scheduled_tasks SET next_run_at='2000-01-01T00:00:00+00:00' WHERE task_id='core.seo.crawlers.verify'\");", str(site)], check=True)
            disabled = cli('run-scheduled-tasks')
            cancelled = [r for r in rows() if r['agent'] == 'Baiduspider']
            check(disabled['succeeded'] == 1 and disabled['missing'] == 0 and 'ciphertext' not in state_file.read_text() and cancelled[0]['verification'] == 'source_unavailable', 'actual cron runner removes queued IPs and marks cancelled batches unavailable after collection is disabled')
            _, css, _ = request('/assets/admin/admin.css')
            _, js, _ = request('/assets/admin/admin.js')
            if args.output:
                args.output.parent.mkdir(parents=True, exist_ok=True)
                for name, body in [('pending.html', pending), ('verified.html', final)]:
                    body = re.sub(r'<link rel="stylesheet" href="[^"]*admin\.css[^"]*">', lambda _: '<style>' + css + '</style>', body)
                    body = re.sub(r'<script src="[^"]*admin\.js[^"]*" defer></script>', lambda _: '<script>' + js + '</script>', body)
                    body = body.replace('<h1>蜘蛛记录与统计</h1>', '<h1>蜘蛛记录与统计</h1><p>隔离 UI 样例：来源、流量和 DNS 结果为测试数据。</p>')
                    (args.output.parent / name).write_text(body)
                args.output.write_text(json.dumps({'status': 'PASS', 'checks': checks, 'synthetic_sources': True, 'network': 'loopback only', 'production_modified': False}, ensure_ascii=False, indent=2) + '\n')
        finally:
            if server is not None:
                server.terminate()
                server.wait(timeout=5)
print(f'Crawler native HTTP/CLI PASS: {len(checks)} checks; synthetic sources; loopback only')
