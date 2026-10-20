<?php

declare(strict_types=1);

require dirname(__DIR__) . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Auth\AdminAuthenticator;
use Cms\Core\Auth\FrontUserAuthenticator;
use Cms\Core\Auth\MemberAccessController;
use Cms\Core\Auth\MemberRoleService;
use Cms\Core\Admin\AdminController;
use Cms\Core\Config\Settings;
use Cms\Core\Content\MemberDraftService;
use Cms\Core\Http\Request;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Security\CsrfToken;

$root = sys_get_temp_dir() . '/cms-member-access-' . bin2hex(random_bytes(6));
mkdir($root . '/content/uploads', 0700, true);
register_shutdown_function(static function () use ($root): void {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
});
$database = ['dsn' => 'sqlite:' . $root . '/site.sqlite'];
if ($file = getenv('CMS_MYSQL_TEST_CONFIG')) {
    $config = require $file;
    if (($config['host'] ?? '') !== '127.0.0.1' || !preg_match('/^cms_mysql_[a-z0-9_]+$/', $config['database'] ?? '')) throw new RuntimeException('Disposable isolated MySQL database required.');
    $database = ['dsn' => 'mysql:host=127.0.0.1;port=' . (int)$config['port'] . ';dbname=' . $config['database'] . ';charset=utf8mb4', 'username' => $config['user'], 'password' => $config['password']];
}
$pdo = new PDO($database['dsn'], $database['username'] ?? '', $database['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$migrationFiles = array_filter(
    glob(dirname(__DIR__) . '/system/migrations/*.php'),
    static fn (string $file): bool => !str_ends_with($file, '2026_09_07_000001_official_plugins_registry.php')
        && !str_ends_with($file, '2026_09_08_000007_official_plugins_registry_sync.php')
);
$migrations = array_map(static fn (string $file) => require $file, $migrationFiles);
(new MigrationRunner($pdo, $migrations))->run();
(new MigrationRunner($pdo, $migrations))->run();
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION = [];
$check = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    echo 'PASS: ' . $label . PHP_EOL;
};

$front = new FrontUserAuthenticator($pdo);
$firstMember = $front->register('operator@example.invalid', 'member-test-password', 'Operator');
$secondMember = $front->register('other@example.invalid', 'member-test-password', 'Other');
$adminAuth = new AdminAuthenticator($pdo);
$adminAuth->createAdmin('owner@example.invalid', 'owner-test-password', 'Owner');
$adminAuth->createAdmin('other-admin@example.invalid', 'admin-test-password', 'Other Admin');
$admin = $pdo->query('SELECT id FROM cms_admin_users ORDER BY id ASC LIMIT 1')->fetchColumn();
$settings = Settings::fromArray(['database' => $database]);
$controller = new MemberAccessController($settings, $root);
$roles = new MemberRoleService($pdo);
$drafts = new MemberDraftService($pdo, $root);
$csrf = CsrfToken::get();
$request = static fn (array $body): Request => new Request('POST', '/admin/member-access', [], $body);

$check(!$roles->has($firstMember, 'content.own_draft'), 'Registration grants no operator permission');
$check($controller->assignments()->status() === 302, 'Anonymous member cannot manage roles');
$_SESSION['admin_user'] = ['id' => (int) $admin + 1, 'email' => 'other-admin@example.invalid', 'display_name' => 'Other Admin'];
$check($controller->assignments()->status() === 403, 'Non-owner administrator cannot manage roles');
$_SESSION['admin_user'] = ['id' => (int) $admin, 'email' => 'owner@example.invalid', 'display_name' => 'Owner'];
$check(str_contains($controller->assignments()->body(), '自有草稿编辑与 SEO 只读权限'), 'Owner sees exact grant scope before submission');
$check($controller->assign($request(['_csrf' => $csrf, 'member_id' => (string) $firstMember, 'enabled' => '1']))->status() === 403, 'Role grant requires recent reauthentication');
$_SESSION['admin_reauthenticated_at'] = time();
$check($controller->assign($request(['_csrf' => $csrf, 'member_id' => (string) $firstMember, 'enabled' => '1']))->status() === 302, 'Owner can assign limited role');
$check((int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action = 'member.role_assigned'")->fetchColumn() === 1, 'Role assignment is audited');
$check($roles->has($firstMember, 'content.own_draft') && $roles->has($firstMember, 'seo.read'), 'Operator has both allowed capabilities');
$check(!$roles->has($firstMember, 'content.publish') && !$roles->has($firstMember, 'admin.super'), 'Operator cannot gain publishing or administrator capability');
$check(!$roles->has($secondMember, 'seo.read'), 'Other member stays unprivileged');
$denied = false;
try {
    $roles->assignOperator($secondMember, (int) $admin + 1, true);
} catch (InvalidArgumentException) {
    $denied = true;
}
$check($denied, 'Non-owner cannot grant a role through the service');

unset($_SESSION['admin_user']);
$_SESSION['front_user'] = ['id' => $firstMember, 'email' => 'operator@example.invalid', 'display_name' => 'Operator'];
$adminContent = (new AdminController($settings, new FileLogger($root . '/app.log'), $root))
    ->contentStore(new Request('POST', '/admin/content', [], ['_csrf' => $csrf, 'title' => 'Denied']));
$check(($adminContent->headers()['Location'] ?? '') === '/admin/login', 'Member identity cannot enter administrator content route');
$check($controller->workspace()->status() === 200 && $controller->seo()->status() === 200, 'Operator can view draft workspace and read-only SEO');
$check($controller->saveDraft(new Request('POST', '/workspace/drafts', [], ['title' => 'No CSRF']))->status() === 403, 'Draft save requires CSRF');
$check($controller->saveDraft(new Request('POST', '/workspace/drafts/invalid', [], ['_csrf' => $csrf, 'title' => 'Invalid path']))->status() === 404, 'Invalid edit path never creates content');
$check($controller->saveDraft(new Request('POST', '/workspace/drafts', [], ['_csrf' => $csrf, 'title' => 'From form', 'body' => 'Draft body']))->status() === 302, 'Operator can save through the member route');
$contentId = $drafts->create($firstMember, 'Own draft', 'Body text');
$check($drafts->draft($firstMember, $contentId) !== null, 'Operator owns new draft');
$check($drafts->draft($secondMember, $contentId) === null && !$drafts->update($secondMember, $contentId, 'Other edit', 'Body'), 'Other member cannot read or edit draft');
$check($drafts->update($firstMember, $contentId, 'Edited draft', 'Updated body'), 'Operator can edit own draft');
$complexId = $drafts->create($firstMember, 'Owner review', 'Original body');
$complexBlocks = json_encode([['type' => 'paragraph', 'data' => ['text' => 'Original body']], ['type' => 'paragraph', 'data' => ['text' => 'Owner addition']]], JSON_THROW_ON_ERROR);
$pdo->prepare('UPDATE cms_contents SET blocks_json = :blocks WHERE id = :id')->execute([':blocks' => $complexBlocks, ':id' => $complexId]);
$check(!$drafts->update($firstMember, $complexId, 'Overwritten', 'New body'), 'Operator cannot overwrite a draft expanded during Owner review');
$pdo->prepare("UPDATE cms_contents SET status = 'published' WHERE id = :id")->execute([':id' => $contentId]);
$check(!$drafts->update($firstMember, $contentId, 'Changed', 'Body'), 'Published content is locked from operator edits');
$_SESSION['admin_user'] = ['id' => (int) $admin, 'email' => 'owner@example.invalid', 'display_name' => 'Owner'];
$check($controller->assign($request(['_csrf' => $csrf, 'member_id' => (string) $firstMember, 'enabled' => '0']))->status() === 302, 'Owner can revoke role');
$check($controller->workspace()->status() === 403 && $controller->seo()->status() === 403, 'Role revocation takes effect on existing session');
