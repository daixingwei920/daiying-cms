<?php

declare(strict_types=1);

define('CMS_SOURCE_ROOT', dirname(__DIR__));

$failures = 0;

function final_artifact_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }

    echo '[PASS] ' . $message . PHP_EOL;
}

function zip_has(ZipArchive $zip, string $name): bool
{
    return $zip->locateName($name) !== false;
}

function zip_any(array $names, callable $predicate): bool
{
    foreach ($names as $name) {
        if ($predicate($name)) {
            return true;
        }
    }

    return false;
}

$zipPath = CMS_SOURCE_ROOT . '/daiying-cms-1.2.0.zip';
final_artifact_check(is_file($zipPath) && filesize($zipPath) > 100000, 'release package exists and is non-empty');
$zipHash = hash_file('sha256', $zipPath);
$shaSidecar = $zipPath . '.sha256';
$manifestSidecar = CMS_SOURCE_ROOT . '/daiying-cms-1.2.0.manifest.json';
final_artifact_check(is_file($shaSidecar) && trim((string) file_get_contents($shaSidecar)) === $zipHash . '  daiying-cms-1.2.0.zip', 'release package SHA-256 sidecar exists and matches');
$artifactManifest = is_file($manifestSidecar) ? json_decode((string) file_get_contents($manifestSidecar), true) : null;
final_artifact_check(is_array($artifactManifest) && ($artifactManifest['package_sha256'] ?? '') === $zipHash && ($artifactManifest['size_bytes'] ?? 0) === filesize($zipPath) && ($artifactManifest['app_version'] ?? '') === '1.2.0', 'release package artifact manifest matches hash, size and app version');
final_artifact_check(is_array($artifactManifest) && ($artifactManifest['audit_pass_count'] ?? 0) === 1256, 'release package artifact manifest records 1256 counted automated PASS checks');

$zip = new ZipArchive();
$zipOpened = $zip->open($zipPath) === true;
final_artifact_check($zipOpened, 'release package can be opened');
if (!$zipOpened) {
    exit(1);
}

$names = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $names[] = (string) $zip->getNameIndex($i);
}

final_artifact_check(zip_has($zip, 'public/index.php'), 'package contains stable public launcher');
final_artifact_check(zip_has($zip, 'scripts/publish_scheduled_content.php'), 'package contains scheduled content publish CLI');
final_artifact_check(zip_has($zip, 'scripts/diagnose_payment_providers.php'), 'package contains Payment Provider diagnostic CLI');
final_artifact_check(zip_has($zip, 'scripts/verify_release_audit_counts.php'), 'package contains release audit count verifier CLI');
final_artifact_check(zip_has($zip, 'system/core/Bootstrap/Application.php'), 'package contains Core application bootstrap');
final_artifact_check(zip_has($zip, 'system/core-manifest.json'), 'package contains Core manifest');
$coreManifest = json_decode((string) $zip->getFromName('system/core-manifest.json'), true);
final_artifact_check(is_array($coreManifest) && $coreManifest !== [], 'package Core manifest is valid JSON with file hashes');
$coreManifestHashesMatch = is_array($coreManifest) && $coreManifest !== [];
if ($coreManifestHashesMatch) {
    foreach ($coreManifest as $corePath => $expectedHash) {
        $entryName = 'system/core/' . (string) $corePath;
        $contents = $zip->getFromName($entryName);
        if (!is_string($expectedHash) || !preg_match('/^[a-f0-9]{64}$/', $expectedHash) || $contents === false || hash('sha256', $contents) !== $expectedHash) {
            $coreManifestHashesMatch = false;
            break;
        }
    }
}
final_artifact_check($coreManifestHashesMatch, 'package Core manifest hashes match packaged Core files');
final_artifact_check(
    zip_has($zip, 'system/core/Payment/PaymentService.php')
    && zip_has($zip, 'system/core/Payment/ManualPaymentProvider.php')
    && zip_has($zip, 'system/core/Payment/PaymentProviderSettingsRepository.php')
    && zip_has($zip, 'system/core/CardDelivery/CardDeliveryService.php')
    && zip_has($zip, 'system/core/CardDelivery/CardDeliveryController.php')
    && zip_has($zip, 'system/migrations/2026_08_20_000001_core_payment_schema.php')
    && zip_has($zip, 'system/migrations/2026_08_22_000001_card_delivery_schema.php'),
    'package contains Core Payment Provider and Card Delivery foundations'
);
final_artifact_check(zip_has($zip, 'config/app.example.php') && zip_has($zip, 'config/app.php'), 'package contains installable configuration files');
final_artifact_check(zip_has($zip, '.htaccess') && zip_has($zip, 'nginx-root-security.conf'), 'package contains project-root web exposure guard files');
final_artifact_check(zip_has($zip, 'README.md'), 'package contains first-release README');
final_artifact_check(zip_has($zip, 'content/themes/default/theme.json') && zip_has($zip, 'content/themes/safe/theme.json'), 'package contains default and safe themes');
final_artifact_check(zip_has($zip, 'content/uploads/.htaccess') && zip_has($zip, 'content/uploads/upload-security.nginx.conf'), 'package contains upload execution-denial server rules');
final_artifact_check(zip_has($zip, 'storage/logs/') && zip_has($zip, 'storage/cache/') && zip_has($zip, 'storage/tmp/') && zip_has($zip, 'storage/database/'), 'package preserves required empty runtime directories');
final_artifact_check(zip_has($zip, 'storage/plugin-installs/uploads/') && zip_has($zip, 'storage/plugin-installs/staging/'), 'package preserves local plugin ZIP install runtime directories');

$requiredReports = [
    'CMS_RELEASE_BATCH1_INSTALL_REPORT.md',
    'CMS_RELEASE_BATCH2_THEME_REPORT.md',
    'CMS_RELEASE_BATCH3_CONTENT_EDITOR_SEO_REPORT.md',
    'CMS_RELEASE_BATCH4_MEDIA_REPORT.md',
    'CMS_RELEASE_BATCH5_PLUGIN_LIFECYCLE_REPORT.md',
    'CMS_RELEASE_BATCH5A_MIGRATION_RECOVERY_REPORT.md',
    'CMS_RELEASE_BATCH6_CORE_UPDATE_REPORT.md',
    'CMS_RELEASE_BATCH7_PRODUCTION_RECOVERY_REPORT.md',
    'CMS_COMPLETION_STATUS_AUDIT_V2.md',
    'CMS_RELEASE_FINAL_SIGNOFF_CHECKLIST.md',
    'CMS_RELEASE_FINAL_SIGNOFF_REPORT.md',
    'CMS_RELEASE_DEPLOYMENT_READINESS_REPORT.md',
    'CMS_RELEASE_INSTALL_SECURE_COOKIES_REPORT.md',
    'CMS_RELEASE_INSTALL_URL_SCHEME_REPORT.md',
    'CMS_RELEASE_CONFIG_SCOPE_REPORT.md',
    'CMS_RELEASE_SCOPE_ROUTE_GATING_REPORT.md',
    'CMS_RELEASE_ARTIFACT_SIDECARS_REPORT.md',
    'CMS_RELEASE_WEBROOT_GUARD_REPORT.md',
    'CMS_RELEASE_PACKAGE_SIDECAR_READINESS_REPORT.md',
    'CMS_RELEASE_PRISTINE_INSTALL_PACKAGE_REPORT.md',
    'CMS_RELEASE_UPDATE_PUBLIC_KEY_READINESS_REPORT.md',
    'CMS_RELEASE_SCHEDULED_PUBLISH_CRON_REPORT.md',
    'CMS_RELEASE_MEDIA_UPLOAD_READINESS_REPORT.md',
    'CMS_RELEASE_MYSQL_BACKUP_TOOL_READINESS_REPORT.md',
    'CMS_RELEASE_ARTIFACT_EXPOSURE_GUARD_READINESS_REPORT.md',
    'CMS_RELEASE_HSTS_SECURITY_HEADERS_REPORT.md',
    'CMS_RELEASE_DIAGNOSTICS_LOG_REDACTION_REPORT.md',
    'CMS_RELEASE_RESTORE_POINT_PATH_SAFETY_REPORT.md',
    'CMS_RELEASE_INSTALL_FILE_PERMISSION_REPORT.md',
    'CMS_RELEASE_INSTALL_ROLLBACK_PERMISSION_REPORT.md',
    'CMS_RELEASE_STATELESS_HEALTH_REPORT.md',
    'CMS_RELEASE_RUNTIME_HEADER_PRIVACY_REPORT.md',
    'CMS_RELEASE_MAINTENANCE_RESPONSE_SECURITY_REPORT.md',
    'CMS_RELEASE_MAINTENANCE_ROUTE_SCOPE_REPORT.md',
    'CMS_RELEASE_RECOVERY_MODE_PERMISSION_REPORT.md',
    'CMS_RELEASE_UPDATE_CONTROL_PERMISSION_REPORT.md',
    'CMS_RELEASE_RESTORE_POINT_PERMISSION_REPORT.md',
    'CMS_RELEASE_RESTORE_POINT_SYMLINK_SAFETY_REPORT.md',
    'CMS_RELEASE_RECOVERY_PUBLIC_ACTION_HARDENING_REPORT.md',
    'CMS_RELEASE_PUBLIC_DIAGNOSTICS_SCOPE_REPORT.md',
    'CMS_RELEASE_ROOT_SENSITIVE_FILE_GUARD_REPORT.md',
    'CMS_RELEASE_PUBLIC_DOCUMENT_ROOT_GUARD_REPORT.md',
    'CMS_RELEASE_PRODUCTION_READINESS_SENSITIVE_GUARD_REPORT.md',
    'CMS_RELEASE_PRODUCTION_READINESS_RECOVERY_DIRECTORIES_REPORT.md',
    'CMS_RELEASE_PRODUCTION_READINESS_INSTALL_LOCK_REPORT.md',
    'CMS_RELEASE_PRODUCTION_READINESS_PLUGIN_INSTALL_DIRECTORIES_REPORT.md',
    'CMS_RELEASE_FRESH_INSTALL_RUNTIME_DIRECTORIES_REPORT.md',
    'CMS_RELEASE_ENVIRONMENT_CHECKLIST_PLUGIN_INSTALL_DIRECTORIES_REPORT.md',
    'CMS_RELEASE_ENVIRONMENT_CHECKLIST_INSTALLED_FILE_PERMISSIONS_REPORT.md',
    'CMS_RELEASE_INSTALL_E2E_RUNTIME_DIRECTORIES_REPORT.md',
    'CMS_RELEASE_PACKAGE_PLUGIN_INSTALL_DIRECTORIES_REPORT.md',
    'CMS_RELEASE_README_PLUGIN_INSTALL_DIRECTORIES_REPORT.md',
    'CMS_RELEASE_SCOPE_HYGIENE_SUMMARY_REPORT.md',
    'CMS_RELEASE_THEME_VISUAL_SMOKE_REPORT.md',
    'CMS_RELEASE_MOBILE_VISUAL_SMOKE_REPORT.md',
    'CMS_RELEASE_ENVIRONMENT_DEPLOYMENT_CHECKLIST.md',
    'CMS_RELEASE_ENVIRONMENT_CHECKLIST_REPORT.md',
    'CMS_RELEASE_ENVIRONMENT_CHECKLIST_TEST_REPORT.md',
    'CMS_RELEASE_ADMIN_UX_SMOKE_REPORT.md',
    'CMS_RELEASE_SIGNOFF_CONSISTENCY_REPORT.md',
];
final_artifact_check(array_reduce($requiredReports, static fn (bool $ok, string $report): bool => $ok && zip_has($zip, $report), true), 'package contains Batch 1-7, audit V2 and final sign-off reports');
final_artifact_check(zip_has($zip, 'CMS_RELEASE_ENVIRONMENT_DEPLOYMENT_CHECKLIST.md'), 'package contains environment deployment checklist');
final_artifact_check(!array_reduce($requiredReports, static fn (bool $found, string $report): bool => $found || str_contains((string) $zip->getFromName($report), 'Generated release evidence placeholder'), false), 'package generated release report summaries use formal wording');

$requiredTests = [
    'tests/install_e2e.php',
    'tests/admin_login_security.php',
    'tests/theme_switching.php',
    'tests/content_editor_seo.php',
    'tests/media_security_player.php',
    'tests/plugin_zip_lifecycle.php',
    'tests/plugin_migration_recovery.php',
    'tests/core_update_lifecycle.php',
    'tests/core_payment_foundation.php',
    'tests/payment_provider_card_delivery_content_delete.php',
    'tests/block_editor_card_delivery.php',
    'tests/admin_density_static.php',
    'tests/market_developer_package_builder.php',
    'tests/release_recovery_diagnostics.php',
    'tests/browser_release_e2e.js',
    'tests/payment_provider_browser_smoke.js',
    'tests/payment_provider_browser_helper.php',
    'tests/admin_density_browser_smoke.js',
    'tests/admin_ux_smoke.js',
    'tests/theme_visual_smoke.js',
    'tests/mysql_release_acceptance.php',
    'tests/production_package_security.php',
    'tests/production_error_redaction.php',
    'tests/production_security_headers.php',
    'tests/production_session_cookie_security.php',
    'tests/production_health_stateless.php',
    'tests/production_runtime_header_privacy.php',
    'tests/production_maintenance_response_security.php',
    'tests/production_recovery_mode_permissions.php',
    'tests/production_update_control_permissions.php',
    'tests/production_restore_point_permissions.php',
    'tests/production_head_response.php',
    'tests/production_method_not_allowed.php',
    'tests/production_deployment_readiness.php',
    'tests/release_documentation_scope.php',
    'tests/release_scope_routes.php',
    'tests/release_artifact_sidecars.php',
    'tests/release_pristine_install_package.php',
    'tests/release_environment_checklist.php',
    'tests/release_signoff_consistency.php',
];
final_artifact_check(array_reduce($requiredTests, static fn (bool $ok, string $test): bool => $ok && zip_has($zip, $test), true), 'package contains release acceptance and production safety tests');
final_artifact_check(zip_has($zip, 'scripts/validate_production_readiness.php'), 'package contains production deployment readiness CLI');
$providerBrowserSmoke = (string) $zip->getFromName('tests/payment_provider_browser_smoke.js');
final_artifact_check(str_contains($providerBrowserSmoke, 'stdout:') && str_contains($providerBrowserSmoke, 'stderr:') && str_contains($providerBrowserSmoke, 'Timed out waiting for PHP server'), 'package Provider browser smoke reports PHP server startup diagnostics');

final_artifact_check(!zip_any($names, static fn (string $name): bool => str_ends_with($name, '.zip')), 'package does not contain nested ZIP artifacts');
final_artifact_check(!zip_any($names, static fn (string $name): bool => !str_contains($name, '/') && str_starts_with($name, '--')), 'package excludes accidental option-like top-level artifacts');
final_artifact_check(!zip_any($names, static fn (string $name): bool => str_starts_with($name, 'storage/logs/') && $name !== 'storage/logs/'), 'package excludes runtime logs');
final_artifact_check(!zip_any($names, static fn (string $name): bool => str_starts_with($name, 'storage/database/') && $name !== 'storage/database/'), 'package excludes runtime databases');
final_artifact_check(!zip_any($names, static fn (string $name): bool => str_starts_with($name, 'storage/recovery/') && $name !== 'storage/recovery/'), 'package excludes recovery archives');
final_artifact_check(!zip_any($names, static fn (string $name): bool => str_starts_with($name, 'storage/exports/')), 'package excludes export artifacts');
final_artifact_check(!zip_any($names, static fn (string $name): bool => str_starts_with($name, 'storage/market/') || str_starts_with($name, 'storage/market-server/')), 'package excludes market runtime artifacts');
final_artifact_check(!zip_any($names, static fn (string $name): bool => str_starts_with($name, 'content/uploads/202')), 'package excludes uploaded media runtime files');
final_artifact_check(!zip_any($names, static fn (string $name): bool => preg_match('/^PHASE\d+_REPORT\.md$/', $name) === 1), 'package excludes internal phase history reports');
final_artifact_check(!zip_any($names, static fn (string $name): bool => str_starts_with($name, 'docs/adr/')), 'package excludes internal ADR design documents from the first-release artifact');
final_artifact_check(!zip_has($zip, 'CMS_COMPLETION_STATUS_AUDIT.md') && zip_has($zip, 'CMS_COMPLETION_STATUS_AUDIT_V2.md'), 'package excludes superseded completion audit and ships only audit V2');
final_artifact_check(!zip_any($names, static fn (string $name): bool => preg_match('/^CMS_RELEASE_.*(?:PROGRESS|PRERELEASE).*\.md$/', $name) === 1), 'package excludes intermediate progress and prerelease reports');
final_artifact_check(!zip_any($names, static fn (string $name): bool => preg_match('/^(?:COMMERCE|CJ)_[^\/]*\.md$/', $name) === 1 || str_starts_with($name, 'cj-production-package-')), 'package excludes paused Commerce/CJ progress reports and external CJ package directories');
final_artifact_check(!zip_any($names, static fn (string $name): bool => preg_match('/^CMS_RELEASE_SEEDED_.*\.md$/', $name) === 1), 'package excludes seeded artifact exclusion micro-reports');
final_artifact_check(!zip_any($names, static fn (string $name): bool => in_array($name, [
    'CMS_RELEASE_LEGACY_TEST_RUNNER_EXCLUSION_REPORT.md',
    'CMS_RELEASE_MARKET_SERVER_SOURCE_EXCLUSION_REPORT.md',
    'CMS_RELEASE_PAUSED_MARKET_MIGRATION_EXCLUSION_REPORT.md',
    'CMS_RELEASE_MARKET_CLIENT_SOURCE_EXCLUSION_REPORT.md',
    'CMS_RELEASE_README_LEGACY_TEST_COMMAND_SCOPE_REPORT.md',
    'CMS_RELEASE_PAUSED_SCOPE_ADR_EXCLUSION_REPORT.md',
    'CMS_RELEASE_INTERNAL_ADR_EXCLUSION_REPORT.md',
    'CMS_RELEASE_SUPERSEDED_AUDIT_EXCLUSION_REPORT.md',
    'CMS_RELEASE_INTERMEDIATE_REPORT_EXCLUSION_REPORT.md',
    'CMS_RELEASE_MICROREPORT_SCOPE_REPORT.md',
], true)) && zip_has($zip, 'CMS_RELEASE_SCOPE_HYGIENE_SUMMARY_REPORT.md'), 'package consolidates scope hygiene micro-reports into a single summary report');
final_artifact_check(!zip_any($names, static fn (string $name): bool => preg_match('/(^|\/)(\.DS_Store|Thumbs\.db|desktop\.ini)(\/|$)/i', $name) === 1 || str_starts_with($name, '__MACOSX/') || str_starts_with($name, '.AppleDouble/')), 'package excludes platform metadata artifacts');
final_artifact_check(!zip_any($names, static fn (string $name): bool => preg_match('/(^|\/)(\.git|\.github|\.codex|\.agents|\.vscode|\.idea|node_modules|vendor|work)(\/|$)/', $name) === 1), 'package excludes development workspace, workbench and dependency directories');
final_artifact_check(!zip_any($names, static fn (string $name): bool => in_array(basename($name), ['.phpunit.result.cache', 'junit.xml', 'clover.xml'], true) || str_ends_with(basename($name), '.lcov') || str_ends_with(basename($name), '.log') || str_starts_with($name, 'coverage/') || str_starts_with($name, 'test-results/') || str_starts_with($name, 'playwright-report/')), 'package excludes test, coverage and browser report artifacts');
final_artifact_check(!zip_any($names, static fn (string $name): bool => str_ends_with(basename($name), '~') || preg_match('/\.(?:swp|swo|tmp|orig|rej|patch|diff)$/i', basename($name)) === 1), 'package excludes editor, patch and merge residue artifacts');
final_artifact_check(!zip_any($names, static fn (string $name): bool => preg_match('/\.(?:tar|tgz|gz|bz2|xz|7z|rar)$/i', basename($name)) === 1), 'package excludes non-ZIP local archive artifacts');
final_artifact_check(!zip_any($names, static fn (string $name): bool => in_array(basename($name), ['.php-cs-fixer.cache', '.eslintcache'], true) || str_starts_with($name, '.phpstan/') || str_starts_with($name, '.psalm/') || str_starts_with($name, '.parcel-cache/') || str_starts_with($name, '.sass-cache/') || str_starts_with($name, '.cache/')), 'package excludes local tool cache artifacts');
final_artifact_check(!zip_any($names, static fn (string $name): bool => !str_contains($name, '/') && (preg_match('/^(?:LOCAL|SCRATCH|DRAFT|TEMP|NOTES)[^\/]*\.(?:md|txt)$/i', basename($name)) === 1 || preg_match('/\.(?:local|draft|scratch)\.(?:md|txt)$/i', basename($name)) === 1)), 'package excludes local temporary note artifacts');
final_artifact_check(!zip_any($names, static fn (string $name): bool => str_starts_with($name, 'config/') && (in_array(basename($name), ['secrets.php', 'installed.php', 'database.php'], true) || preg_match('/\.(?:local|secret|private|installed)\.php$/i', basename($name)) === 1)), 'package excludes local config secret and installed-state artifacts');
final_artifact_check(!zip_any($names, static fn (string $name): bool => in_array(basename($name), ['.user.ini', 'php.ini', '.htpasswd', '.htdigest', '.envrc', 'Procfile'], true)), 'package excludes local PHP and Web server override artifacts');
final_artifact_check(!zip_any($names, static fn (string $name): bool => preg_match('/\.(?:crt|cer|csr|p12|pfx|jks|keystore)$/i', basename($name)) === 1), 'package excludes local certificate and keystore artifacts');
$packagedConfig = (string) $zip->getFromName('config/app.php') . "\n" . (string) $zip->getFromName('config/app.example.php');
final_artifact_check(!preg_match('/phase\d+/i', $packagedConfig), 'package config uses stable release version labels');
final_artifact_check(!preg_match('/signing_private_key|payment_secret|payment_api|payment_key|ai_|object_storage_secret|market\.example\.com/i', $packagedConfig), 'package config excludes paused market, AI and payment secret placeholders while allowing Core payment defaults');
$packagedExtensionManifests = (string) $zip->getFromName('content/themes/default/theme.json') . "\n" . (string) $zip->getFromName('content/themes/safe/theme.json') . "\n" . (string) $zip->getFromName('content/plugins/faq_block/plugin.json');
final_artifact_check(!preg_match('/phase\d+/i', $packagedExtensionManifests), 'package bundled theme and plugin manifests use stable release compatibility labels');
final_artifact_check(!zip_has($zip, 'content/plugins/bad_plugin/plugin.json') && !zip_has($zip, 'content/plugins/market_demo/plugin.php'), 'package excludes test-only plugin artifacts');
final_artifact_check(!zip_has($zip, 'content/plugins/official.payment-fixture/plugin.json') && !zip_has($zip, 'content/plugins/official.payment-fixture/plugin.php'), 'package excludes retired payment fixture plugin artifacts');
final_artifact_check(
    !zip_any($names, static fn (string $name): bool => str_starts_with($name, 'content/plugins/official.commerce/') || str_starts_with($name, 'content/plugins/official.cj-dropshipping/')),
    'package excludes paused Commerce and CJ business plugins from the CMS Core release',
);
$packagedOfficialPlugins = (string) $zip->getFromName('system/official-plugins.php');
final_artifact_check(
    !str_contains($packagedOfficialPlugins, 'official.commerce')
    && !str_contains($packagedOfficialPlugins, 'official.cj-dropshipping')
    && str_contains($packagedOfficialPlugins, 'official.friend-links'),
    'package official plugin registry only declares bundled plugins that are shipped',
);
$packagedAdminUiText = (string) $zip->getFromName('system/core/Support/AdminUiText.php');
final_artifact_check(
    !str_contains($packagedAdminUiText, '/admin/commerce')
    && !str_contains($packagedAdminUiText, '/admin/cj')
    && !str_contains($packagedAdminUiText, 'commerce.view')
    && !str_contains($packagedAdminUiText, 'cj.products.import'),
    'package Core admin text does not hard-code paused Commerce or CJ plugin UI',
);

$audit = (string) $zip->getFromName('CMS_COMPLETION_STATUS_AUDIT_V2.md');
$readme = (string) $zip->getFromName('README.md');
$deploymentChecklist = (string) $zip->getFromName('CMS_RELEASE_ENVIRONMENT_DEPLOYMENT_CHECKLIST.md');
final_artifact_check(str_contains($readme, 'PHP CMS V1.2 First Release') && !str_contains($readme, 'Phase 39'), 'package README is scoped to the first CMS release');
final_artifact_check(str_contains($readme, 'Market Server, Developer Center and AI Review are optional V1.2 platform services') && str_contains($readme, 'not required for a customer CMS site'), 'package README documents optional Market and AI platform scope without making it a customer-site runtime dependency');
final_artifact_check(str_contains($readme, '/admin/payments/providers') && str_contains($readme, 'Core Payment') && str_contains($readme, 'Automatic Card Delivery'), 'package README documents Core Payment Provider and Card Delivery foundations');
final_artifact_check(str_contains($readme, 'Safe content deletion') && str_contains($readme, 'Compact admin backend density'), 'package README documents content deletion and compact admin backend foundations');
final_artifact_check(str_contains($deploymentChecklist, '`core.manual-payment`') && str_contains($deploymentChecklist, '`core.fixture-payment`') && str_contains($deploymentChecklist, 'duplicate capture does not re-deliver'), 'package deployment checklist documents production Provider setup and Card Delivery acceptance');
final_artifact_check(str_contains($readme, 'scripts/verify_release_audit_counts.php --run') && str_contains($deploymentChecklist, 'scripts/verify_release_audit_counts.php --run'), 'package documents release audit count verification command');
final_artifact_check(str_contains($audit, 'Total counted automated PASS checks: 1256.'), 'audit V2 records 1256 counted automated PASS checks');
final_artifact_check(str_contains($audit, 'No currently known first-release CMS main-chain blocker remains'), 'audit V2 records no known first-release main-chain blocker');
final_artifact_check(str_contains($audit, 'Market AI Review Orchestrator') && str_contains($audit, 'feature-gated optional platform services'), 'audit V2 includes Market AI Review while keeping platform services feature-gated');

$zip->close();

$coreUpdateZipPath = dirname(CMS_SOURCE_ROOT, 2) . '/outputs/daiying-cms-core-1.2.1-payment-content-admin-density-20260822.zip';
$coreZip = new ZipArchive();
$coreZipOpened = is_file($coreUpdateZipPath) && $coreZip->open($coreUpdateZipPath) === true;
final_artifact_check($coreZipOpened, 'Core update package can be opened for operational support verification');
if ($coreZipOpened) {
    final_artifact_check(
        zip_has($coreZip, 'README.md')
        && zip_has($coreZip, 'CMS_RELEASE_ENVIRONMENT_DEPLOYMENT_CHECKLIST.md')
        && zip_has($coreZip, 'scripts/diagnose_payment_providers.php')
        && zip_has($coreZip, 'scripts/publish_scheduled_content.php')
        && zip_has($coreZip, 'scripts/validate_production_readiness.php')
        && zip_has($coreZip, 'scripts/verify_release_audit_counts.php'),
        'Core update package contains operational support files for production readiness and Provider diagnostics'
    );
    $coreUpdateManifest = json_decode((string) $coreZip->getFromName('update.json'), true);
    final_artifact_check(
        is_array($coreUpdateManifest)
        && in_array('core_update_operational_support_files', $coreUpdateManifest['features'] ?? [], true)
        && in_array('core_update_package_installs_readiness_diagnostics_and_deployment_checklist', $coreUpdateManifest['acceptance_gates'] ?? [], true),
        'Core update package manifest records operational support file feature and acceptance gate'
    );
    $coreZip->close();
}

if ($failures > 0) {
    fwrite(STDERR, $failures . " final artifact verification checks failed.\n");
    exit(1);
}

echo "Final release artifact verification tests passed.\n";
