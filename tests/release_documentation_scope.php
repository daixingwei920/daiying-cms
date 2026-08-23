<?php

declare(strict_types=1);

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require_once CMS_SOURCE_ROOT . '/scripts/build_release_package.php';

$failures = 0;

function docs_scope_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }

    echo '[PASS] ' . $message . PHP_EOL;
}

$readme = (string) file_get_contents(CMS_SOURCE_ROOT . '/README.md');
$checklist = (string) file_get_contents(CMS_SOURCE_ROOT . '/CMS_RELEASE_FINAL_SIGNOFF_CHECKLIST.md');
$signoff = (string) file_get_contents(CMS_SOURCE_ROOT . '/CMS_RELEASE_FINAL_SIGNOFF_REPORT.md');

docs_scope_check(str_contains($readme, 'PHP CMS V1.2 First Release'), 'README identifies the first-release CMS package');
docs_scope_check(!str_contains($readme, 'Phase 39') && !preg_match('/Phase\\s+\\d+/i', $readme), 'README does not expose stale phase numbering');
docs_scope_check(str_contains($readme, 'not part of this first CMS release scope'), 'README explicitly excludes paused non-CMS scope');
docs_scope_check(str_contains($readme, '/install') && str_contains($readme, '/admin/login'), 'README documents install and admin login entry points');
docs_scope_check(str_contains($readme, 'validate_production_readiness.php --strict'), 'README documents strict production readiness command');
docs_scope_check(str_contains($readme, 'publish_scheduled_content.php') && str_contains($readme, 'cron'), 'README documents scheduled publishing cron command');
docs_scope_check(str_contains($readme, 'upload_max_filesize') && str_contains($readme, 'media.max_file_bytes'), 'README documents PHP media upload runtime sizing');
docs_scope_check(str_contains($readme, 'PHP extensions: `pdo`, `json`, `openssl`, `fileinfo`, `zip`'), 'README required PHP extension list matches Core runtime requirements');
docs_scope_check(str_contains($readme, 'app.secure_cookies') && str_contains($readme, 'HTTPS'), 'README documents HTTPS secure cookie requirement');
docs_scope_check(str_contains($readme, '/admin/payments/providers') && str_contains($readme, 'Core Payment') && str_contains($readme, 'Automatic Card Delivery'), 'README documents Core Payment Provider and Card Delivery foundations');
docs_scope_check(str_contains($readme, 'Safe content deletion') && str_contains($readme, 'Compact admin backend density'), 'README documents content deletion and compact admin backend foundations');
docs_scope_check(str_contains($readme, 'least-privilege MySQL/MariaDB user'), 'README documents least-privilege database user guidance');
docs_scope_check(str_contains($readme, 'content/uploads/.htaccess') && str_contains($readme, 'upload-security.nginx.conf'), 'README documents upload execution-denial rules');
docs_scope_check(str_contains($readme, '`storage/plugin-installs/uploads`') && str_contains($readme, '`storage/plugin-installs/staging`'), 'README documents local plugin ZIP install runtime directories');
$auditPassCount = cms_release_audit_pass_count(CMS_SOURCE_ROOT);
docs_scope_check(is_int($auditPassCount) && str_contains($readme, 'CMS_COMPLETION_STATUS_AUDIT_V2.md') && str_contains($readme, $auditPassCount . ' counted automated PASS checks'), 'README points to audit V2 and current PASS count');
docs_scope_check(str_contains($readme, 'CMS_RELEASE_ENVIRONMENT_DEPLOYMENT_CHECKLIST.md'), 'README points to environment deployment checklist');
docs_scope_check(str_contains($readme, '/recovery') && str_contains($readme, '/diagnostics'), 'README documents recovery and diagnostics entry points');
docs_scope_check(!str_contains($readme . $checklist . $signoff, 'php tests/run.php'), 'release user-facing command docs do not reference the excluded legacy regression runner');

if ($failures > 0) {
    fwrite(STDERR, $failures . " release documentation scope checks failed.\n");
    exit(1);
}

echo "Release documentation scope tests passed.\n";
