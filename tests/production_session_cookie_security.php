<?php

declare(strict_types=1);

define('CMS_SOURCE_ROOT', dirname(__DIR__));

$failures = 0;

function session_cookie_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function session_cookie_probe(bool $secure): array
{
    $code = 'require ' . var_export(CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php', true) . '; ' .
        'Cms\\Core\\Security\\SessionManager::start(' . ($secure ? 'true' : 'false') . '); ' .
        'echo json_encode(["name"=>session_name(),"params"=>session_get_cookie_params()], JSON_UNESCAPED_SLASHES);';
    $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open([PHP_BINARY, '-d', 'session.use_cookies=1', '-r', $code], $descriptor, $pipes, CMS_SOURCE_ROOT);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start PHP session probe.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
        throw new RuntimeException('Session probe failed: ' . trim((string) $stderr));
    }
    $decoded = json_decode((string) $stdout, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Session probe returned invalid JSON.');
    }

    return $decoded;
}

$plain = session_cookie_probe(false);
$secure = session_cookie_probe(true);
$example = require CMS_SOURCE_ROOT . '/config/app.example.php';

session_cookie_check(($plain['name'] ?? '') === 'cms_admin_session' && ($secure['name'] ?? '') === 'cms_admin_session', 'session cookie uses stable CMS admin session name');
session_cookie_check((bool) ($plain['params']['httponly'] ?? false) === true && (bool) ($secure['params']['httponly'] ?? false) === true, 'session cookie is HttpOnly in both secure and non-secure modes');
session_cookie_check((string) ($plain['params']['samesite'] ?? '') === 'Lax' && (string) ($secure['params']['samesite'] ?? '') === 'Lax', 'session cookie uses SameSite=Lax');
session_cookie_check((string) ($plain['params']['path'] ?? '') === '/' && (string) ($secure['params']['path'] ?? '') === '/', 'session cookie path is site-wide root path');
session_cookie_check((bool) ($plain['params']['secure'] ?? true) === false, 'local/non-secure mode does not force Secure cookie flag');
session_cookie_check((bool) ($secure['params']['secure'] ?? false) === true, 'secure mode enables Secure cookie flag');
session_cookie_check((bool) ($example['app']['secure_cookies'] ?? false) === true, 'example production config enables secure cookies by default');
session_cookie_check(($example['app']['version'] ?? '') === '1.2.0', 'example production config exposes stable release-candidate version');

if ($failures > 0) {
    fwrite(STDERR, $failures . " production session cookie security checks failed.\n");
    exit(1);
}

echo "Production session cookie security tests passed.\n";
