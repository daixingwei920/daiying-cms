<?php

declare(strict_types=1);

const CMS_ROOT = __DIR__ . '/..';

require_once CMS_ROOT . '/system/core/Bootstrap/autoload.php';
require_once CMS_ROOT . '/content/plugins/official.mail/src/OfficialSmtpMailProvider.php';
require_once CMS_ROOT . '/content/plugins/official.mail/src/GmailSmtpMailProvider.php';
require_once CMS_ROOT . '/content/plugins/official.mail/src/OutlookSmtpMailProvider.php';
require_once CMS_ROOT . '/content/plugins/official.mail/src/MailController.php';

use Cms\Core\Http\Request;
use Cms\Core\Mail\MailProviderInterface;
use Cms\Core\Mail\MailProviderRegistry;
use Official\Mail\GmailSmtpMailProvider;
use Official\Mail\MailController;
use Official\Mail\OfficialSmtpMailProvider;
use Official\Mail\OutlookSmtpMailProvider;

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
$assert(($manifest['version'] ?? '') === '0.1.0-alpha.2', 'Manifest version is alpha.2.');
$assert(($manifest['migrations'] ?? null) === [], 'Provider bridge does not install duplicate mail tables.');
$assert(in_array('mail.provider', $manifest['capabilities'] ?? [], true), 'Manifest declares mail.provider capability.');
$assert(in_array('mail.event', $manifest['capabilities'] ?? [], true), 'Manifest declares mail.event capability.');
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

$gmailConfig = $preset(new GmailSmtpMailProvider(), [
    'smtp_host' => '',
    'smtp_port' => 25,
    'smtp_encryption' => 'none',
    'smtp_username' => '',
    'from_email' => 'sender@gmail.com',
]);
$assert(($gmailConfig['smtp_host'] ?? '') === 'smtp.gmail.com', 'Gmail preset forces the official Gmail SMTP host.');
$assert(($gmailConfig['smtp_port'] ?? 0) === 587, 'Gmail preset forces SMTP port 587.');
$assert(($gmailConfig['smtp_encryption'] ?? '') === 'tls', 'Gmail preset forces STARTTLS mode.');
$assert(($gmailConfig['smtp_username'] ?? '') === 'sender@gmail.com', 'Gmail preset falls back to the from address as username.');

$outlookConfig = $preset(new OutlookSmtpMailProvider(), [
    'smtp_host' => '',
    'smtp_port' => 25,
    'smtp_encryption' => 'none',
    'smtp_username' => '',
    'from_email' => 'sender@outlook.com',
]);
$assert(($outlookConfig['smtp_host'] ?? '') === 'smtp-mail.outlook.com', 'Outlook preset forces the official Outlook SMTP host.');
$assert(($outlookConfig['smtp_port'] ?? 0) === 587, 'Outlook preset forces SMTP port 587.');
$assert(($outlookConfig['smtp_encryption'] ?? '') === 'tls', 'Outlook preset forces STARTTLS mode.');
$assert(($outlookConfig['smtp_username'] ?? '') === 'sender@outlook.com', 'Outlook preset falls back to the from address as username.');

$page = (new MailController())->adminIndex(new Request('GET', '/admin/mail'))->body();
$assert(str_contains($page, '邮件 Provider') && str_contains($page, '/admin/settings/mail'), 'Admin page links to the Core mail settings page.');
$assert(str_contains($page, 'official.mail.gmail') && str_contains($page, 'official.mail.outlook'), 'Admin page lists Gmail and Outlook providers.');
$assert(!str_contains($page, 'name="smtp_password"'), 'Plugin admin page does not expose a duplicate SMTP password field.');

if ($failures > 0) {
    fwrite(STDERR, 'official_mail_contract failed: ' . $failures . PHP_EOL);
    exit(1);
}

echo 'official_mail_contract: PASS' . PHP_EOL;
