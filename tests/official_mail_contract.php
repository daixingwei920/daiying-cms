<?php

declare(strict_types=1);

use Cms\Core\Http\Request;
use Cms\Core\Plugin\PluginSecretStore;
use Cms\Core\Security\CsrfToken;
use Official\Mail\MailController;
use Official\Mail\MailRepository;
use Official\Mail\MailService;
use Official\Mail\MailTransport;

const CMS_ROOT = __DIR__ . '/..';

require_once CMS_ROOT . '/system/core/Http/Request.php';
require_once CMS_ROOT . '/system/core/Http/Response.php';
require_once CMS_ROOT . '/system/core/Plugin/PluginException.php';
require_once CMS_ROOT . '/system/core/Plugin/PluginSecretStore.php';
require_once CMS_ROOT . '/system/core/Security/CsrfToken.php';
require_once CMS_ROOT . '/system/core/Support/View.php';
require_once CMS_ROOT . '/content/plugins/official.mail/src/MailRepository.php';
require_once CMS_ROOT . '/content/plugins/official.mail/src/SmtpTransport.php';
require_once CMS_ROOT . '/content/plugins/official.mail/src/MailService.php';
require_once CMS_ROOT . '/content/plugins/official.mail/src/MailController.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SERVER['REQUEST_URI'] = '/admin/mail';

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures++;
        fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE cms_plugin_secrets (id INTEGER PRIMARY KEY AUTOINCREMENT, plugin_id TEXT, secret_key TEXT, ciphertext TEXT, created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE UNIQUE INDEX idx_plugin_secrets_plugin_key ON cms_plugin_secrets (plugin_id, secret_key)');
$migration = require CMS_ROOT . '/content/plugins/official.mail/migrations/001_mail_core.php';
$migration['up']($pdo);

$secrets = new PluginSecretStore($pdo, 'official-mail-test-master-key');
$repo = new MailRepository($pdo, $secrets);
$transport = new class implements MailTransport {
    /** @var array<string,mixed> */
    public array $lastSettings = [];
    public string $lastPassword = '';
    /** @var array<string,mixed> */
    public array $lastMessage = [];
    public bool $fail = false;

    public function send(array $settings, string $password, array $message): string
    {
        $this->lastSettings = $settings;
        $this->lastPassword = $password;
        $this->lastMessage = $message;
        if ($this->fail) {
            throw new RuntimeException('SMTP password=SHOULD_NOT_LEAK failed');
        }

        return 'queued-test-1';
    }
};
$service = new MailService($repo, $transport);
$controller = new MailController($repo, $service);

$manifest = json_decode((string) file_get_contents(CMS_ROOT . '/content/plugins/official.mail/plugin.json'), true);
$assert(($manifest['plugin_id'] ?? '') === 'official.mail', 'Manifest uses the official mail plugin ID.');
$assert(($manifest['version'] ?? '') === '0.1.0-alpha.1', 'Manifest version is alpha.1.');
$assert(in_array('mail.manage', $manifest['capabilities'] ?? [], true) && in_array('mail.send', $manifest['capabilities'] ?? [], true), 'Manifest declares mail management and send capabilities.');
$assert(($manifest['table_prefixes'] ?? []) === ['mail_'], 'Manifest restricts table ownership to mail_.');

$repo->saveSettings([
    'status' => 'enabled',
    'host' => 'SMTP.Example.COM',
    'port' => '587',
    'encryption' => 'starttls',
    'auth_mode' => 'login',
    'username' => 'mailer@example.com',
    'password' => 'smtp-secret-password',
    'from_email' => 'noreply@example.com',
    'from_name' => "Daiying\r\nCMS",
    'reply_to' => 'support@example.com',
    'timeout_seconds' => '9',
]);
$settings = $repo->settings();
$assert(($settings['host'] ?? '') === 'smtp.example.com', 'SMTP host is normalized.');
$assert(($settings['from_name'] ?? '') === 'Daiying CMS', 'Header values are cleaned before storage.');
$assert(($settings['password_configured'] ?? false) === true, 'SMTP password is marked as configured.');
$assert(!str_contains(json_encode($settings, JSON_UNESCAPED_SLASHES) ?: '', 'smtp-secret-password'), 'SMTP password is never exposed in settings output.');
$assert($repo->smtpPassword() === 'smtp-secret-password', 'SMTP password can be decrypted server-side through PluginSecretStore.');

$result = $service->send([
    'to_email' => 'buyer@example.com',
    'to_name' => 'Buyer',
    'subject' => 'Order notification',
    'body_text' => 'Your order has been paid.',
]);
$assert($result['status'] === 'sent' && $result['provider_message_id'] === 'queued-test-1', 'Mail service sends through the configured transport.');
$assert($transport->lastPassword === 'smtp-secret-password', 'Transport receives the decrypted password only at send time.');
$messages = $repo->recentMessages();
$assert(($messages[0]['status'] ?? '') === 'sent' && ($messages[0]['recipient_email'] ?? '') === 'buyer@example.com', 'Successful sends are logged.');
$assert(!array_key_exists('body_text', $messages[0]) && !array_key_exists('body_html', $messages[0]), 'Message body content is not exposed through recent logs.');

$transport->fail = true;
try {
    $service->send([
        'to_email' => 'buyer@example.com',
        'subject' => 'Failure notification',
        'body_text' => 'This should fail.',
    ]);
    $assert(false, 'SMTP errors should throw.');
} catch (RuntimeException $exception) {
    $assert(!str_contains($exception->getMessage(), 'SHOULD_NOT_LEAK'), 'SMTP error messages are redacted.');
}
$failed = $repo->recentMessages();
$assert(($failed[0]['status'] ?? '') === 'failed' && !str_contains((string) ($failed[0]['error_message'] ?? ''), 'SHOULD_NOT_LEAK'), 'Failed sends are logged with redacted errors.');

$page = $controller->adminSettings(new Request('GET', '/admin/mail'))->body();
$assert(str_contains($page, '邮件设置') && str_contains($page, '发送测试邮件'), 'Admin settings page renders SMTP and test controls.');
$assert(!str_contains($page, 'smtp-secret-password'), 'Admin settings page does not render SMTP password.');

try {
    $repo->saveSettings(['from_email' => "bad\r\nBcc: victim@example.com"]);
    $assert(false, 'Header injection should be rejected.');
} catch (RuntimeException) {
    $assert(true, 'Header injection is rejected.');
}

if ($failures > 0) {
    fwrite(STDERR, 'official_mail_contract failed: ' . $failures . PHP_EOL);
    exit(1);
}

echo 'official_mail_contract: PASS' . PHP_EOL;
