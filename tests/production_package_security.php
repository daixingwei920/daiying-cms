<?php

declare(strict_types=1);

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/scripts/build_release_package.php';

$failures = 0;

function prod_package_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function prod_package_remove(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir((string) $item->getPathname()) : unlink((string) $item->getPathname());
    }
    rmdir($path);
}

$work = sys_get_temp_dir() . '/cms-prod-package-security-' . bin2hex(random_bytes(4));
prod_package_remove($work);
mkdir($work, 0755, true);
$zipPath = $work . '/release.zip';
$seededPluginState = [
    CMS_SOURCE_ROOT . '/storage/plugin-installs/install.lock' => 'locked',
    CMS_SOURCE_ROOT . '/storage/plugin-installs/uploads/package-seed.zip' => 'zip-bytes',
    CMS_SOURCE_ROOT . '/storage/plugin-installs/staging/tmp/plugin.php' => '<?php echo "temp";',
];
$seededSensitiveState = [
    CMS_SOURCE_ROOT . '/.env.local' => 'DB_PASSWORD=secret',
    CMS_SOURCE_ROOT . '/private.pem' => 'PRIVATE KEY',
    CMS_SOURCE_ROOT . '/database-dump.sql' => 'create table secret(id int);',
    CMS_SOURCE_ROOT . '/site.backup' => 'backup-bytes',
];
$seededPlatformState = [
    CMS_SOURCE_ROOT . '/.DS_Store' => 'macos',
    CMS_SOURCE_ROOT . '/content/themes/default/.DS_Store' => 'theme-macos',
    CMS_SOURCE_ROOT . '/Thumbs.db' => 'windows',
    CMS_SOURCE_ROOT . '/desktop.ini' => 'windows',
    CMS_SOURCE_ROOT . '/__MACOSX/._README.md' => 'resource-fork',
    CMS_SOURCE_ROOT . '/.AppleDouble/._index.php' => 'appledouble',
];
$seededWorkspaceState = [
    CMS_SOURCE_ROOT . '/.github/workflows/release.yml' => 'name: local',
    CMS_SOURCE_ROOT . '/.codex/session.json' => '{"local":true}',
    CMS_SOURCE_ROOT . '/.agents/state.json' => '{"agent":"local"}',
    CMS_SOURCE_ROOT . '/.vscode/settings.json' => '{}',
    CMS_SOURCE_ROOT . '/.idea/workspace.xml' => '<project />',
    CMS_SOURCE_ROOT . '/node_modules/example-package/index.js' => 'module.exports = true;',
    CMS_SOURCE_ROOT . '/vendor/example-package/autoload.php' => '<?php',
];
$seededTestArtifactState = [
    CMS_SOURCE_ROOT . '/.phpunit.result.cache' => '{}',
    CMS_SOURCE_ROOT . '/junit.xml' => '<testsuite />',
    CMS_SOURCE_ROOT . '/clover.xml' => '<coverage />',
    CMS_SOURCE_ROOT . '/coverage/index.html' => '<html>coverage</html>',
    CMS_SOURCE_ROOT . '/coverage/coverage.lcov' => 'TN:',
    CMS_SOURCE_ROOT . '/test-results/browser.json' => '{}',
    CMS_SOURCE_ROOT . '/playwright-report/index.html' => '<html>report</html>',
    CMS_SOURCE_ROOT . '/debug.log' => 'debug',
];
$seededEditorPatchState = [
    CMS_SOURCE_ROOT . '/README.md~' => 'backup',
    CMS_SOURCE_ROOT . '/public/index.php.swp' => 'swap',
    CMS_SOURCE_ROOT . '/system/core/Bootstrap/Application.php.swo' => 'swap',
    CMS_SOURCE_ROOT . '/config/app.php.tmp' => 'tmp',
    CMS_SOURCE_ROOT . '/content/themes/default/theme.json.orig' => 'orig',
    CMS_SOURCE_ROOT . '/content/plugins/faq_block/plugin.php.rej' => 'reject',
    CMS_SOURCE_ROOT . '/local-fix.patch' => 'patch',
    CMS_SOURCE_ROOT . '/local-fix.diff' => 'diff',
];
$seededArchiveState = [
    CMS_SOURCE_ROOT . '/local-backup.tar' => 'tar',
    CMS_SOURCE_ROOT . '/release-copy.tgz' => 'tgz',
    CMS_SOURCE_ROOT . '/database.sql.gz' => 'gz',
    CMS_SOURCE_ROOT . '/snapshot.bz2' => 'bz2',
    CMS_SOURCE_ROOT . '/snapshot.xz' => 'xz',
    CMS_SOURCE_ROOT . '/assets.7z' => '7z',
    CMS_SOURCE_ROOT . '/legacy.rar' => 'rar',
];
$seededToolCacheState = [
    CMS_SOURCE_ROOT . '/.php-cs-fixer.cache' => '{}',
    CMS_SOURCE_ROOT . '/.eslintcache' => '{}',
    CMS_SOURCE_ROOT . '/.phpstan/resultCache.php' => '<?php',
    CMS_SOURCE_ROOT . '/.psalm/cache.json' => '{}',
    CMS_SOURCE_ROOT . '/.parcel-cache/data.bin' => 'cache',
    CMS_SOURCE_ROOT . '/.sass-cache/style.scssc' => 'cache',
    CMS_SOURCE_ROOT . '/.cache/tool/state.json' => '{}',
];
$seededLocalNoteState = [
    CMS_SOURCE_ROOT . '/LOCAL_NOTES.md' => 'local notes',
    CMS_SOURCE_ROOT . '/SCRATCH_RELEASE.txt' => 'scratch',
    CMS_SOURCE_ROOT . '/DRAFT_ACCEPTANCE.md' => 'draft',
    CMS_SOURCE_ROOT . '/TEMP_HANDOFF.txt' => 'temp',
    CMS_SOURCE_ROOT . '/NOTES_PRIVATE.md' => 'private notes',
    CMS_SOURCE_ROOT . '/release.local.md' => 'local',
    CMS_SOURCE_ROOT . '/release.draft.md' => 'draft',
    CMS_SOURCE_ROOT . '/release.scratch.txt' => 'scratch',
];
$seededConfigSecretState = [
    CMS_SOURCE_ROOT . '/config/app.local.php' => '<?php return ["database" => ["password" => "secret"]];',
    CMS_SOURCE_ROOT . '/config/database.local.php' => '<?php return ["password" => "secret"];',
    CMS_SOURCE_ROOT . '/config/secrets.php' => '<?php return ["key" => "secret"];',
    CMS_SOURCE_ROOT . '/config/installed.php' => '<?php return ["installed" => true];',
    CMS_SOURCE_ROOT . '/config/app.private.php' => '<?php return ["private" => true];',
];
$seededServerOverrideState = [
    CMS_SOURCE_ROOT . '/.user.ini' => 'auto_prepend_file=local.php',
    CMS_SOURCE_ROOT . '/php.ini' => 'display_errors=1',
    CMS_SOURCE_ROOT . '/public/.user.ini' => 'auto_prepend_file=public-local.php',
    CMS_SOURCE_ROOT . '/.htpasswd' => 'admin:hash',
    CMS_SOURCE_ROOT . '/.htdigest' => 'admin:realm:hash',
    CMS_SOURCE_ROOT . '/.envrc' => 'export SECRET=1',
    CMS_SOURCE_ROOT . '/Procfile' => 'web: php -S 0.0.0.0:8080',
];
$seededCertificateState = [
    CMS_SOURCE_ROOT . '/local.crt' => 'cert',
    CMS_SOURCE_ROOT . '/local.cer' => 'cert',
    CMS_SOURCE_ROOT . '/local.csr' => 'csr',
    CMS_SOURCE_ROOT . '/local.p12' => 'p12',
    CMS_SOURCE_ROOT . '/local.pfx' => 'pfx',
    CMS_SOURCE_ROOT . '/local.jks' => 'jks',
    CMS_SOURCE_ROOT . '/local.keystore' => 'keystore',
];
foreach ($seededPluginState + $seededSensitiveState + $seededPlatformState + $seededWorkspaceState + $seededTestArtifactState + $seededEditorPatchState + $seededArchiveState + $seededToolCacheState + $seededLocalNoteState + $seededConfigSecretState + $seededServerOverrideState + $seededCertificateState as $path => $contents) {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($path, $contents);
}
cms_build_release_package(CMS_SOURCE_ROOT, $zipPath);

$zip = new ZipArchive();
$zip->open($zipPath);
$names = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $names[] = (string) $zip->getNameIndex($i);
}
$has = static fn (string $name): bool => $zip->locateName($name) !== false;
$any = static fn (string $prefix): bool => array_reduce($names, static fn (bool $found, string $name): bool => $found || str_starts_with($name, $prefix), false);

prod_package_check($has('public/index.php') && $has('system/core/Bootstrap/Application.php'), 'package contains stable public launcher and Core bootstrap');
prod_package_check($has('scripts/publish_scheduled_content.php'), 'package contains scheduled content publish CLI for cron');
prod_package_check($has('config/app.example.php') && $has('config/app.php'), 'package contains installable config files');
$apacheRootGuard = (string) $zip->getFromName('.htaccess');
$nginxRootGuard = (string) $zip->getFromName('nginx-root-security.conf');
prod_package_check($has('.htaccess') && str_contains($apacheRootGuard, 'RewriteRule ^(config|storage|system|tests|scripts|content/plugins|content/themes)') && str_contains($apacheRootGuard, 'Require all denied'), 'package includes Apache project-root exposure guard');
prod_package_check(str_contains($apacheRootGuard, '\\.env') && str_contains($apacheRootGuard, 'pem') && str_contains($apacheRootGuard, 'sql') && str_contains($apacheRootGuard, 'backup'), 'Apache project-root guard denies common secret, database and backup files');
prod_package_check($has('nginx-root-security.conf') && str_contains($nginxRootGuard, 'deny all') && str_contains($nginxRootGuard, 'try_files /public$uri'), 'package includes Nginx project-root exposure guard example');
prod_package_check(str_contains($nginxRootGuard, '\\.env') && str_contains($nginxRootGuard, 'pem') && str_contains($nginxRootGuard, 'sql') && str_contains($nginxRootGuard, 'backup'), 'Nginx project-root guard denies common secret, database and backup files');
$publicApacheGuard = (string) $zip->getFromName('public/.htaccess');
prod_package_check($has('public/.htaccess') && str_contains($publicApacheGuard, 'Options -Indexes'), 'package includes public Apache rewrite and index-denial rules');
prod_package_check(str_contains($publicApacheGuard, '\\.env') && str_contains($publicApacheGuard, '\\..+') && str_contains($publicApacheGuard, 'Require all denied'), 'public Apache guard denies hidden environment files');
prod_package_check(str_contains($publicApacheGuard, 'sqlite') && str_contains($publicApacheGuard, 'pem') && str_contains($publicApacheGuard, 'backup'), 'public Apache guard denies database, key and backup files');
prod_package_check($has('content/uploads/.htaccess') && str_contains((string) $zip->getFromName('content/uploads/.htaccess'), 'Require all denied'), 'package includes Apache upload script execution denial');
prod_package_check($has('content/uploads/upload-security.nginx.conf') && str_contains((string) $zip->getFromName('content/uploads/upload-security.nginx.conf'), 'return 403'), 'package includes Nginx upload script execution denial example');
prod_package_check($has('storage/logs/') && $has('storage/cache/') && $has('storage/tmp/') && $has('storage/database/') && $has('content/uploads/'), 'package preserves required empty runtime directories');
prod_package_check($has('storage/plugin-installs/uploads/') && $has('storage/plugin-installs/staging/'), 'package preserves local plugin ZIP upload and staging directories');
prod_package_check(!$any('storage/logs/app.log') && !$any('storage/logs/test-theme.log'), 'package excludes runtime log files');
prod_package_check(!$any('storage/cache/') || $has('storage/cache/'), 'package excludes cache contents');
prod_package_check(!$any('storage/tmp/') || $has('storage/tmp/'), 'package excludes temp contents');
prod_package_check(!$any('storage/database/') || $has('storage/database/'), 'package excludes database contents');
prod_package_check(!$any('storage/updates/') || $has('storage/updates/incoming/'), 'package excludes update history, releases and incoming packages');
prod_package_check(!$any('storage/recovery/') || $has('storage/recovery/'), 'package excludes restore point archives and recovery runtime data');
prod_package_check(!$any('storage/plugin-installs/install.lock') && !$any('storage/plugin-installs/staging/tmp') && !$any('storage/plugin-installs/uploads/package'), 'package excludes local plugin install runtime state');
prod_package_check(!in_array('storage/plugin-installs/install.lock', $names, true) && !in_array('storage/plugin-installs/uploads/package-seed.zip', $names, true) && !in_array('storage/plugin-installs/staging/tmp/plugin.php', $names, true), 'package excludes seeded local plugin install lock, upload and staging files');
prod_package_check(!in_array('.env.local', $names, true) && !in_array('private.pem', $names, true) && !in_array('database-dump.sql', $names, true) && !in_array('site.backup', $names, true), 'package excludes seeded local environment, key, database dump and backup files');
prod_package_check(!in_array('.DS_Store', $names, true) && !in_array('content/themes/default/.DS_Store', $names, true) && !in_array('Thumbs.db', $names, true) && !in_array('desktop.ini', $names, true) && !in_array('__MACOSX/._README.md', $names, true) && !in_array('.AppleDouble/._index.php', $names, true), 'package excludes seeded macOS and Windows metadata files');
prod_package_check(!in_array('.github/workflows/release.yml', $names, true) && !in_array('.codex/session.json', $names, true) && !in_array('.agents/state.json', $names, true) && !in_array('.vscode/settings.json', $names, true) && !in_array('.idea/workspace.xml', $names, true) && !in_array('node_modules/example-package/index.js', $names, true) && !in_array('vendor/example-package/autoload.php', $names, true), 'package excludes seeded development workspace and dependency directories');
prod_package_check(!array_filter($names, static fn (string $name): bool => str_starts_with($name, 'work/')), 'package excludes local Codex workbench scripts and temporary artifacts');
prod_package_check(!in_array('.phpunit.result.cache', $names, true) && !in_array('junit.xml', $names, true) && !in_array('clover.xml', $names, true) && !in_array('coverage/index.html', $names, true) && !in_array('coverage/coverage.lcov', $names, true) && !in_array('test-results/browser.json', $names, true) && !in_array('playwright-report/index.html', $names, true) && !in_array('debug.log', $names, true), 'package excludes seeded test, coverage, browser report and debug log artifacts');
prod_package_check(!in_array('README.md~', $names, true) && !in_array('public/index.php.swp', $names, true) && !in_array('system/core/Bootstrap/Application.php.swo', $names, true) && !in_array('config/app.php.tmp', $names, true) && !in_array('content/themes/default/theme.json.orig', $names, true) && !in_array('content/plugins/faq_block/plugin.php.rej', $names, true) && !in_array('local-fix.patch', $names, true) && !in_array('local-fix.diff', $names, true), 'package excludes seeded editor, patch and merge residue artifacts');
prod_package_check(!in_array('local-backup.tar', $names, true) && !in_array('release-copy.tgz', $names, true) && !in_array('database.sql.gz', $names, true) && !in_array('snapshot.bz2', $names, true) && !in_array('snapshot.xz', $names, true) && !in_array('assets.7z', $names, true) && !in_array('legacy.rar', $names, true), 'package excludes seeded non-ZIP local archive artifacts');
prod_package_check(!in_array('.php-cs-fixer.cache', $names, true) && !in_array('.eslintcache', $names, true) && !in_array('.phpstan/resultCache.php', $names, true) && !in_array('.psalm/cache.json', $names, true) && !in_array('.parcel-cache/data.bin', $names, true) && !in_array('.sass-cache/style.scssc', $names, true) && !in_array('.cache/tool/state.json', $names, true), 'package excludes seeded local tool cache artifacts');
prod_package_check(!in_array('LOCAL_NOTES.md', $names, true) && !in_array('SCRATCH_RELEASE.txt', $names, true) && !in_array('DRAFT_ACCEPTANCE.md', $names, true) && !in_array('TEMP_HANDOFF.txt', $names, true) && !in_array('NOTES_PRIVATE.md', $names, true) && !in_array('release.local.md', $names, true) && !in_array('release.draft.md', $names, true) && !in_array('release.scratch.txt', $names, true), 'package excludes seeded local temporary note artifacts');
prod_package_check(!in_array('config/app.local.php', $names, true) && !in_array('config/database.local.php', $names, true) && !in_array('config/secrets.php', $names, true) && !in_array('config/installed.php', $names, true) && !in_array('config/app.private.php', $names, true), 'package excludes seeded local config secret and installed-state artifacts');
prod_package_check(!in_array('.user.ini', $names, true) && !in_array('php.ini', $names, true) && !in_array('public/.user.ini', $names, true) && !in_array('.htpasswd', $names, true) && !in_array('.htdigest', $names, true) && !in_array('.envrc', $names, true) && !in_array('Procfile', $names, true), 'package excludes seeded local PHP and Web server override artifacts');
prod_package_check(!in_array('local.crt', $names, true) && !in_array('local.cer', $names, true) && !in_array('local.csr', $names, true) && !in_array('local.p12', $names, true) && !in_array('local.pfx', $names, true) && !in_array('local.jks', $names, true) && !in_array('local.keystore', $names, true), 'package excludes seeded local certificate and keystore artifacts');
prod_package_check(!$any('storage/exports/'), 'package excludes export artifacts');
prod_package_check(!$any('storage/market/') && !$any('storage/market-server/'), 'package excludes market runtime artifacts from CMS release package');
prod_package_check(!$any('content/uploads/202'), 'package excludes uploaded media files while preserving upload directory and security rules');
prod_package_check(!array_filter($names, static fn (string $name): bool => str_ends_with($name, '.zip')), 'package does not include nested ZIP artifacts');
prod_package_check(!array_filter($names, static fn (string $name): bool => preg_match('/^PHASE\d+_REPORT\.md$/', $name) === 1), 'package excludes internal phase history reports');
prod_package_check(!array_filter($names, static fn (string $name): bool => str_starts_with($name, 'docs/adr/')), 'package excludes internal ADR design documents from the first-release artifact');
prod_package_check(!in_array('CMS_COMPLETION_STATUS_AUDIT.md', $names, true) && in_array('CMS_COMPLETION_STATUS_AUDIT_V2.md', $names, true), 'package excludes superseded completion audit and ships only audit V2');
prod_package_check(!array_filter($names, static fn (string $name): bool => preg_match('/^CMS_RELEASE_.*(?:PROGRESS|PRERELEASE).*\.md$/', $name) === 1), 'package excludes intermediate progress and prerelease reports');
prod_package_check(!array_filter($names, static fn (string $name): bool => preg_match('/^CMS_RELEASE_SEEDED_.*\.md$/', $name) === 1), 'package excludes seeded artifact exclusion micro-reports');
prod_package_check(!array_filter($names, static fn (string $name): bool => in_array($name, [
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
], true)) && in_array('CMS_RELEASE_SCOPE_HYGIENE_SUMMARY_REPORT.md', $names, true), 'package consolidates scope hygiene micro-reports into a single summary report');
prod_package_check(!in_array('tests/run.php', $names, true), 'package excludes legacy monolithic regression runner with paused scope coverage');
prod_package_check($any('system/core/MarketServer/') && $any('system/core/Market/'), 'package includes V1.2 market client and market server source');
prod_package_check(in_array('system/migrations/2026_08_12_000006_market_schema.php', $names, true) && in_array('system/migrations/2026_08_12_000007_market_server_schema.php', $names, true) && in_array('system/migrations/2026_08_23_000002_market_ai_review_schema.php', $names, true), 'package includes V1.2 market and AI review migrations');
$packagedConfig = (string) $zip->getFromName('config/app.php') . "\n" . (string) $zip->getFromName('config/app.example.php');
prod_package_check(!str_contains($packagedConfig, 'phase106') && !preg_match('/phase\d+/i', $packagedConfig), 'package config exposes stable release version without phase labels');
prod_package_check(!preg_match('/signing_private_key|payment_secret|payment_api|payment_key|ai_|object_storage_secret|market\.example\.com/i', $packagedConfig), 'package config excludes paused market, AI and payment secret placeholders while allowing Core payment defaults');
$packagedExtensionManifests = (string) $zip->getFromName('content/themes/default/theme.json') . "\n" . (string) $zip->getFromName('content/themes/safe/theme.json') . "\n" . (string) $zip->getFromName('content/plugins/faq_block/plugin.json');
prod_package_check(!preg_match('/phase\d+/i', $packagedExtensionManifests), 'package bundled theme and plugin manifests use stable release compatibility labels');
prod_package_check(!$has('content/plugins/bad_plugin/plugin.json') && !$has('content/plugins/market_demo/plugin.php'), 'package excludes test-only plugin artifacts');
prod_package_check(!$has('content/plugins/official.payment-fixture/plugin.json') && !$has('content/plugins/official.payment-fixture/plugin.php'), 'package excludes retired payment fixture plugin artifacts');

$zip->close();
foreach (array_keys($seededPluginState) as $path) {
    prod_package_remove($path);
}
foreach (array_keys($seededSensitiveState) as $path) {
    prod_package_remove($path);
}
foreach (array_keys($seededPlatformState) as $path) {
    prod_package_remove($path);
}
foreach (array_keys($seededWorkspaceState) as $path) {
    prod_package_remove($path);
}
foreach (array_keys($seededTestArtifactState) as $path) {
    prod_package_remove($path);
}
foreach (array_keys($seededEditorPatchState) as $path) {
    prod_package_remove($path);
}
foreach (array_keys($seededArchiveState) as $path) {
    prod_package_remove($path);
}
foreach (array_keys($seededToolCacheState) as $path) {
    prod_package_remove($path);
}
foreach (array_keys($seededLocalNoteState) as $path) {
    prod_package_remove($path);
}
foreach (array_keys($seededConfigSecretState) as $path) {
    prod_package_remove($path);
}
foreach (array_keys($seededServerOverrideState) as $path) {
    prod_package_remove($path);
}
foreach (array_keys($seededCertificateState) as $path) {
    prod_package_remove($path);
}
prod_package_remove(CMS_SOURCE_ROOT . '/storage/plugin-installs/staging/tmp');
prod_package_remove(CMS_SOURCE_ROOT . '/__MACOSX');
prod_package_remove(CMS_SOURCE_ROOT . '/.AppleDouble');
prod_package_remove(CMS_SOURCE_ROOT . '/.github');
prod_package_remove(CMS_SOURCE_ROOT . '/.codex');
prod_package_remove(CMS_SOURCE_ROOT . '/.agents');
prod_package_remove(CMS_SOURCE_ROOT . '/.vscode');
prod_package_remove(CMS_SOURCE_ROOT . '/.idea');
prod_package_remove(CMS_SOURCE_ROOT . '/node_modules');
prod_package_remove(CMS_SOURCE_ROOT . '/vendor');
prod_package_remove(CMS_SOURCE_ROOT . '/coverage');
prod_package_remove(CMS_SOURCE_ROOT . '/test-results');
prod_package_remove(CMS_SOURCE_ROOT . '/playwright-report');
prod_package_remove(CMS_SOURCE_ROOT . '/.phpstan');
prod_package_remove(CMS_SOURCE_ROOT . '/.psalm');
prod_package_remove(CMS_SOURCE_ROOT . '/.parcel-cache');
prod_package_remove(CMS_SOURCE_ROOT . '/.sass-cache');
prod_package_remove(CMS_SOURCE_ROOT . '/.cache');
prod_package_remove($work);

if ($failures > 0) {
    fwrite(STDERR, $failures . " production package security checks failed.\n");
    exit(1);
}

echo "Production package security tests passed.\n";
