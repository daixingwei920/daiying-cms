"""Native report/update UI acceptance using a disposable SQLite site and loopback service."""
import argparse
import hashlib
import http.cookiejar
import html
import json
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--output', type=Path)
parser.add_argument('--commit', help='Test an immutable git archive instead of working tree files')
parser.add_argument('--serve', action='store_true', help='Keep the disposable local preview available until interrupted')
args = parser.parse_args()
source = Path(__file__).resolve().parents[1]
commit = subprocess.check_output(['git', 'rev-parse', args.commit + '^{commit}'], cwd=source, text=True).strip() if args.commit else None
checks, calls = [], []
package_bytes = b'ISOLATED UI PREPARATION ONLY - deliberately not a signed Core ZIP'
report = {'submission_id': 'sub_ui_fixture', 'status': 'MANUAL_REVIEW',
          'static_report': {'status': 'PASS', 'findings': []},
          'ai_report': {'status': 'MANUAL_REVIEW', 'risk_level': 'LOW', 'findings': [
              {'severity': 'LOW', 'file': 'content/plugins/example/DirectoryService.php', 'line': 19,
               'description': '隔离 UI 样例：外部请求需要人工核对。 ' + 'Read the full implementation and retain all safety checks. ' * 15,
               'recommendation': '保留网络权限声明，检查重定向、TLS 与公共地址验证。'},
              {'severity': 'HIGH', 'file': 'plugin.php', 'line': 42,
               'description': '隔离 UI 样例：能力声明需要核对。',
               'recommendation': '合成测试数据，不代表真实审核发现。'}]}}


def check(ok, label):
    if not ok:
        raise AssertionError(label)
    checks.append(label)
    print('PASS ' + label, flush=True)


class MockService(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def do_GET(self):
        calls.append(self.path)
        if self.path == '/api/v1/review/submissions/sub_ui_fixture/report':
            payload = report
        elif self.path.startswith('/api/v1/products/daiying.cms/latest?'):
            compatible = urllib.parse.parse_qs(urllib.parse.urlsplit(self.path).query).get('channel') == ['beta']
            payload = {'update_available': True, 'product_id': 'daiying.cms', 'version': '9.9.9-fixture',
                       'latest_version': '9.9.9-fixture', 'package_url': f'http://127.0.0.1:{self.server.server_port}/fixture.zip',
                       'package_sha256': hashlib.sha256(package_bytes).hexdigest(), 'channel': 'beta' if compatible else 'stable',
                       'compatibility': {'compatible': compatible, 'messages': [] if compatible else ['隔离演示：模拟不兼容，不允许下载准备。']}}
        elif self.path == '/fixture.zip':
            self.send_response(200)
            self.send_header('Content-Length', str(len(package_bytes)))
            self.end_headers()
            self.wfile.write(package_bytes)
            return
        else:
            self.send_error(404)
            return
        body = json.dumps(payload, ensure_ascii=False).encode()
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)


with tempfile.TemporaryDirectory(prefix='cms-admin-ui-') as temporary:
    site = Path(temporary)
    if commit:
        with tempfile.TemporaryFile() as archive:
            subprocess.run(['git', 'archive', commit], cwd=source, stdout=archive, check=True)
            archive.seek(0)
            subprocess.run(['tar', '-xf', '-', '-C', str(site)], stdin=archive, check=True)
    else:
        files = subprocess.check_output(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], cwd=source).decode().split('\0')
        for name in filter(None, files):
            origin = source / name
            if origin.is_file():
                target = site / name
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(origin, target)
    (site / 'sessions').mkdir(mode=0o700)
    mock = ThreadingHTTPServer(('127.0.0.1', 0), MockService)
    threading.Thread(target=mock.serve_forever, daemon=True).start()
    server = None
    try:
        initialize = r'''
$root=$argv[1]; require $root.'/system/core/Bootstrap/autoload.php';
$config=require $root.'/config/app.example.php';
$config['app']['secure_cookies']=false; $config['site']['url']='http://127.0.0.1';
$config['database']=['dsn'=>'sqlite:'.$root.'/storage/fixture.sqlite'];
$config['security']['encryption_key']=bin2hex(random_bytes(32));
$config['market']['enabled']=true; $config['market']['server_url']=$argv[2];
$config['review']['server_url']=$argv[2]; $config['updates']['server_url']=$argv[2];
file_put_contents($root.'/config/app.php', "<?php\nreturn ".var_export($config,true).";\n"); chmod($root.'/config/app.php',0600);
$settings=Cms\Core\Config\Settings::fromArray($config);
$pdo=Cms\Core\Database\ConnectionFactory::make($settings);
$files=glob($root.'/system/migrations/*.php');sort($files,SORT_STRING);
(new Cms\Core\Migration\MigrationRunner($pdo,array_map(static fn($f)=>require $f,$files)))->run();
(new Cms\Core\Auth\AdminAuthenticator($pdo))->createAdmin('ui@example.invalid','Isolated-ui-only!','UI Fixture');
$pdo->exec("INSERT INTO cms_review_submissions(submission_id,product_id,package_type,version,developer_name,developer_email,status,created_at,updated_at) VALUES ('sub_ui_fixture','example.plugin','plugin','0.1.0','UI Fixture','ui@example.invalid','UPLOADED','fixture','fixture')");
file_put_contents($root.'/storage/installed.lock','ISOLATED UI FIXTURE ONLY');
echo json_encode((new Cms\Core\Recovery\IntegrityChecker())->check($root));
'''
        integrity = json.loads(subprocess.check_output(['php', '-r', initialize, str(site), f'http://127.0.0.1:{mock.server_port}'], text=True))
        check(integrity == {'status': 'ok', 'missing': [], 'changed': [], 'unknown': []}, 'candidate passes native integrity checker without bypass')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        base = f'http://127.0.0.1:{port}'
        opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

        def request(path, fields=None):
            data = urllib.parse.urlencode(fields).encode() if fields is not None else None
            try:
                with opener.open(urllib.request.Request(base + path, data=data), timeout=20) as response:
                    return response.status, response.read().decode(), response.url
            except urllib.error.HTTPError as error:
                return error.code, error.read().decode(), error.url

        def csrf(body):
            return html.unescape(re.search(r'name="_csrf" value="([^"]+)"', body)[1])

        # Preview-only static pages exercise the exact presentation helpers, never update execution.
        subprocess.run(['php', str(site / 'tests/admin_data_workspace.php'), '--preview', str(site / 'public/ui-fixture')], check=True, stdout=subprocess.DEVNULL)
        (site / 'public/ui-fixture/index.html').write_text('<h1>隔离 UI 预览</h1><p>合成测试数据，未执行真实更新。</p><a href="/ui-fixture/review.html">审核结果</a> · <a href="/ui-fixture/verification.html">验证计划</a> · <a href="/ui-fixture/result.html">执行结果样例</a> · <a href="/admin/update">真实本地更新表单</a>')
        (site / 'preview-router.php').write_text("<?php $path=parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); if ((str_starts_with($path, '/ui-fixture/') || str_starts_with($path, '/assets/')) && !str_contains($path, '..') && is_file(__DIR__.'/public'.$path)) { return false; } require __DIR__.'/public/index.php';")
        with (site / 'http.log').open('w') as log:
            server = subprocess.Popen(['php', '-d', 'session.save_path=' + str(site / 'sessions'), '-S', f'127.0.0.1:{port}', '-t', str(site / 'public'), str(site / 'preview-router.php')], stdout=log, stderr=log)
            for _ in range(50):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1):
                        break
                except OSError:
                    time.sleep(.1)
            status, css, _ = request('/assets/admin/admin.css')
            check(status == 200 and '.data-layout' in css, 'preview serves the actual candidate stylesheet')
            status, js, _ = request('/assets/admin/admin.js')
            check(status == 200 and 'data-report-tab' in js, 'preview serves the actual candidate interaction script')
            status, _, url = request('/admin/market/submissions/sub_ui_fixture')
            check(status == 200 and '/admin/login' in url and not calls, 'anonymous review access redirects before remote report lookup')
            _, login, _ = request('/admin/login')
            request('/admin/login', {'_csrf': csrf(login), 'email': 'ui@example.invalid', 'password': 'Isolated-ui-only!'})
            status, body, _ = request('/admin/update')
            check(status == 200 and 'data-admin-data-view' in body, 'native update page uses grouped workspace')
            if args.output:
                args.output.parent.mkdir(parents=True, exist_ok=True)
                export = (args.output.parent / 'preview')
                export.mkdir(exist_ok=True)
                standalone = re.sub(r'<link rel="stylesheet" href="[^"]*admin\.css[^"]*">', lambda _: '<style>' + css + '</style>', body)
                standalone = re.sub(r'<script src="[^"]*admin\.js[^"]*" defer></script>', lambda _: '<script>' + js + '</script>', standalone)
                standalone = re.sub(r'<form method="post" action="[^"]*">', '<form onsubmit="return false">', standalone)
                standalone = re.sub(r'<input type="hidden" name="_csrf" value="[^"]*">', '', standalone)
                standalone = standalone.replace('<h1>系统更新</h1>', '<h1>系统更新</h1><p>隔离 UI 演示：表单提交已禁用。</p>')
                (export / 'updates.html').write_text(standalone)
            token = csrf(body)
            check('data-report-panel hidden' not in body, 'server HTML keeps all update forms available without JavaScript')
            status, prepared, _ = request('/admin/update?prepared_package=%2Ffixture.zip')
            check(status == 200 and prepared.index('updates-verify-tab') < prepared.index('updates-check-tab') and '请先验证安装包' in prepared, 'return with prepared path selects verification without claiming verification success')
            check(all('action="/admin/update/' + action + '"' in body for action in ['check', 'verify', 'execute']), 'all update POST forms and CSRF fields remain present')
            for action in ['check', 'prepare', 'verify', 'execute']:
                status, _, _ = request('/admin/update/' + action, {'_csrf': 'invalid'})
                check(status == 403 and not calls, action + ' rejects invalid CSRF before any provider call')
            status, review, _ = request('/admin/market/submissions/sub_ui_fixture')
            check(status == 200 and '等待人工审核' in review and review.count('class="data-issue"') == 2, 'native review handler renders final manual status and all findings')
            check(calls == ['/api/v1/review/submissions/sub_ui_fixture/report'], 'review fetch is limited to loopback mock report endpoint')
            status, checked, _ = request('/admin/update/check', {'_csrf': token, 'server_url': f'http://127.0.0.1:{mock.server_port}', 'product_id': 'daiying.cms', 'channel': 'stable'})
            check(status == 200 and '不兼容' in checked and '下载并准备更新包' not in checked, 'native compatibility rejection retains the prepare-action guard')
            fields = {'_csrf': token, 'server_url': f'http://127.0.0.1:{mock.server_port}', 'product_id': 'daiying.cms', 'channel': 'stable'}
            status, blocked, _ = request('/admin/update/prepare', fields)
            check(status == 400 and '不兼容' in blocked and '/fixture.zip' not in calls, 'incompatible prepare POST rejects before downloading a package')
            status, prepared, _ = request('/admin/update/prepare', dict(fields, channel='beta'))
            check(status == 200 and '更新包已准备' in prepared and '尚未执行安装' in prepared, 'compatible prepare POST displays the new preparation workspace')
            package_path = html.unescape(re.search(r'name="package_path" value="([^"]+)"', prepared)[1])
            check(Path(package_path).resolve().is_relative_to(site.resolve()) and Path(package_path).read_bytes() == package_bytes and calls.count('/fixture.zip') == 1,
                  'preparation downloads only the loopback fixture and preserves exact bytes')
            check(prepared.count('name="package_path"') == 2 and 'name="confirmation"' in prepared and prepared.count('name="_csrf"') >= 2,
                  'prepared workspace preserves both POST actions, CSRF and second confirmation')
            status, rejected, _ = request('/admin/update/verify', {'_csrf': token, 'package_path': package_path})
            check(status == 400 and '更新包验证失败' in rejected, 'download preparation never makes an invalid unsigned payload trusted')
            status, failed, _ = request('/admin/update/verify', {'_csrf': token, 'package_path': str(site / 'unsigned.zip')})
            check(status == 400 and '更新包验证失败' in failed, 'missing/untrusted package cannot become a successful verification UI')
            status, failed, _ = request('/admin/update/execute', {'_csrf': token, 'package_path': str(site / 'unsigned.zip'), 'confirmation': 'WRONG'})
            check(status == 400 and 'second confirmation' in failed, 'native second confirmation remains enforced before update execution')
            check(not (site / 'storage/updates/current-release.json').exists(), 'failed operations do not switch the Core release pointer')
            if args.output:
                args.output.parent.mkdir(parents=True, exist_ok=True)
                runtime_paths = ['system/core/Admin/AdminController.php', 'system/core/Admin/AdminDataView.php', 'system/core/Support/View.php', 'system/core-manifest.json', 'public/assets/admin/admin.css', 'public/assets/admin/admin.js']
                args.output.write_text(json.dumps({'status': 'PASS', 'source_commit': commit, 'checks': checks, 'assertion_count': len(checks), 'mock_requests': calls, 'source': 'immutable git archive' if commit else 'working tree snapshot', 'runtime_sha256': {p: hashlib.sha256((site / p).read_bytes()).hexdigest() for p in runtime_paths}, 'real_update_executed': False, 'external_calls': 0}, ensure_ascii=False, indent=2) + '\n')
            print('PREVIEW ' + base + '/ui-fixture/index.html', flush=True)
            print('LOCAL_FIXTURE_LOGIN ui@example.invalid / Isolated-ui-only!', flush=True)
            if args.serve:
                print('Serving disposable preview; Ctrl-C cleans fixture and servers.', flush=True)
                while True:
                    time.sleep(1)
    except KeyboardInterrupt:
        pass
    finally:
        if server:
            server.terminate()
            server.wait(timeout=10)
        mock.shutdown()
        mock.server_close()
