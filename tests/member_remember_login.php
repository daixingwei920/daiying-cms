<?php

declare(strict_types=1);

require dirname(__DIR__) . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Auth\FrontUserAuthenticator;
use Cms\Core\Auth\FrontUserRememberService;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Security\SessionManager;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$members = require dirname(__DIR__) . '/system/migrations/2026_08_29_000001_members_comments_schema.php';
$remember = require dirname(__DIR__) . '/system/migrations/2026_11_12_000001_front_remember_tokens.php';
check((new MigrationRunner($pdo, [$members, $remember]))->run() === 2, 'Member migrations failed.');
$remember->up($pdo);
SessionManager::start(false);
$_SESSION = [];
$_COOKIE = [];
$auth = new FrontUserAuthenticator($pdo);
$id = $auth->register('chef@example.invalid', 'sample-password-123', 'Chef');
check($id > 0, 'Registration failed.');
unset($_SESSION['front_user']);

check($auth->attempt('chef@example.invalid', 'sample-password-123', '127.0.0.1', true), 'Remembered login failed.');
$token = $_COOKIE[FrontUserRememberService::COOKIE] ?? '';
check(is_string($token) && preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1, 'Persistent cookie missing.');
$row = $pdo->query('SELECT token_hash, expires_at FROM cms_front_remember_tokens')->fetch(PDO::FETCH_ASSOC);
check(is_array($row) && hash_equals($row['token_hash'], hash('sha256', $token)) && $row['token_hash'] !== $token, 'Only a token hash should be stored.');
check((int) $row['expires_at'] > time() + 360 * 86400, 'Initial expiry too short.');

unset($_SESSION['front_user']);
check(($auth->user()['id'] ?? null) === $id, 'Cookie did not restore the member after session loss.');
$pdo->exec('UPDATE cms_front_remember_tokens SET expires_at = ' . (time() + 10 * 86400));
$_SESSION['front_remember_checked_at'] = time() - 86401;
check(($auth->user()['id'] ?? null) === $id, 'Active member was lost during renewal.');
check((int) $pdo->query('SELECT expires_at FROM cms_front_remember_tokens')->fetchColumn() > time() + 360 * 86400, 'Active use did not renew expiry.');

$auth->logout();
check(!isset($_COOKIE[FrontUserRememberService::COOKIE]) && (int) $pdo->query('SELECT COUNT(*) FROM cms_front_remember_tokens')->fetchColumn() === 0, 'Logout did not revoke the persistent login.');
check($auth->user() === null, 'Logout did not clear the current member.');

check($auth->attempt('chef@example.invalid', 'sample-password-123', '127.0.0.1', false), 'Ordinary login failed.');
check(!isset($_COOKIE[FrontUserRememberService::COOKIE]), 'Ordinary login unexpectedly persisted.');
unset($_SESSION['front_user']);
check($auth->user() === null, 'Ordinary login restored without a cookie.');

check($auth->attempt('chef@example.invalid', 'sample-password-123', '127.0.0.1', true), 'Second remembered login failed.');
$pdo->exec("UPDATE cms_front_users SET status = 'disabled' WHERE id = " . $id);
unset($_SESSION['front_user']);
check($auth->user() === null, 'Disabled member restored from remembered login.');
check((int) $pdo->query('SELECT COUNT(*) FROM cms_front_remember_tokens')->fetchColumn() === 0, 'Disabled member token was not revoked.');

$pdo->exec("UPDATE cms_front_users SET status = 'active' WHERE id = " . $id);
check($auth->attempt('chef@example.invalid', 'sample-password-123', '127.0.0.1', true), 'Third remembered login failed.');
$auth->loginUser(['id' => $id + 1, 'email' => 'other@example.invalid', 'display_name' => 'Other']);
check(!isset($_COOKIE[FrontUserRememberService::COOKIE]) && (int) $pdo->query('SELECT COUNT(*) FROM cms_front_remember_tokens')->fetchColumn() === 0, 'Switching member identity kept the previous member token.');

echo "PASS member remember login: persistence, renewal, logout, ordinary login, disabled account, identity switch\n";
