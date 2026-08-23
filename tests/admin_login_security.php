<?php

declare(strict_types=1);

use Cms\Core\Admin\AdminController;
use Cms\Core\Auth\AdminAuthenticator;
use Cms\Core\Auth\AdminMfaService;
use Cms\Core\Config\Settings;
use Cms\Core\Http\Request;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Security\CsrfToken;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;

function admin_login_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }

    echo '[PASS] ' . $message . PHP_EOL;
}

function admin_login_remove(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }

    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir((string) $item->getPathname()) : unlink((string) $item->getPathname());
    }
    rmdir($path);
}

$root = sys_get_temp_dir() . '/cms-admin-login-security-' . bin2hex(random_bytes(4));
admin_login_remove($root);
mkdir($root . '/storage/logs', 0755, true);
mkdir($root . '/storage/database', 0755, true);

$dbPath = $root . '/storage/database/login.sqlite';
$settings = Settings::fromArray([
    'database' => [
        'dsn' => 'sqlite:' . $dbPath,
        'username' => '',
        'password' => '',
        'options' => [],
    ],
]);

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$migrations = [];
foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
    $migrations[] = require $file;
}
(new MigrationRunner($pdo, $migrations))->run();

$auth = new AdminAuthenticator($pdo);
$auth->createAdmin('login-admin@example.test', 'login-secret', 'Login Admin');
$controller = new AdminController($settings, new FileLogger($root . '/storage/logs/admin-login-security.log'), $root);

$_SESSION = [];
$csrf = CsrfToken::get();

$badCsrf = $controller->login(new Request('POST', '/admin/login', [], [
    '_csrf' => 'bad-token',
    'email' => 'login-admin@example.test',
    'password' => 'login-secret',
], ['REMOTE_ADDR' => '198.51.100.10']));
admin_login_check($badCsrf->status() === 400 && str_contains($badCsrf->body(), 'CSRF 校验失败'), 'admin login POST rejects invalid CSRF with a Chinese error');
admin_login_check((int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'admin.login'")->fetchColumn() === 0, 'admin login invalid CSRF does not write login audit evidence');
admin_login_check((int) $pdo->query("SELECT COUNT(*) FROM cms_login_attempts")->fetchColumn() === 0, 'admin login invalid CSRF does not record password attempts');

$wrongPassword = $controller->login(new Request('POST', '/admin/login', [], [
    '_csrf' => $csrf,
    'email' => 'login-admin@example.test',
    'password' => 'wrong-secret',
], ['REMOTE_ADDR' => '198.51.100.10']));
admin_login_check($wrongPassword->status() === 401 && str_contains($wrongPassword->body(), '登录失败，或尝试次数过多。'), 'admin login POST returns localized failure for invalid password');
admin_login_check((int) $pdo->query('SELECT COUNT(*) FROM cms_login_attempts WHERE success = 0')->fetchColumn() === 1, 'admin login invalid password records a failed attempt');

$success = $controller->login(new Request('POST', '/admin/login', [], [
    '_csrf' => $csrf,
    'email' => ' login-admin@example.test ',
    'password' => 'login-secret',
], ['REMOTE_ADDR' => '198.51.100.10']));
$audit = $pdo->query("SELECT context_json FROM cms_audit_logs WHERE action = 'admin.login' ORDER BY id DESC LIMIT 1")->fetchColumn();
admin_login_check($success->status() === 302 && ($success->headers()['Location'] ?? '') === '/admin', 'admin login POST redirects to dashboard after valid credentials');
admin_login_check(is_array($_SESSION['admin_user'] ?? null) && ($_SESSION['admin_user']['email'] ?? '') === 'login-admin@example.test', 'admin login POST stores authenticated administrator session');
admin_login_check(is_string($audit) && str_contains($audit, 'login-admin@example.test') && str_contains($audit, '198.51.100.10'), 'admin login success records audit context with email and IP');

$_POST = ['_csrf' => 'bad-token'];
$badLogout = $controller->logout();
admin_login_check($badLogout->status() === 403 && str_contains($badLogout->body(), '无权执行此操作'), 'admin logout POST rejects invalid CSRF without redirecting');
admin_login_check(is_array($_SESSION['admin_user'] ?? null) && ($_SESSION['admin_user']['email'] ?? '') === 'login-admin@example.test', 'admin logout invalid CSRF keeps the authenticated session intact');
admin_login_check((int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'admin.logout'")->fetchColumn() === 0, 'admin logout invalid CSRF does not write logout audit evidence');

$_POST = ['_csrf' => $csrf];
$logout = $controller->logout();
admin_login_check($logout->status() === 302 && ($logout->headers()['Location'] ?? '') === '/admin/login', 'admin logout POST redirects to login after valid CSRF');
admin_login_check(!isset($_SESSION['admin_user']), 'admin logout valid CSRF clears the authenticated session');
admin_login_check((int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'admin.logout'")->fetchColumn() === 1, 'admin logout valid CSRF writes logout audit evidence');

$adminId = (int) $pdo->query("SELECT id FROM cms_admin_users WHERE email = 'login-admin@example.test'")->fetchColumn();
$mfaSecret = AdminMfaService::generateSecret();
(new AdminMfaService($pdo))->enableTotp($adminId, $mfaSecret, ['MFA-RECOVERY-ONE']);
admin_login_check((new AdminMfaService($pdo))->isEnabled($adminId), 'admin MFA service enables TOTP for an administrator');

$mfaLogin = $controller->login(new Request('POST', '/admin/login', [], [
    '_csrf' => $csrf,
    'email' => 'login-admin@example.test',
    'password' => 'login-secret',
], ['REMOTE_ADDR' => '198.51.100.11']));
admin_login_check($mfaLogin->status() === 302 && ($mfaLogin->headers()['Location'] ?? '') === '/admin/mfa', 'admin login with MFA redirects to the second-factor challenge');
admin_login_check(!isset($_SESSION['admin_user']) && is_array($_SESSION['admin_mfa_pending'] ?? null), 'admin login with MFA keeps the session pending until challenge success');

$blockedDashboard = $controller->dashboard();
admin_login_check($blockedDashboard->status() === 302 && ($blockedDashboard->headers()['Location'] ?? '') === '/admin/login', 'pending MFA session cannot access the admin dashboard');

$challengeForm = $controller->mfaChallengeForm();
admin_login_check($challengeForm->status() === 200 && str_contains($challengeForm->body(), '管理员二次验证'), 'admin MFA challenge form renders for pending sessions');

$badMfa = $controller->mfaChallenge(new Request('POST', '/admin/mfa', [], [
    '_csrf' => $csrf,
    'mfa_code' => '000000',
], ['REMOTE_ADDR' => '198.51.100.11']));
admin_login_check($badMfa->status() === 401 && str_contains($badMfa->body(), '二次验证码无效'), 'admin MFA challenge rejects an invalid code with a Chinese error');
admin_login_check(!isset($_SESSION['admin_user']), 'admin MFA invalid code does not authenticate the administrator');

$goodMfa = $controller->mfaChallenge(new Request('POST', '/admin/mfa', [], [
    '_csrf' => $csrf,
    'mfa_code' => AdminMfaService::totpCode($mfaSecret),
], ['REMOTE_ADDR' => '198.51.100.11']));
admin_login_check($goodMfa->status() === 302 && ($goodMfa->headers()['Location'] ?? '') === '/admin', 'admin MFA challenge accepts a valid TOTP code');
admin_login_check(is_array($_SESSION['admin_user'] ?? null) && ($_SESSION['admin_user']['id'] ?? 0) === $adminId, 'admin MFA valid code stores authenticated administrator session');
admin_login_check((int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'admin.login_mfa_failed'")->fetchColumn() === 1, 'admin MFA invalid attempts are audited');

$_POST = ['_csrf' => $csrf];
$controller->logout();
$mfaRecoveryLogin = $controller->login(new Request('POST', '/admin/login', [], [
    '_csrf' => $csrf,
    'email' => 'login-admin@example.test',
    'password' => 'login-secret',
], ['REMOTE_ADDR' => '198.51.100.12']));
admin_login_check($mfaRecoveryLogin->status() === 302 && ($mfaRecoveryLogin->headers()['Location'] ?? '') === '/admin/mfa', 'admin MFA recovery-code flow starts with a pending challenge');
$recoveryMfa = $controller->mfaChallenge(new Request('POST', '/admin/mfa', [], [
    '_csrf' => $csrf,
    'mfa_code' => 'MFA-RECOVERY-ONE',
], ['REMOTE_ADDR' => '198.51.100.12']));
admin_login_check($recoveryMfa->status() === 302 && ($recoveryMfa->headers()['Location'] ?? '') === '/admin', 'admin MFA challenge accepts an unused recovery code');

$_POST = ['_csrf' => $csrf];
$controller->logout();
$controller->login(new Request('POST', '/admin/login', [], [
    '_csrf' => $csrf,
    'email' => 'login-admin@example.test',
    'password' => 'login-secret',
], ['REMOTE_ADDR' => '198.51.100.13']));
$reusedRecovery = $controller->mfaChallenge(new Request('POST', '/admin/mfa', [], [
    '_csrf' => $csrf,
    'mfa_code' => 'MFA-RECOVERY-ONE',
], ['REMOTE_ADDR' => '198.51.100.13']));
admin_login_check($reusedRecovery->status() === 401 && str_contains($reusedRecovery->body(), '二次验证码无效'), 'admin MFA recovery codes cannot be reused');
admin_login_check(!isset($_SESSION['admin_user']), 'admin MFA reused recovery code does not authenticate the administrator');

admin_login_remove($root);

if ($failures > 0) {
    fwrite(STDERR, $failures . " admin login security checks failed.\n");
    exit(1);
}

echo "Admin login security tests passed.\n";
