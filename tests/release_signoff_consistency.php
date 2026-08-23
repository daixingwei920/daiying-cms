<?php

declare(strict_types=1);

define('CMS_SOURCE_ROOT', dirname(__DIR__));

$failures = 0;

function signoff_consistency_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }

    echo '[PASS] ' . $message . PHP_EOL;
}

function read_release_file(string $relative): string
{
    $path = CMS_SOURCE_ROOT . '/' . $relative;
    return is_file($path) ? (string) file_get_contents($path) : '';
}

$readme = read_release_file('README.md');
$audit = read_release_file('CMS_COMPLETION_STATUS_AUDIT_V2.md');
$batch7 = read_release_file('CMS_RELEASE_BATCH7_PRODUCTION_RECOVERY_REPORT.md');
$checklist = read_release_file('CMS_RELEASE_FINAL_SIGNOFF_CHECKLIST.md');
$deploymentChecklist = read_release_file('CMS_RELEASE_ENVIRONMENT_DEPLOYMENT_CHECKLIST.md');
$signoff = read_release_file('CMS_RELEASE_FINAL_SIGNOFF_REPORT.md');
$adminUx = read_release_file('CMS_RELEASE_ADMIN_UX_SMOKE_REPORT.md');
$finalArtifactTest = read_release_file('tests/release_final_artifact_verification.php');
$manifestPath = CMS_SOURCE_ROOT . '/daiying-cms-1.2.0.manifest.json';
$manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;

signoff_consistency_check(str_contains($readme, '1256 counted automated PASS checks'), 'README records the current 1256 PASS total');
signoff_consistency_check(str_contains($audit, 'Total counted automated PASS checks: 1256.') && str_contains($finalArtifactTest, "final_artifact_check(\$zipOpened, 'release package can be opened')"), 'audit V2 records the current 1256 PASS total and final artifact package-open PASS remains counted');
signoff_consistency_check(str_contains($batch7, 'Total automated PASS checks counted: 1167.'), 'Batch 7 production recovery report records the current baseline PASS total');
signoff_consistency_check(str_contains($checklist, 'Current counted automated PASS checks after public read guard, URL Mapping source/repository safety, restore preflight validation, safe failure notices, runtime URL Mapping safety regression coverage, Core public API registry coverage, Plugin risk boundary policy coverage, official Core update server client coverage and Provider storage self-check and content delete preview-token/method guard and Provider write method guard and payment lifecycle method guard and half-built payment plugin Provider field normalization plus legacy is_enabled form compatibility coverage, Core admin MFA runtime coverage, Market AI Review/OpenAI structured adapter coverage, V1.2 developer-mode gate coverage, Market Developer Package Builder, isolated submission, scan queue, freeze/sign/distribute, Review Admin evidence/actions and Developer Center review/sales boundary coverage, diagnostic CLI help coverage and readiness CLI help coverage and scheduled publish CLI help coverage: 1256') && str_contains($checklist, 'Release recovery diagnostics verifier: passed, 33 checks.') && !str_contains($checklist, 'Release recovery diagnostics verifier: passed, 28 checks.'), 'final sign-off checklist records the current 1256 PASS total and recovery diagnostics verifier count');
signoff_consistency_check(str_contains($checklist, 'Package: `daiying-cms-1.2.0.zip`') && !str_contains($checklist, 'daiying-cms-1.2.0-rc1.zip'), 'final sign-off checklist points at the current stable package instead of stale RC artifacts');
signoff_consistency_check(str_contains($signoff, 'Total counted automated PASS checks: 1256.'), 'final sign-off report records the current 1256 PASS total');
signoff_consistency_check(is_array($manifest) && ($manifest['audit_pass_count'] ?? null) === 1256, 'artifact manifest records the current 1256 PASS total');
signoff_consistency_check(!str_contains($checklist, 'after environment deployment checklist content hardening: 818'), 'final sign-off checklist no longer exposes the stale 818 total');
signoff_consistency_check(str_contains($audit, '| Content editor, routing, SEO, transfer import, full export checksum preflight, official content-data restore, URL Mapping source-target safety, repository write guards, preflight validation, safe failure notices, public read guards, V1.2 developer-mode gate and Core public API registry | `php tests/content_editor_seo.php` | 74 |') && str_contains($audit, '| Media security, players and remote image localization | `php tests/media_security_player.php` | 56 |') && str_contains($audit, '| Admin login/logout POST CSRF, localized failure, session, audit and Core MFA runtime security | `php tests/admin_login_security.php` | 28 |') && str_contains($audit, '| Payment Provider persistence, Provider storage self-check, no-id legacy Provider storage repair, Card Delivery manual capture and content delete front URL/sitemap/preview-token/method-guard regression | `php tests/payment_provider_card_delivery_content_delete.php` | 99 |') && str_contains($audit, '| Production deployment readiness, Plugin risk boundary policy, official Core update server client, Provider storage repair, Provider secret ciphertext preflight, Provider runtime service/WAL snapshot probe, Market extension restore-point backup policy, shared RuntimeRequirements PHP extension source, PHP ZipArchive package readiness, SEO indexing launch warning, admin MFA runtime enforcement readiness, readiness CLI help coverage and scheduled publish CLI help coverage | `php tests/production_deployment_readiness.php` | 86 |') && str_contains($audit, '| Admin density static regression | `php tests/admin_density_static.php` | 9 |') && str_contains($audit, '| Market Developer Package Builder, Isolated Submissions, Scan Queue, Freeze/Sign/Distribute, Review Admin Evidence/Actions and Developer Center Review/Sales Boundary | `php tests/market_developer_package_builder.php` | 26 |') && str_contains($audit, '| Market AI Review Orchestrator and OpenAI Structured Review Adapter | `php tests/market_ai_review_orchestrator.php` | 14 |') && !str_contains($audit, '`php tests/run.php`') && str_contains($audit, '| Release sign-off consistency | `php tests/release_signoff_consistency.php` | 15 |'), 'audit V2 lists packaged content import, full export checksum preflight, media localization, admin login security, Provider/Card Delivery content delete regression, production readiness, admin density, Market Developer Package Builder, Market AI Review and sign-off consistency tests without the excluded regression runner');
signoff_consistency_check(str_contains($signoff, 'Release sign-off consistency: 15 PASS'), 'final sign-off report includes the sign-off consistency validation result');
signoff_consistency_check(str_contains($signoff, 'runtime URL Mapping safety, V1.2 developer-mode gate and Core public API registry: 74 PASS'), 'final sign-off report includes runtime URL Mapping safety and Core public API registry result');
signoff_consistency_check(str_contains($signoff, 'Production deployment readiness, Plugin risk boundary policy, official Core update server client, Provider storage repair, Provider secret ciphertext preflight, Provider runtime service/WAL snapshot probe, Market extension restore-point backup policy, shared RuntimeRequirements PHP extension source, PHP ZipArchive package readiness, SEO indexing launch warning and admin MFA runtime enforcement readiness: 86 PASS'), 'final sign-off report includes current production readiness result');
signoff_consistency_check(str_contains($signoff, 'Payment Provider persistence, Provider storage self-check, no-id legacy Provider storage repair, Card Delivery manual capture and content delete front URL/sitemap/preview-token/method-guard regression: 99 PASS'), 'final sign-off report includes Provider/Card Delivery content delete regression result');
signoff_consistency_check(str_contains($adminUx, 'current artifact manifest audit count aligned at 1256') && str_contains($adminUx, 'Targeted Playwright smoke coverage was rerun on 2026-08-23') && str_contains($adminUx, 'Payment Provider persistence/Card Delivery capture') && str_contains($adminUx, 'Full browser regression coverage was also rerun on 2026-08-23') && !str_contains($adminUx, 'Operation not permitted') && !str_contains($adminUx, '1119'), 'admin UX report links forward to the current production SEO, Provider and delivery-manifest readiness count and records targeted/full browser smoke evidence');
signoff_consistency_check(str_contains($readme, 'scripts/verify_release_audit_counts.php --run') && str_contains($readme, 'tests/payment_provider_browser_smoke.js') && str_contains($deploymentChecklist, 'scripts/verify_release_audit_counts.php --run'), 'README and deployment checklist document release audit count verification command and targeted browser smoke commands');

if ($failures > 0) {
    fwrite(STDERR, $failures . " release sign-off consistency checks failed.\n");
    exit(1);
}

echo "Release sign-off consistency tests passed.\n";
