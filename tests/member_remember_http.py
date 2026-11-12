#!/usr/bin/env python3
"""Exercise the real HTTP cookie across a discarded PHP session."""

import http.client
import os
from pathlib import Path
import re
import socket
import subprocess
import tempfile
import time
from urllib.parse import urlencode

source = Path(__file__).resolve().parents[1]
router = r'''<?php
require getenv('CMS_SOURCE') . '/system/core/Bootstrap/autoload.php';
$db = getenv('REMEMBER_TEST_DB');
$pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (!is_file($db . '.ready')) {
    $pdo->exec('CREATE TABLE cms_front_users (id INTEGER PRIMARY KEY, email TEXT, password_hash TEXT, display_name TEXT, status TEXT, created_at TEXT, updated_at TEXT, last_login_at TEXT)');
    $pdo->exec('CREATE TABLE cms_front_login_attempts (id INTEGER PRIMARY KEY, email TEXT, ip_address TEXT, success INTEGER, attempted_at TEXT)');
    $pdo->exec('CREATE TABLE cms_front_remember_tokens (token_hash TEXT PRIMARY KEY, user_id INTEGER, expires_at INTEGER, created_at TEXT)');
    $stmt = $pdo->prepare('INSERT INTO cms_front_users (email,password_hash,display_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?)');
    $stmt->execute(['chef@example.invalid', Cms\Core\Security\PasswordHasher::hash('sample-password-123'), 'Chef', 'active', 'now', 'now']);
    touch($db . '.ready');
}
Cms\Core\Security\SessionManager::start(false);
$auth = new Cms\Core\Auth\FrontUserAuthenticator($pdo);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/login') {
    $settings = Cms\Core\Config\Settings::fromArray(['database' => ['dsn' => 'sqlite:' . $db]]);
    $controller = new Cms\Core\Content\ContentFrontController(dirname($db), $settings, new Cms\Core\Logging\FileLogger(dirname($db) . '/app.log'));
    $request = Cms\Core\Http\Request::capture();
    ($_SERVER['REQUEST_METHOD'] === 'POST' ? $controller->login($request) : $controller->loginForm($request))->send();
} elseif ($path === '/logout') {
    $auth->logout();
    http_response_code(204);
} else {
    header('Content-Type: text/plain');
    echo $auth->user() === null ? 'anonymous' : 'member';
}
'''

with tempfile.TemporaryDirectory(prefix='cms-remember-http-') as directory:
    root = Path(directory)
    (root / 'router.php').write_text(router)
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
    env = os.environ | {'CMS_SOURCE': str(source), 'REMEMBER_TEST_DB': str(root / 'test.sqlite')}
    server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(root), str(root / 'router.php')], stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, env=env)
    try:
        for _ in range(50):
            try:
                connection = http.client.HTTPConnection('127.0.0.1', port, timeout=2)
                connection.request('GET', '/login?remember=1')
                response = connection.getresponse()
                cookies = response.headers.get_all('Set-Cookie') or []
                login_form = response.read().decode()
                connection.close()
                break
            except OSError:
                time.sleep(.05)
        else:
            raise RuntimeError('PHP test server did not start')
        assert response.status == 200 and 'name="remember" value="1" checked' in login_form, f'login form HTTP {response.status}'
        session = next((cookie for cookie in cookies if cookie.startswith('cms_admin_session=')), '')
        csrf = re.search(r'name="_csrf" value="([^"]+)"', login_form)
        assert session and csrf, 'login session or CSRF token missing'
        form = urlencode({'email': 'chef@example.invalid', 'password': 'sample-password-123', 'redirect': '/', 'remember': '1', '_csrf': csrf.group(1)})
        connection = http.client.HTTPConnection('127.0.0.1', port, timeout=3)
        connection.request('POST', '/login', form, {'Cookie': session.split(';', 1)[0], 'Content-Type': 'application/x-www-form-urlencoded'})
        response = connection.getresponse()
        cookies = response.headers.get_all('Set-Cookie') or []
        response.read()
        connection.close()
        assert response.status == 302, f'login POST HTTP {response.status}'
        remembered = next((cookie for cookie in cookies if cookie.startswith('cms_front_remember=')), '')
        assert remembered and 'httponly' in remembered.lower() and 'samesite=lax' in remembered.lower() and 'expires=' in remembered.lower(), remembered
        token = re.match(r'cms_front_remember=([^;]+)', remembered).group(1)

        def call(path, method='GET'):
            connection = http.client.HTTPConnection('127.0.0.1', port, timeout=3)
            connection.request(method, path, headers={'Cookie': f'cms_front_remember={token}'})
            result = connection.getresponse()
            body = result.read().decode()
            status = result.status
            connection.close()
            return status, body

        restored = call('/who')
        assert restored == (200, 'member'), f'remember cookie did not survive discarded session cookie: {restored}'
        assert call('/logout', 'POST')[0] == 204, 'logout failed'
        assert call('/who') == (200, 'anonymous'), 'revoked cookie still authenticated'
        print('PASS HTTP member remember: persistent HttpOnly cookie, session restoration, logout revocation')
    finally:
        server.terminate()
        server.wait(timeout=5)
