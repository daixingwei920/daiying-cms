"""Exercise the candidate UI's complete native HTTP update flow in a disposable site.

Uses an existing signed package; never generates signing keys. The fixture explicitly
models current-version metadata separately from candidate handlers. Formal native
old-installer upgrade/rollback acceptance is run independently by the release gate.
"""
import argparse
import hashlib
import html
from html.parser import HTMLParser
import http.cookiejar
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
import zipfile

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--package', required=True, type=Path)
parser.add_argument('--public-key', required=True)
parser.add_argument('--current-version', required=True)
parser.add_argument('--commit')
parser.add_argument('--php', default='php')
parser.add_argument('--output', required=True, type=Path)
args = parser.parse_args()
source = Path(__file__).resolve().parents[1]
package = args.package.resolve().read_bytes()
with zipfile.ZipFile(args.package) as archive:
    manifest = json.loads(archive.read('update.json'))
target_version = manifest.get('to_version', manifest.get('version'))
assert target_version and args.current_version != target_version
commit = subprocess.check_output(['git', 'rev-parse', args.commit + '^{commit}'], cwd=source, text=True).strip() if args.commit else None
checks, calls = [], []


def check(ok, label):
    if not ok:
        raise AssertionError(label)
    checks.append(label)
    print('PASS ' + label, flush=True)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *_args, **_kwargs):
        return None


class Page(HTMLParser):
    def __init__(self, text):
        super().__init__()
        self.forms, self.links = [], []
        self.current, self.select, self.textarea, self.link = None, None, None, None
        self.feed(text)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'form':
            self.current = dict(attrs, fields={})
            self.forms.append(self.current)
        if tag == 'a':
            self.link = dict(attrs, text='')
            self.links.append(self.link)
        if not self.current:
            return
        if tag == 'input' and attrs.get('name') and (attrs.get('type') not in ['checkbox', 'radio'] or 'checked' in attrs):
            self.current['fields'][attrs['name']] = attrs.get('value', '')
        if tag == 'select':
            self.select = attrs.get('name')
        if tag == 'option' and self.select and (self.select not in self.current['fields'] or 'selected' in attrs):
            self.current['fields'][self.select] = attrs.get('value', '')
        if tag == 'textarea':
            self.textarea = attrs.get('name')
            self.current['fields'][self.textarea] = ''

    def handle_data(self, data):
        if self.link is not None:
            self.link['text'] += data
        if self.current and self.textarea:
            self.current['fields'][self.textarea] += data

    def handle_endtag(self, tag):
        if tag == 'a':
            self.link = None
        if tag == 'form':
            self.current = None
        if tag == 'select':
            self.select = None
        if tag == 'textarea':
            self.textarea = None

    def form(self, action):
        forms = [f for f in self.forms if f.get('action') == action]
        assert len(forms) == 1, ('Missing/duplicate form', action)
        assert forms[0].get('method', '').lower() == 'post'
        return dict(forms[0]['fields'])

    def return_url(self):
        links = [a['href'] for a in self.links if a['text'].strip() == '返回系统更新']
        assert len(links) == 1
        return links[0]


class Service(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def do_GET(self):
        calls.append(self.path)
        if self.path.startswith('/api/v1/products/daiying.cms/latest?'):
            query = urllib.parse.parse_qs(urllib.parse.urlsplit(self.path).query)
            compatible = query.get('channel') == ['stable']
            value = {'product_id': 'daiying.cms', 'package_type': 'core', 'version': target_version,
                     'latest_version': target_version, 'update_available': True, 'channel': query.get('channel', ['stable'])[0],
                     'package_url': f'http://127.0.0.1:{self.server.server_port}/fixture.zip',
                     'package_sha256': hashlib.sha256(package).hexdigest(),
                     'compatibility': {'compatible': compatible, 'messages': [] if compatible else ['TEST ONLY incompatible channel']}}
            raw = json.dumps(value).encode()
        elif self.path == '/fixture.zip':
            raw = package
        else:
            self.send_error(404)
            return
        self.send_response(200)
        self.send_header('Content-Length', str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)


with tempfile.TemporaryDirectory(prefix='cms-native-update-flow-') as temporary:
    site = Path(temporary)
    if commit:
        with tempfile.TemporaryFile() as archive:
            subprocess.run(['git', 'archive', commit], cwd=source, stdout=archive, check=True)
            archive.seek(0)
            subprocess.run(['tar', '-xf', '-', '-C', str(site)], stdin=archive, check=True)
    else:
        names = subprocess.check_output(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], cwd=source).decode().split('\0')
        for name in filter(None, names):
            origin = source / name
            if origin.is_file():
                target = site / name
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(origin, target)
    (site / 'sessions').mkdir(mode=0o700)
    service = ThreadingHTTPServer(('127.0.0.1', 0), Service)
    threading.Thread(target=service.serve_forever, daemon=True).start()
    initialize = r'''
$root=$argv[1];define('CMS_ROOT',$root);require $root.'/system/core/Bootstrap/autoload.php';
$config=require $root.'/config/app.example.php';$config['app']['version']=$argv[3];$config['app']['secure_cookies']=false;
$config['site']['url']='http://127.0.0.1';$config['database']=['dsn'=>'sqlite:'.$root.'/storage/fixture.sqlite'];
$config['security']['encryption_key']='isolated-flow-symmetric-test-only';$config['updates']['server_url']=$argv[2];$config['updates']['public_key']=$argv[4];
file_put_contents($root.'/config/app.php','<?php return '.var_export($config,true).';');
$s=Cms\Core\Config\Settings::fromArray($config);$p=Cms\Core\Database\ConnectionFactory::make($s);
(new Cms\Core\Migration\MigrationRunner($p,array_map(static fn($f)=>require $f,glob($root.'/system/migrations/*.php'))))->run();
(new Cms\Core\Auth\AdminAuthenticator($p))->createAdmin('flow@example.invalid','Native-flow-fixture-only!','Flow Fixture');
(new Cms\Core\Content\ContentRepository($p,Cms\Core\Content\ContentTypeRegistry::defaults()))->create('article','TEST ONLY retained article','flow-preserved',[],'published');
file_put_contents($root.'/storage/installed.lock','ISOLATED UPDATE FLOW FIXTURE');
echo json_encode((new Cms\Core\Recovery\IntegrityChecker())->check($root));
'''
    integrity = json.loads(subprocess.check_output([args.php, '-r', initialize, str(site), f'http://127.0.0.1:{service.server_port}', args.current_version, args.public_key], text=True))
    check(integrity == {'status': 'ok', 'missing': [], 'changed': [], 'unknown': []}, 'candidate fixture passes original Core integrity checker')
    config_hash = hashlib.sha256((site / 'config/app.php').read_bytes()).hexdigest()
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())
    anonymous = urllib.request.build_opener(NoRedirect())

    def request(path, fields=None, authenticated=True):
        req = urllib.request.Request(base + path, data=None if fields is None else urllib.parse.urlencode(fields).encode())
        try:
            response = (client if authenticated else anonymous).open(req, timeout=45)
        except urllib.error.HTTPError as error:
            response = error
        return response.code, response.read().decode(), dict(response.headers)

    pointer = site / 'storage/updates/current-release.json'
    with (site / 'http.log').open('w') as log:
        server = subprocess.Popen([args.php, '-d', 'session.save_path=' + str(site / 'sessions'), '-S', f'127.0.0.1:{port}', '-t', str(site / 'public'), str(site / 'public/index.php')], stdout=log, stderr=log)
        try:
            for _ in range(50):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1):
                        break
                except OSError:
                    time.sleep(.1)
            for path in ['/admin/update', '/admin/update/verify', '/admin/update/execute']:
                code, _, _ = request(path, {} if path != '/admin/update' else None, False)
                check(code == 302 and not calls, 'anonymous update access blocked: ' + path)
            _, body, _ = request('/admin/login')
            token = Page(body).form('/admin/login')['_csrf']
            code, _, _ = request('/admin/login', {'_csrf': token, 'email': 'flow@example.invalid', 'password': 'Native-flow-fixture-only!'})
            check(code == 302, 'native administrator login succeeds')
            code, body, _ = request('/admin/update')
            fields = Page(body).form('/admin/update/check')
            check(code == 200 and fields['current_version'] == args.current_version and not pointer.exists(), 'start page models current version without switching Core')
            for action in ['check', 'prepare', 'verify', 'execute']:
                count = len(calls)
                code, _, _ = request('/admin/update/' + action, {'_csrf': 'invalid'})
                check(code == 403 and len(calls) == count and not pointer.exists(), 'invalid CSRF blocked before side effects: ' + action)
            code, body, _ = request('/admin/update/check', dict(fields, channel='beta'))
            check(code == 200 and '下载并准备更新包' not in body, 'incompatible check does not expose preparation')
            code, _, _ = request('/admin/update/prepare', dict(fields, channel='beta'))
            check(code == 400 and '/fixture.zip' not in calls, 'incompatible preparation does not download')
            code, checked, _ = request('/admin/update/check', fields)
            prepare = Page(checked).form('/admin/update/prepare')
            check(code == 200 and target_version in checked, 'official update check offers signed target')
            code, prepared, _ = request('/admin/update/prepare', prepare)
            verify = Page(prepared).form('/admin/update/verify')
            package_path = verify['package_path']
            check(code == 200 and Path(package_path).resolve().is_relative_to(site.resolve()) and Path(package_path).read_bytes() == package, 'preparation downloads exact signed package into isolated incoming directory')
            code, invalid, _ = request('/admin/update/verify', dict(verify, package_path=str(site / 'missing.zip')))
            check(code == 400 and '继续执行更新' not in invalid and not pointer.exists(), 'missing package cannot produce a continuation form')
            code, verified, _ = request('/admin/update/verify', verify)
            page = Page(verified)
            execute = page.form('/admin/update/execute')
            check(code == 200 and '更新包验证通过' in verified and execute['package_path'] == package_path, 'verification directly exposes execution with exact prepared path')
            check(execute['confirmation'] == '' and '_csrf' in execute and '继续执行更新' in verified, 'continuation requires manual second confirmation and CSRF')
            check(not pointer.exists(), 'verification and continuation rendering do not execute an update')
            return_url = page.return_url()
            check(urllib.parse.parse_qs(urllib.parse.urlsplit(return_url).query).get('prepared_package') == [package_path], 'verified return link preserves the package path')
            code, returned, _ = request(return_url)
            returned_page = Page(returned)
            check(code == 200 and all(returned_page.form('/admin/update/' + action)['package_path'] == package_path for action in ['verify', 'execute']), 'returning restores both verification and execution paths without typing')
            code, reverified, _ = request('/admin/update/verify', returned_page.form('/admin/update/verify'))
            execute = Page(reverified).form('/admin/update/execute')
            check(code == 200 and execute['package_path'] == package_path, 'return and reverify retain direct continuation')
            code, wrong, _ = request('/admin/update/execute', dict(execute, confirmation='WRONG'))
            check(code == 400 and 'second confirmation' in wrong and not pointer.exists(), 'wrong confirmation does not switch Core')
            wrong_return = Page(wrong).return_url()
            code, after_error, _ = request(wrong_return)
            check(code == 200 and Page(after_error).form('/admin/update/execute')['package_path'] == package_path, 'return after confirmation failure also retains package path')
            Path(package_path).write_bytes(b'CORRUPTED AFTER VERIFICATION')
            code, damaged, _ = request('/admin/update/execute', dict(execute, confirmation='UPDATE CORE'))
            check(code == 400 and '系统更新失败' in damaged and not pointer.exists(), 'execution revalidates and rejects a package changed after verification')
            Path(package_path).write_bytes(package)
            code, verified, _ = request('/admin/update/verify', verify)
            execute = Page(verified).form('/admin/update/execute')
            check(code == 200 and not pointer.exists(), 'restored signed package verifies without automatic execution')
            code, completed, _ = request('/admin/update/execute', dict(execute, confirmation='UPDATE CORE'))
            check(code == 200 and '系统更新完成' in completed and 'Completed' in completed, 'direct continuation performs a real native update through HTTP')
            state = json.loads(pointer.read_text())
            check(state['version'] == target_version and Path(state['path']).resolve().is_relative_to(site.resolve()), 'native pointer selects target Core inside the isolated site')
            check(hashlib.sha256((site / 'config/app.php').read_bytes()).hexdigest() == config_hash, 'original site configuration is preserved')
            code, article, _ = request('/articles/flow-preserved')
            check(code == 200 and 'TEST ONLY retained article' in article, 'existing published content remains readable after direct execution')
            code, body, _ = request('/admin/update')
            check(code == 200 and target_version in body and '尚未选择安装包' in body, 'completed update returns to current target version without stale prepared state')
            check(not (site / 'storage/maintenance.mode').exists() and not (site / 'storage/recovery.mode').exists(), 'successful flow exits maintenance and recovery modes')
            check(calls.count('/fixture.zip') == 1 and all(x.startswith('/api/v1/products/daiying.cms/latest?') or x == '/fixture.zip' for x in calls), 'all package-service traffic stays on loopback with one download')
            args.output.parent.mkdir(parents=True, exist_ok=True)
            args.output.write_text(json.dumps({'status': 'PASS', 'source_commit': commit, 'fixture_current_version': args.current_version, 'target_version': target_version, 'fixture_scope': 'Candidate handlers with explicit current-version metadata; actual old-installer upgrade/rollback is an independent release gate', 'signed_package_sha256': hashlib.sha256(package).hexdigest(), 'checks': checks, 'mock_requests': calls, 'real_update_executed_in_isolation': True, 'production_writes': 0, 'external_requests': 0, 'private_key_generation': False}, ensure_ascii=False, indent=2) + '\n')
            print('Native update flow PASS (' + str(len(checks)) + ' checks)', flush=True)
        finally:
            server.terminate()
            server.wait(timeout=10)
            service.shutdown()
            service.server_close()
