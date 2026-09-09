<?php

declare(strict_types=1);

require __DIR__ . '/../system/core/Bootstrap/autoload.php';

use Cms\Core\Events\EventDispatcher;
use Cms\Core\Notification\NotificationService;
use Cms\Core\Plugin\BlockRegistry;
use Cms\Core\Plugin\PluginContext;
use Cms\Core\Plugin\PluginDataStore;
use Cms\Core\Plugin\PluginException;
use Cms\Core\Plugin\PluginManifest;
use Cms\Core\Support\PublicApiRegistry;
use Cms\Core\Support\View;

$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[PASS] {$message}\n";
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$migration = require __DIR__ . '/../system/migrations/2026_09_09_000002_core_notifications.php';
$migration->up($pdo);
$migration->up($pdo);
$pdo->exec('CREATE TABLE cms_plugin_data (id INTEGER PRIMARY KEY AUTOINCREMENT, plugin_id TEXT, data_type TEXT, data_key TEXT, payload_json TEXT, created_at TEXT, updated_at TEXT)');
$check((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'cms_notifications'")->fetchColumn() === 1, 'notification migration creates cms_notifications idempotently');

$service = new NotificationService($pdo);
$id = $service->create('新邮件：测试', 'Authorization: Bearer abc.def.ghi api_key=sk_live_secret', [
    'source_type' => 'mail',
    'source_id' => 'remote-message-1',
    'severity' => 'info',
    'action_url' => '/admin/mail/message?account_id=1&message_id=remote-message-1',
    'dedupe_key' => 'official.mail:1:remote-message-1',
    'payload' => [
        'account_id' => 1,
        'remote_message_id' => 'remote-message-1',
        'sender_email' => 'sender@example.com',
        'received_at' => '2026-09-09T00:00:00Z',
        'subject' => '测试邮件',
        'refresh_token' => 'refresh-token-secret',
    ],
]);
$row = $service->find($id) ?? [];
$payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
$check($id > 0 && $service->unreadCount() === 1, 'notification service creates unread notifications');
$check(($row['action_url'] ?? '') === '/admin/mail/message?account_id=1&message_id=remote-message-1', 'notification action URL allows local admin paths');
$check(!str_contains((string) ($row['body'] ?? ''), 'abc.def.ghi') && !str_contains((string) ($row['body'] ?? ''), 'sk_live_secret'), 'notification body redacts secrets before persistence');
$check(($payload['refresh_token'] ?? '') === '[redacted]' && ($payload['sender_email'] ?? '') === 'sender@example.com', 'notification payload redacts sensitive fields only');

$dedupedId = $service->create('新邮件：测试更新', 'safe body', [
    'source_type' => 'mail',
    'source_id' => 'remote-message-1',
    'severity' => 'warning',
    'action_url' => '/admin/mail/message?account_id=1&message_id=remote-message-1',
    'dedupe_key' => 'official.mail:1:remote-message-1',
]);
$dedupedRow = $service->find($dedupedId) ?? [];
$check($dedupedId === $id && (int) $pdo->query('SELECT COUNT(*) FROM cms_notifications')->fetchColumn() === 1, 'dedupe key updates existing notification instead of creating duplicates');
$check(($dedupedRow['title'] ?? '') === '新邮件：测试更新' && ($dedupedRow['status'] ?? '') === 'unread', 'deduped notification is refreshed as unread');

$blockedExternalUrl = false;
try {
    $service->create('外部链接', '', ['action_url' => 'https://example.com/admin']);
} catch (InvalidArgumentException) {
    $blockedExternalUrl = true;
}
$check($blockedExternalUrl, 'notification action URL rejects external absolute URLs');

$blockedNonAdminPath = false;
try {
    $service->create('前台链接', '', ['action_url' => '/login']);
} catch (InvalidArgumentException) {
    $blockedNonAdminPath = true;
}
$check($blockedNonAdminPath, 'notification action URL requires admin-local paths');

$blockedSecretUrl = false;
try {
    $service->create('密钥链接', '', ['action_url' => '/admin/mail?access_token=abc.def.ghi']);
} catch (InvalidArgumentException) {
    $blockedSecretUrl = true;
}
$check($blockedSecretUrl, 'notification action URL rejects secret-bearing query strings');

$service->markRead($id);
$check(($service->find($id)['status'] ?? '') === 'read' && $service->unreadCount() === 0, 'notifications can be marked read');
$service->create('系统更新失败', '', ['source_type' => 'update', 'severity' => 'error']);
$service->create('支付异常', '', ['source_type' => 'payment', 'severity' => 'warning']);
$check($service->markAllRead() === 2 && $service->unreadCount() === 0, 'mark all read updates unread notifications');
$service->archive($id);
$check(($service->find($id)['status'] ?? '') === 'archived', 'notifications can be archived');
$check(count($service->recent(['limit' => 10])) === 2 && count($service->recent(['include_archived' => true, 'limit' => 10])) === 3, 'recent notifications hide archived entries by default');

$manifest = new PluginManifest('official.mail', 'Official Mail', '0.2.0-alpha.3', 'Daiying CMS', '1.0.0', '8.3.0', 'plugin.php', 'api', [], [], [], 'plugin', false, [], [], '');
$context = new PluginContext(
    $manifest,
    new EventDispatcher(),
    new BlockRegistry(),
    new PluginDataStore($pdo, 'official.mail'),
    null,
    notifications: $service,
);
$pluginNotificationId = $context->notifications()->create('插件通知', '', [
    'source_owner' => 'core',
    'source_type' => 'mail',
    'action_url' => '/admin/mail',
]);
$pluginRow = $service->find($pluginNotificationId) ?? [];
$check(($pluginRow['source_owner'] ?? '') === 'official.mail' && ($pluginRow['source_type'] ?? '') === 'mail', 'PluginContext notifications force plugin source ownership');

$missingServiceBlocked = false;
try {
    (new PluginContext($manifest, new EventDispatcher(), new BlockRegistry(), new PluginDataStore($pdo, 'official.mail'), null))->notifications();
} catch (PluginException) {
    $missingServiceBlocked = true;
}
$check($missingServiceBlocked, 'PluginContext reports missing notification service clearly');

$contract = PublicApiRegistry::contract('notification.service');
$check(($contract['class'] ?? '') === 'Cms\\Core\\Notification\\NotificationService' && ($contract['version'] ?? '') === '1.0', 'notification service is registered as a stable public API');

$_SERVER['REQUEST_URI'] = '/admin/notifications';
$_SESSION = [];
View::setAdminNotificationSummary(12);
$html = View::page('通知中心', '<h1>通知中心</h1><p>通知列表</p>');
$check(str_contains($html, 'admin-notification-badge') && str_contains($html, '通知中心，未读 12 条'), 'admin layout renders notification badge with unread count');

echo "Core notification infrastructure tests PASS\n";
