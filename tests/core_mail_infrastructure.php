<?php

declare(strict_types=1);

require __DIR__ . '/../system/core/Bootstrap/autoload.php';

use Cms\Core\Config\Settings;
use Cms\Core\Events\EventDispatcher;
use Cms\Core\Mail\MailAddress;
use Cms\Core\Mail\MailAttachment;
use Cms\Core\Mail\MailEventRegistry;
use Cms\Core\Mail\MailMessage;
use Cms\Core\Mail\MailProviderInterface;
use Cms\Core\Mail\MailProviderRegistry;
use Cms\Core\Mail\MailQueueRepository;
use Cms\Core\Mail\MailResult;
use Cms\Core\Mail\MailService;
use Cms\Core\Mail\MailTemplateRepository;
use Cms\Core\Mail\PhpMailProvider;
use Cms\Core\Mail\SiteMailSettingsRepository;
use Cms\Core\Plugin\BlockRegistry;
use Cms\Core\Plugin\PluginContext;
use Cms\Core\Plugin\PluginDataStore;
use Cms\Core\Plugin\PluginException;
use Cms\Core\Plugin\PluginManifest;

$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[PASS] {$message}\n";
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$migration = require __DIR__ . '/../system/migrations/2026_09_08_000002_core_mail_infrastructure.php';
$migration->up($pdo);
$migration->up($pdo);
$pdo->exec('CREATE TABLE cms_core_extension_data (id INTEGER PRIMARY KEY AUTOINCREMENT, extension_id TEXT, data_type TEXT, data_key TEXT, payload TEXT, status TEXT, created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE cms_plugin_data (id INTEGER PRIMARY KEY AUTOINCREMENT, plugin_id TEXT, data_type TEXT, data_key TEXT, payload_json TEXT, created_at TEXT, updated_at TEXT)');

$settings = Settings::fromArray([
    'security' => ['encryption_key' => 'mail-test-master-key-123456'],
    'mail' => ['from' => 'fallback@example.com'],
]);

$repo = new SiteMailSettingsRepository($pdo, 'mail-test-master-key-123456');
$current = $repo->current();
$check($current['enabled'] === false && $current['provider_id'] === 'smtp', 'old sites receive default disabled SMTP mail settings');

$repo->save([
    'enabled' => true,
    'provider_id' => 'smtp',
    'smtp_host' => 'smtp.example.com',
    'smtp_port' => 587,
    'smtp_encryption' => 'tls',
    'smtp_username' => 'mailer@example.com',
    'from_name' => 'Daiying CMS',
    'from_email' => 'no-reply@example.com',
    'reply_to' => 'support@example.com',
    'timeout_seconds' => 12,
    'queue_enabled' => true,
], 'smtp-secret-value', false);
$runtime = $repo->runtimeConfig();
$check(($runtime['smtp_password'] ?? '') === 'smtp-secret-value', 'SMTP password is encrypted and decryptable server-side');
$rawCipher = (string) $pdo->query('SELECT smtp_password_ciphertext FROM cms_core_mail_settings WHERE id = 1')->fetchColumn();
$check($rawCipher !== '' && !str_contains($rawCipher, 'smtp-secret-value'), 'SMTP password is not stored in plaintext');

$repo->save([
    'enabled' => true,
    'provider_id' => 'smtp',
    'smtp_host' => 'smtp.example.com',
    'smtp_port' => 465,
    'smtp_encryption' => 'ssl',
    'smtp_username' => 'mailer@example.com',
    'from_name' => 'Daiying CMS',
    'from_email' => 'no-reply@example.com',
    'reply_to' => 'support@example.com',
    'timeout_seconds' => 12,
    'queue_enabled' => true,
], '', false);
$check(($repo->runtimeConfig()['smtp_password'] ?? '') === 'smtp-secret-value', 'saving non-secret mail fields preserves existing SMTP password');
$masked = $repo->current();
$check(($masked['smtp_password_configured'] ?? false) === true && !str_contains(json_encode($masked, JSON_UNESCAPED_SLASHES) ?: '', 'smtp-secret-value'), 'admin mail settings expose only masked password state');

$injectionBlocked = false;
try {
    new MailAddress("bad@example.com\r\nBcc: victim@example.com");
} catch (Throwable) {
    $injectionBlocked = true;
}
$check($injectionBlocked, 'mail address validation blocks header injection');

$templateRepo = new MailTemplateRepository($pdo);
$templateRepo->register('order.paid', 'Paid {{order_no}}', '<p>{{site_name}} {{amount}}</p>', '{{site_name}} {{amount}}', ['site_name', 'order_no', 'amount']);
$fake = new class implements MailProviderInterface {
    public array $sent = [];
    public function id(): string { return 'unit.mail'; }
    public function label(): string { return 'Unit Mail'; }
    public function apiVersion(): string { return '1.0'; }
    public function capabilities(): array { return ['send', 'test_connection']; }
    public function send(MailMessage $message, array $config): MailResult
    {
        $this->sent[] = [$message, $config];
        return MailResult::success($this->id(), 'unit-message-id');
    }
    public function testConnection(array $config): MailResult
    {
        return MailResult::success($this->id());
    }
};

$repo->save([
    'enabled' => true,
    'provider_id' => 'smtp',
    'smtp_host' => 'smtp.example.com',
    'smtp_port' => 587,
    'smtp_encryption' => 'tls',
    'smtp_username' => 'mailer@example.com',
    'from_name' => 'Daiying CMS',
    'from_email' => '',
    'reply_to' => '',
    'timeout_seconds' => 12,
    'queue_enabled' => true,
], '', false);
$settingsWithoutMailFallback = Settings::fromArray([
    'security' => ['encryption_key' => 'mail-test-master-key-123456'],
]);
$missingFrom = (new MailService($pdo, $settingsWithoutMailFallback, $fake))->sendHtml('user@example.com', 'Hello', '<p>Hi</p>', 'Hi');
$check(!$missingFrom->success && str_contains($missingFrom->error, 'from address'), 'enabled mail without From address fails without crashing');

$repo->save([
    'enabled' => true,
    'provider_id' => 'smtp',
    'smtp_host' => 'smtp.example.com',
    'smtp_port' => 465,
    'smtp_encryption' => 'ssl',
    'smtp_username' => 'mailer@example.com',
    'from_name' => 'Daiying CMS',
    'from_email' => 'no-reply@example.com',
    'reply_to' => 'support@example.com',
    'timeout_seconds' => 12,
    'queue_enabled' => true,
], '', false);

$service = new MailService($pdo, $settings, $fake);
$result = $service->sendHtml('user@example.com', 'Hello', '<p>Hi</p>', 'Hi');
$check($result->success && count($fake->sent) === 1, 'MailService sends HTML through a provider');
$templateResult = $service->sendTemplate('order.paid', 'buyer@example.com', ['site_name' => 'Daiying CMS', 'order_no' => 'NO1', 'amount' => '360']);
$check($templateResult->success && str_contains($fake->sent[1][0]->subject, 'NO1'), 'MailService renders and sends templates');

$phpMailAttachment = (new PhpMailProvider())->send(new MailMessage(
    [new MailAddress('user@example.com')],
    'Attachment',
    '<p>Attachment</p>',
    'Attachment',
    [],
    [],
    null,
    [new MailAttachment('test.txt', 'body', 'text/plain')],
), $repo->runtimeConfig());
$check(!$phpMailAttachment->success && str_contains($phpMailAttachment->error, 'attachments'), 'PHP mail provider reports unsupported attachments clearly');

$queueId = $service->queue(MailMessage::html('queued@example.com', 'Queued', '<p>Queued</p>'), 'system.update');
$check($queueId > 0, 'MailService can enqueue mail');
$processed = $service->processQueue(5);
$row = (new MailQueueRepository($pdo))->find($queueId);
$check($processed['sent'] === 1 && ($row['status'] ?? '') === 'sent', 'MailService processes queued mail without a separate worker');

$manifest = new PluginManifest('local.mailtest', 'Mail Test', '1.0.0', 'Unit', '1.0.0', '8.3.0', 'plugin.php', 'api', ['mail.provider', 'mail.event'], [], [], 'plugin', false, [], [], '');
$context = new PluginContext($manifest, new EventDispatcher(), new BlockRegistry(), new PluginDataStore($pdo, 'local.mailtest'), null, null, null, false, '', null, $service);
$check($context->mail()->capabilities()['api_version'] === '1.0', 'PluginContext exposes stable mail service API');
MailProviderRegistry::clear();
$context->registerMailProvider($fake);
$check(MailProviderRegistry::get('unit.mail') !== null, 'plugins with mail.provider capability can register mail providers');
MailEventRegistry::clear();
$context->registerMailEvent('custom.event', 'Custom Event', ['site_name']);
$check(isset(MailEventRegistry::all()['custom.event']), 'plugins with mail.event capability can register mail events');

$blockedContext = new PluginContext(new PluginManifest('local.nomail', 'No Mail', '1.0.0', 'Unit', '1.0.0', '8.3.0', 'plugin.php', 'api', [], [], [], 'plugin', false, [], [], ''), new EventDispatcher(), new BlockRegistry(), new PluginDataStore($pdo, 'local.nomail'), null, null, null, false, '', null, $service);
$blocked = false;
try {
    $blockedContext->registerMailProvider($fake);
} catch (PluginException) {
    $blocked = true;
}
$check($blocked, 'plugin mail provider registration requires declared capability');

echo "Core mail infrastructure tests PASS\n";
