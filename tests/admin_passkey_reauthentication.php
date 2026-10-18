<?php

declare(strict_types=1);

require dirname(__DIR__) . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Admin\AdminController;
use Cms\Core\Auth\AdminPasskeyService;
use Cms\Core\Config\Settings;
use Cms\Core\Http\Request;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Security\CsrfToken;

$dbFile = tempnam(sys_get_temp_dir(), 'daiying-passkey-reauth-');
if ($dbFile === false) {
    throw new RuntimeException('Unable to create isolated database.');
}
register_shutdown_function(static function () use ($dbFile): void { @unlink($dbFile); });
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE cms_admin_passkeys (id INTEGER PRIMARY KEY, admin_id INTEGER, credential_id TEXT, public_key_cose TEXT, sign_count INTEGER, label TEXT, created_at TEXT, last_used_at TEXT)');
$pdo->exec('CREATE TABLE cms_audit_logs (actor_type TEXT, actor_id INTEGER, action TEXT, context_json TEXT, created_at TEXT)');
$pdo->exec("INSERT INTO cms_admin_passkeys VALUES (1, 1, 'fixture-credential', '', 0, 'Fixture', '', NULL)");
$settings = Settings::fromArray(['database' => ['dsn' => 'sqlite:' . $dbFile], 'site' => ['url' => 'https://example.invalid']]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION = [];
$service = new AdminPasskeyService($pdo, $settings);
$controller = new AdminController($settings, new FileLogger(sys_get_temp_dir() . '/daiying-passkey-reauth.log'), dirname(__DIR__));
$check = static function (bool $ok, string $label): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    echo 'PASS: ' . $label . PHP_EOL;
};
$request = static fn (string $path, array $body = []): Request => new Request('POST', $path, [], $body);
$optionsPath = '/admin/security/reauth/passkey-options';
$verifyPath = '/admin/security/reauth/passkey-verify';

$check($controller->adminSecurityPasskeyReauthOptions($request($optionsPath))->status() === 401, 'Anonymous challenge denied');
$_SESSION['admin_user'] = ['id' => 1, 'email' => 'admin@example.invalid', 'display_name' => 'Fixture'];
$check($controller->adminSecurityPasskeyReauthOptions($request($optionsPath))->status() === 403, 'Challenge requires CSRF');
$csrf = CsrfToken::get();
$check($controller->adminSecurityPasskeyReauthOptions($request($optionsPath, ['_csrf' => $csrf]))->status() === 200, 'Authenticated administrator receives challenge');
$check(($_SESSION['admin_passkey_authentication']['purpose'] ?? '') === 'reauth', 'Challenge is scoped to reauthentication');
$check(!$service->verifyAuthentication(1, [], 'login'), 'Reauthentication challenge cannot complete login');
$check(!$service->verifyAuthentication(2, [], 'reauth'), 'Other administrator cannot use challenge');
$challenge = (string) $_SESSION['admin_passkey_authentication']['challenge'];
$client = AdminPasskeyService::b64(json_encode(['type' => 'webauthn.get', 'challenge' => $challenge, 'origin' => 'https://example.invalid'], JSON_THROW_ON_ERROR));
$presenceOnly = AdminPasskeyService::b64(hash('sha256', 'example.invalid', true) . "\x01" . str_repeat("\0", 4));
$check(!$service->verifyAuthentication(1, ['id' => 'fixture-credential', 'clientDataJSON' => $client, 'authenticatorData' => $presenceOnly], 'reauth'),
    'Presence without user verification cannot complete reauthentication');
$invalid = $controller->adminSecurityPasskeyReauthVerify($request($verifyPath, ['_csrf' => $csrf]));
$check($invalid->status() === 401, 'Invalid assertion denied (got ' . $invalid->status() . ': ' . $invalid->body() . ')');
$check(!isset($_SESSION['admin_reauthenticated_at']), 'Failed assertion does not grant recent reauthentication');
$_SESSION['admin_passkey_authentication']['issued_at'] = time() - 601;
$check(!$service->verifyAuthentication(1, [], 'reauth'), 'Expired challenge denied');
$check($controller->adminSecurityPasskeyReauthVerify($request($verifyPath))->status() === 403, 'Verification requires CSRF');
