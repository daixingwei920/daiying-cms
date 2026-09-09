<?php

declare(strict_types=1);

const CMS_ROOT = __DIR__ . '/..';

require_once CMS_ROOT . '/system/core/Bootstrap/autoload.php';
require_once CMS_ROOT . '/content/plugins/official.mail/src/OfficialSmtpMailProvider.php';
require_once CMS_ROOT . '/content/plugins/official.mail/src/GmailSmtpMailProvider.php';
require_once CMS_ROOT . '/content/plugins/official.mail/src/OutlookSmtpMailProvider.php';
require_once CMS_ROOT . '/content/plugins/official.mail/src/MailHttpClient.php';
require_once CMS_ROOT . '/content/plugins/official.mail/src/MailAccountRepository.php';
require_once CMS_ROOT . '/content/plugins/official.mail/src/MailOAuthService.php';
require_once CMS_ROOT . '/content/plugins/official.mail/src/MailboxClientInterface.php';
require_once CMS_ROOT . '/content/plugins/official.mail/src/GmailMailboxClient.php';
require_once CMS_ROOT . '/content/plugins/official.mail/src/OutlookMailboxClient.php';
require_once CMS_ROOT . '/content/plugins/official.mail/src/MailApiClientFactory.php';
require_once CMS_ROOT . '/content/plugins/official.mail/src/MailController.php';

use Cms\Core\Http\Request;
use Cms\Core\Mail\MailProviderInterface;
use Cms\Core\Mail\MailProviderRegistry;
use Cms\Core\Plugin\PluginSecretStore;
use Official\Mail\GmailSmtpMailProvider;
use Official\Mail\MailAccountRepository;
use Official\Mail\MailApiClientFactory;
use Official\Mail\MailController;
use Official\Mail\MailHttpClient;
use Official\Mail\MailOAuthService;
use Official\Mail\OfficialSmtpMailProvider;
use Official\Mail\OutlookSmtpMailProvider;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures++;
        fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
};

$manifest = json_decode((string) file_get_contents(CMS_ROOT . '/content/plugins/official.mail/plugin.json'), true);
$assert(($manifest['plugin_id'] ?? '') === 'official.mail', 'Manifest uses the official mail plugin ID.');
$assert(($manifest['version'] ?? '') === '0.2.0-alpha.1', 'Manifest version is Webmail V1 alpha.1.');
$assert(($manifest['core']['min'] ?? '') === '1.2.32', 'Manifest requires a Core version with Mail API infrastructure.');
$assert(($manifest['migrations'] ?? null) === ['migrations/001_mail_client.php'], 'Manifest declares the mail client migration.');
$assert(in_array('mail.provider', $manifest['capabilities'] ?? [], true), 'Manifest declares mail.provider capability.');
$assert(in_array('mail.event', $manifest['capabilities'] ?? [], true), 'Manifest declares mail.event capability.');
$assert(in_array('mail.read', $manifest['capabilities'] ?? [], true), 'Manifest declares mail.read capability.');
$assert(in_array('mail.oauth', $manifest['capabilities'] ?? [], true), 'Manifest declares mail.oauth capability.');
$assert(in_array('mail', $manifest['capability_namespaces'] ?? [], true), 'Manifest limits mail capabilities to the mail namespace.');

$providers = [
    new OfficialSmtpMailProvider(),
    new GmailSmtpMailProvider(),
    new OutlookSmtpMailProvider(),
];
foreach ($providers as $provider) {
    $assert($provider instanceof MailProviderInterface, $provider->id() . ' implements the Core mail provider interface.');
    $assert($provider->apiVersion() === '1.0', $provider->id() . ' declares the Core mail provider API version.');
    $assert(in_array('send', $provider->capabilities(), true), $provider->id() . ' supports sending mail.');
    $assert(in_array('test_connection', $provider->capabilities(), true), $provider->id() . ' supports connection tests.');
}
MailProviderRegistry::clear();
foreach ($providers as $provider) {
    MailProviderRegistry::register($provider);
}
$registered = MailProviderRegistry::all();
$assert(isset($registered['official.mail.smtp']), 'Official SMTP provider registers with Core registry.');
$assert(isset($registered['official.mail.gmail']), 'Gmail SMTP provider registers with Core registry.');
$assert(isset($registered['official.mail.outlook']), 'Outlook SMTP provider registers with Core registry.');

$preset = static function (object $provider, array $config): array {
    $method = new ReflectionMethod($provider, 'presetConfig');

    return $method->invoke($provider, $config);
};
$gmailConfig = $preset(new GmailSmtpMailProvider(), ['smtp_host' => '', 'smtp_port' => 25, 'smtp_encryption' => 'none', 'smtp_username' => '', 'from_email' => 'sender@gmail.com']);
$assert(($gmailConfig['smtp_host'] ?? '') === 'smtp.gmail.com', 'Gmail preset forces the official Gmail SMTP host.');
$assert(($gmailConfig['smtp_port'] ?? 0) === 587, 'Gmail preset forces SMTP port 587.');
$assert(($gmailConfig['smtp_encryption'] ?? '') === 'tls', 'Gmail preset forces STARTTLS mode.');
$assert(($gmailConfig['smtp_username'] ?? '') === 'sender@gmail.com', 'Gmail preset falls back to the from address as username.');
$outlookConfig = $preset(new OutlookSmtpMailProvider(), ['smtp_host' => '', 'smtp_port' => 25, 'smtp_encryption' => 'none', 'smtp_username' => '', 'from_email' => 'sender@outlook.com']);
$assert(($outlookConfig['smtp_host'] ?? '') === 'smtp-mail.outlook.com', 'Outlook preset forces the official Outlook SMTP host.');
$assert(($outlookConfig['smtp_port'] ?? 0) === 587, 'Outlook preset forces SMTP port 587.');
$assert(($outlookConfig['smtp_encryption'] ?? '') === 'tls', 'Outlook preset forces STARTTLS mode.');
$assert(($outlookConfig['smtp_username'] ?? '') === 'sender@outlook.com', 'Outlook preset falls back to the from address as username.');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE cms_plugin_secrets (id INTEGER PRIMARY KEY AUTOINCREMENT, plugin_id TEXT, secret_key TEXT, ciphertext TEXT, created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE UNIQUE INDEX idx_plugin_secrets_plugin_key ON cms_plugin_secrets (plugin_id, secret_key)');
$migration = require CMS_ROOT . '/content/plugins/official.mail/migrations/001_mail_client.php';
$migration['up']($pdo);
$secrets = new PluginSecretStore($pdo, 'official-mail-v1-test-key');
$repo = new MailAccountRepository($pdo, $secrets);

$repo->saveOAuthConfig('gmail', [
    'status' => 'enabled',
    'client_id' => 'gmail-client-id.apps.googleusercontent.com',
    'client_secret' => 'gmail-client-secret',
    'redirect_uri' => 'https://www.daiyingcms.com/admin/mail/oauth/callback?provider=gmail',
]);
$gmailOAuth = $repo->oauthConfig('gmail');
$assert(($gmailOAuth['status'] ?? '') === 'enabled', 'Gmail OAuth config can be saved.');
$assert(!str_contains(json_encode($gmailOAuth, JSON_UNESCAPED_SLASHES) ?: '', 'gmail-client-secret'), 'OAuth client secret is not exposed in config output.');
$assert($repo->clientSecret('gmail') === 'gmail-client-secret', 'OAuth client secret decrypts server-side only.');
$assert(MailOAuthService::defaultScopes('gmail') === ['openid', 'email', 'profile', 'https://www.googleapis.com/auth/gmail.modify', 'https://www.googleapis.com/auth/gmail.send'], 'Gmail scopes cover profile, send, read, modify.');
$assert(in_array('Mail.ReadWrite', MailOAuthService::defaultScopes('outlook'), true) && in_array('Mail.Send', MailOAuthService::defaultScopes('outlook'), true) && in_array('offline_access', MailOAuthService::defaultScopes('outlook'), true), 'Outlook scopes cover offline access, read/write, and send.');

$account = $repo->upsertAccount('gmail', ['email' => 'seller@example.com', 'display_name' => 'Seller'], MailOAuthService::defaultScopes('gmail'), 'access-token-secret', 'refresh-token-secret', 3600);
$assert(($account['email'] ?? '') === 'seller@example.com', 'OAuth account can be created from provider profile.');
$assert($repo->accessToken($account) === 'access-token-secret' && $repo->refreshToken($account) === 'refresh-token-secret', 'Access and refresh tokens decrypt server-side.');
$stmt = $pdo->query('SELECT ciphertext FROM cms_plugin_secrets');
$secretRows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_COLUMN);
$assert(!str_contains(implode("\n", array_map('strval', $secretRows)), 'access-token-secret'), 'Access token is encrypted at rest.');
$assert(!str_contains(implode("\n", array_map('strval', $secretRows)), 'refresh-token-secret'), 'Refresh token is encrypted at rest.');

$repo->cacheMessages((int) $account['id'], [[
    'id' => 'msg-1',
    'thread_id' => 'thread-1',
    'folder' => 'inbox',
    'sender_name' => 'Buyer',
    'sender_email' => 'buyer@example.com',
    'subject' => 'Order question',
    'snippet' => 'Can you ship tomorrow?',
    'received_at' => gmdate('c'),
    'is_read' => false,
    'has_attachments' => true,
]]);
$cached = $repo->cachedMessages((int) $account['id'], 'Order', 10);
$assert(count($cached) === 1 && ($cached[0]['remote_id'] ?? '') === 'msg-1', 'Inbox metadata is cached and searchable without storing full mailbox history.');

$http = new MailHttpClient();
foreach ([
    'https://gmail.googleapis.com/gmail/v1/users/me/messages',
    'https://graph.microsoft.com/v1.0/me/messages',
    'https://oauth2.googleapis.com/token',
    'https://login.microsoftonline.com/common/oauth2/v2.0/token',
] as $url) {
    $http->assertAllowedUrl($url);
}
$blocked = false;
try {
    $http->assertAllowedUrl('https://gmail.googleapis.com.evil.test/token');
} catch (RuntimeException) {
    $blocked = true;
}
$assert($blocked, 'Mail HTTP client rejects lookalike API hosts.');

$_SERVER['REQUEST_URI'] = '/admin/mail';
$controller = new MailController($repo, new MailOAuthService($repo, $http), new MailApiClientFactory($repo, new MailOAuthService($repo, $http), $http), null);
$page = $controller->adminIndex(new Request('GET', '/admin/mail', [], [], ['HTTPS' => 'on', 'HTTP_HOST' => 'www.daiyingcms.com']))->body();
$assert(str_contains($page, '邮件中心') && str_contains($page, 'GMAIL') && str_contains($page, 'OUTLOOK'), 'Admin page renders Gmail and Outlook OAuth management.');
$assert(str_contains($page, '/admin/settings/mail'), 'Admin page links to Core mail settings.');
$assert(!str_contains($page, 'gmail-client-secret') && !str_contains($page, 'access-token-secret') && !str_contains($page, 'refresh-token-secret'), 'Admin page does not render OAuth secrets or account tokens.');

if ($failures > 0) {
    fwrite(STDERR, 'official_mail_contract failed: ' . $failures . PHP_EOL);
    exit(1);
}

echo 'official_mail_contract: PASS' . PHP_EOL;
