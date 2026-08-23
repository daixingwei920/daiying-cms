<?php

declare(strict_types=1);

define('CMS_SOURCE_ROOT', dirname(__DIR__));

$failures = 0;

function env_checklist_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }

    echo '[PASS] ' . $message . PHP_EOL;
}

$path = CMS_SOURCE_ROOT . '/CMS_RELEASE_ENVIRONMENT_DEPLOYMENT_CHECKLIST.md';
$checklist = is_file($path) ? (string) file_get_contents($path) : '';

env_checklist_check($checklist !== '' && str_contains($checklist, 'CMS Release Environment Deployment Checklist'), 'environment deployment checklist exists with release title');
env_checklist_check(str_contains($checklist, 'document root to `public`') && str_contains($checklist, '.htaccess') && str_contains($checklist, 'nginx-root-security.conf'), 'checklist covers public web root and root guard files');
env_checklist_check(str_contains($checklist, '`config`') && str_contains($checklist, '`storage`') && str_contains($checklist, '`system`'), 'checklist covers private project directories not being directly web-readable');
env_checklist_check(str_contains($checklist, '`site.url`') && str_contains($checklist, '`app.secure_cookies`') && str_contains($checklist, '`Secure` on HTTPS'), 'checklist covers HTTPS origin and secure cookie requirements');
env_checklist_check(str_contains($checklist, '`security.encryption_key`') && str_contains($checklist, '`change-me`') && str_contains($checklist, 'Payment Provider secrets'), 'checklist covers production encryption key replacement and secret storage impact');
env_checklist_check(str_contains($checklist, '/admin/payments/providers') && str_contains($checklist, '`core.manual-payment`') && str_contains($checklist, '`core.fixture-payment`'), 'checklist covers production Core Payment Provider setup and fixture exclusion');
env_checklist_check(str_contains($checklist, '修复 Provider 存储') && str_contains($checklist, 'diagnose_payment_providers.php --json --repair') && str_contains($checklist, 'provider_settings.legacy_plugin_storage') && str_contains($checklist, '`enabled`, `is_default`, `config_json`, `public_config`, `name` or `title`'), 'checklist covers production Provider storage repair through Admin UI, legacy plugin field migration and CLI fallback');
env_checklist_check(str_contains($checklist, 'Card Delivery purchase') && str_contains($checklist, 'administrator capture') && str_contains($checklist, 'duplicate capture does not re-deliver'), 'checklist covers Card Delivery payment fulfillment acceptance');
env_checklist_check(str_contains($checklist, 'MySQL/MariaDB') && str_contains($checklist, 'least-privilege non-root CMS user') && str_contains($checklist, 'TLS for remote MySQL/MariaDB'), 'checklist covers MySQL/MariaDB least privilege and remote TLS guidance');
env_checklist_check(str_contains($checklist, '`storage/logs`') && str_contains($checklist, '`storage/updates/incoming`') && str_contains($checklist, '`content/uploads`'), 'checklist covers required writable runtime directories');
env_checklist_check(str_contains($checklist, '`storage/plugin-installs/uploads`') && str_contains($checklist, '`storage/plugin-installs/staging`'), 'checklist covers local plugin ZIP upload and staging runtime directories');
env_checklist_check(str_contains($checklist, '`config/app.php`') && str_contains($checklist, '`storage/installed.lock`') && str_contains($checklist, 'owner-only'), 'checklist covers installed config and lock private file permissions');
env_checklist_check(str_contains($checklist, '`file_uploads`') && str_contains($checklist, '`fileinfo`') && str_contains($checklist, '`upload_max_filesize`') && str_contains($checklist, '`post_max_size`') && str_contains($checklist, '`memory_limit`'), 'checklist covers PHP media upload runtime sizing and Fileinfo MIME detection');
env_checklist_check(str_contains($checklist, 'content/uploads/.htaccess') && str_contains($checklist, 'content/uploads/upload-security.nginx.conf'), 'checklist covers upload execution-denial server rules');
env_checklist_check(str_contains($checklist, 'scripts/publish_scheduled_content.php') && str_contains($checklist, 'cron') && str_contains($checklist, 'safe when run repeatedly'), 'checklist covers scheduled publishing cron and idempotency');
env_checklist_check(str_contains($checklist, '`updates.public_key`') && str_contains($checklist, 'Do not use placeholder PEM') && str_contains($checklist, '`storage/updates/incoming`'), 'checklist covers Core update signing key and incoming update storage');
env_checklist_check(str_contains($checklist, '`/recovery`') && str_contains($checklist, '`/diagnostics`') && str_contains($checklist, '`/admin/diagnostics`') && str_contains($checklist, 'mysqldump'), 'checklist covers Recovery, diagnostics and MySQL/MariaDB restore tooling');
env_checklist_check(str_contains($checklist, 'validate_production_readiness.php --strict') && str_contains($checklist, 'Do not expose the site publicly while blocking errors remain'), 'checklist covers strict production readiness final gate');

if ($failures > 0) {
    fwrite(STDERR, $failures . " environment deployment checklist checks failed.\n");
    exit(1);
}

echo "Environment deployment checklist tests passed.\n";
