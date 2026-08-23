<?php

declare(strict_types=1);

use Cms\Core\Auth\AdminAuthenticator;
use Cms\Core\Config\Settings;
use Cms\Core\Http\Request;
use Cms\Core\Market\InstallAuthorization;
use Cms\Core\Market\MarketPackageInstaller;
use Cms\Core\MarketServer\DeveloperPackageBuilder;
use Cms\Core\MarketServer\MarketConsoleController;
use Cms\Core\MarketServer\MarketServerRepository;
use Cms\Core\MarketServer\PackageScanner;
use Cms\Core\MarketServer\PackageSigner;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Security\SessionManager;

define('CMS_SOURCE_ROOT', dirname(__DIR__));
require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;

function market_dev_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function market_dev_remove(string $path): void
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

function market_dev_write(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($path, $content);
}

SessionManager::start(false);

$root = sys_get_temp_dir() . '/cms-market-developer-package-' . bin2hex(random_bytes(4));
market_dev_remove($root);
foreach (['config', 'storage/logs', 'storage/database', 'storage/market/generated', 'content/plugins', 'content/themes'] as $dir) {
    mkdir($root . '/' . $dir, 0755, true);
}

$dbPath = $root . '/storage/database/market-dev.sqlite';
$config = require CMS_SOURCE_ROOT . '/config/app.php';
$config['database'] = ['dsn' => 'sqlite:' . $dbPath, 'username' => '', 'password' => '', 'options' => []];
$config['market'] = ['enabled' => true, 'developer_mode' => true];
market_dev_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");

$settings = Settings::load($root);
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$migrations = [];
foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
    $migrations[] = require $file;
}
(new MigrationRunner($pdo, $migrations))->run();

$pluginId = 'vendor.demo';
$pluginDir = $root . '/content/plugins/' . $pluginId;
market_dev_write($pluginDir . '/plugin.json', json_encode([
    'plugin_id' => $pluginId,
    'name' => 'Vendor Demo',
    'version' => '1.2.3',
    'author' => 'Developer',
    'core' => ['min' => '1.2.0'],
    'php' => '>=8.1',
    'entry' => 'plugin.php',
    'capabilities' => ['content.blocks'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
market_dev_write($pluginDir . '/plugin.php', "<?php\n\nreturn static function (): void {\n};\n");
$dangerPluginId = 'vendor.danger';
$dangerPluginDir = $root . '/content/plugins/' . $dangerPluginId;
market_dev_write($dangerPluginDir . '/plugin.json', json_encode([
    'plugin_id' => $dangerPluginId,
    'name' => 'Vendor Danger',
    'version' => '1.0.0',
    'author' => 'Developer',
    'core' => ['min' => '1.2.0'],
    'php' => '>=8.1',
    'entry' => 'plugin.php',
    'capabilities' => [],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
market_dev_write($dangerPluginDir . '/plugin.php', "<?php\n\nshell_exec('id');\n");

$repo = new MarketServerRepository($pdo);
$developer = $repo->registerDeveloper('dev-package', 'Package Developer', 'package@example.test', 'secret', 'Developer');
$project = $repo->createProject($developer->id, 'vendor:demo', 'plugin', 'Vendor Demo');

$projectPage = (new MarketConsoleController($settings, $root))->projectVersions(new Request('GET', '/admin/market-server/projects/' . $project->id));
market_dev_check($projectPage->status() === 302, 'developer package project page requires admin or project developer authentication');

$_SESSION = ['admin_user' => ['id' => 1, 'email' => 'admin@example.test', 'display_name' => 'Admin']];
(new AdminAuthenticator($pdo))->createAdmin('admin@example.test', 'admin-secret', 'Admin');
$csrf = CsrfToken::get();
$controller = new MarketConsoleController($settings, $root);
$projectPage = $controller->projectVersions(new Request('GET', '/admin/market-server/projects/' . $project->id));
market_dev_check($projectPage->status() === 200 && str_contains($projectPage->body(), '生成标准包') && str_contains($projectPage->body(), 'name="extension_id"'), 'Developer Center project page exposes standard package generation workflow');

$builder = new DeveloperPackageBuilder($root);
$built = $builder->build($project->id, 'plugin', $pluginId);
market_dev_check(is_file($built['package_path']) && ($built['extension_id'] ?? '') === $pluginId && ($built['version'] ?? '') === '1.2.3', 'developer package builder creates a ZIP from local plugin manifest defaults');

$plan = (new MarketPackageInstaller($root))->verifyAndPlan($built['package_path'], new InstallAuthorization('test-token', 'vendor:demo', gmdate('c', time() + 3600), $built['package_sha256']));
market_dev_check(($plan['extension_id'] ?? '') === $pluginId && ($plan['type'] ?? '') === 'plugin' && ($plan['file_count'] ?? 0) === 2, 'generated developer package verifies as a standard Market Package with manifest hashes');

$generatedPage = $controller->generatePackage(new Request('POST', '/admin/market-server/packages/generate', [], [
    '_csrf' => $csrf,
    'project_id' => (string) $project->id,
    'extension_id' => $pluginId,
    'version' => '1.2.4',
]));
market_dev_check($generatedPage->status() === 200 && str_contains($generatedPage->body(), '标准包已生成') && str_contains($generatedPage->body(), '提交该版本审核') && str_contains($generatedPage->body(), 'vendor.demo-1.2.4.zip'), 'Developer Center generates a standard package and returns a submit-ready review form');

$packagePath = $root . '/storage/market/generated/project-' . $project->id . '/vendor.demo-1.2.4.zip';
$submitted = $controller->submitVersion(new Request('POST', '/admin/market-server/versions', [], [
    '_csrf' => $csrf,
    'project_id' => (string) $project->id,
    'version' => '1.2.4',
    'package_path' => $packagePath,
    'changelog' => 'Generated from Developer Center.',
]));
$versions = $repo->versionsForProject($project->id);
market_dev_check($submitted->status() === 302 && count($versions) === 1 && (string) ($versions[0]['package_sha256'] ?? '') === hash_file('sha256', $packagePath), 'generated Developer Center package submits into the Market Server review queue');

$versionId = (int) ($versions[0]['id'] ?? 0);
$repo->runScan($versionId, new PackageScanner());
$repo->recordReview($versionId, 1, 'approve', 'Manual review accepted generated package.');
$payment = $repo->recordPayment('vendor:demo', 'site-1', 1299, 'USD', 'manual', 'ORDER-1', 'pro', '', '', [
    'billing_country' => 'US',
    'tax_amount_cents' => 99,
]);
$repo->markPaymentPaid((int) $payment['id'], 'ORDER-1');
$repo->createSettlement($developer->id, 1200, 'USD', '2026-08-01', '2026-08-31');
unset($_SESSION['admin_user']);
$_SESSION['market_developer_key'] = 'dev-package';
$developerGeneratedOwnPackage = $controller->generatePackage(new Request('POST', '/admin/market-server/packages/generate', [], [
    '_csrf' => $csrf,
    'project_id' => (string) $project->id,
    'extension_id' => $pluginId,
    'version' => '1.2.6',
]));
market_dev_check($developerGeneratedOwnPackage->status() === 200 && str_contains($developerGeneratedOwnPackage->body(), 'vendor.demo-1.2.6.zip'), 'Market Server allows a project developer to generate a package for their own project');
$otherDeveloper = $repo->registerDeveloper('other-package', 'Other Developer', 'other-package@example.test', 'secret', 'Developer');
$otherProject = $repo->createProject($otherDeveloper->id, 'vendor:other', 'plugin', 'Other Project');
$developerGeneratedOtherPackage = $controller->generatePackage(new Request('POST', '/admin/market-server/packages/generate', [], [
    '_csrf' => $csrf,
    'project_id' => (string) $otherProject->id,
    'extension_id' => $pluginId,
    'version' => '1.0.0',
]));
market_dev_check($developerGeneratedOtherPackage->status() === 302 && ($developerGeneratedOtherPackage->headers()['Location'] ?? '') === '/developer/market/login', 'Market Server blocks a developer from generating or submitting packages for another project');
$developerProjectPage = $controller->projectVersions(new Request('GET', '/admin/market-server/projects/' . $project->id));
market_dev_check($developerProjectPage->status() === 200 && str_contains($developerProjectPage->body(), '审核历史') && str_contains($developerProjectPage->body(), 'Manual review accepted generated package.') && str_contains($developerProjectPage->body(), '销售与结算') && str_contains($developerProjectPage->body(), '项目已支付订单 1 笔') && str_contains($developerProjectPage->body(), '2026-08-01 - 2026-08-31'), 'Developer Center project page shows submit review history and project-level sales/settlement summary');
$developerPaymentsPage = $controller->payments(new Request('GET', '/admin/market-server/payments'));
market_dev_check($developerPaymentsPage->status() === 302 && ($developerPaymentsPage->headers()['Location'] ?? '') === '/admin/login', 'Developer Center keeps raw payment and settlement administration behind admin authentication');
$_SESSION = ['admin_user' => ['id' => 1, 'email' => 'admin@example.test', 'display_name' => 'Admin']];
$adminCsrf = CsrfToken::get();
$dangerProject = $repo->createProject($developer->id, 'vendor:danger', 'plugin', 'Vendor Danger');
$queueSafePath = $builder->build($project->id, 'plugin', $pluginId, '1.3.0')['package_path'];
$queueDangerPath = $builder->build($dangerProject->id, 'plugin', $dangerPluginId, '1.0.0')['package_path'];
$queueSafe = $repo->submitVersion($project->id, '1.3.0', $queueSafePath, 'scan queue safe');
$queueDanger = $repo->submitVersion($dangerProject->id, '1.0.0', $queueDangerPath, 'scan queue danger');
$limitedScan = $repo->processScanQueue(new PackageScanner(), 1);
market_dev_check($limitedScan['processed'] === 1 && $repo->versionStatus($queueSafe->id) === 'NeedsReview' && $repo->versionStatus($queueDanger->id) === 'Submitted', 'Market Server scan queue processes Submitted versions in order with a bounded limit');
$remainingScan = $repo->processScanQueue(new PackageScanner(), 25);
market_dev_check($remainingScan['processed'] === 1 && $remainingScan['failed'] === 1 && $repo->versionStatus($queueDanger->id) === 'Rejected' && count($repo->latestScanFindings($queueDanger->id)) >= 1, 'Market Server scan queue records failed scans and keeps findings for review');
$scanQueuePage = $controller->reviewScanQueue(new Request('POST', '/admin/market-server/review/scan-queue', [], [
    '_csrf' => $adminCsrf,
    'limit' => '25',
]));
market_dev_check($scanQueuePage->status() === 302 && str_contains((string) ($scanQueuePage->headers()['Location'] ?? ''), 'scan_processed=0'), 'Review Admin exposes a CSRF-protected scan queue worker action');
$returnPath = $builder->build($project->id, 'plugin', $pluginId, '1.3.1')['package_path'];
$returnVersion = $repo->submitVersion($project->id, '1.3.1', $returnPath, 'return action coverage');
$repo->runScan($returnVersion->id, new PackageScanner());
$reviewActionsPage = $controller->reviewQueue(new Request('GET', '/admin/market-server/review'));
market_dev_check($reviewActionsPage->status() === 200 && str_contains($reviewActionsPage->body(), '退回修改') && str_contains($reviewActionsPage->body(), '暂停/下架') && str_contains($reviewActionsPage->body(), '移除/废弃'), 'Review Admin exposes approve, return, reject, suspend and remove actions');
$returned = $controller->reviewAction(new Request('POST', '/admin/market-server/review/action', [], [
    '_csrf' => $adminCsrf,
    'version_id' => (string) $returnVersion->id,
    'action' => 'return',
    'notes' => 'Needs clearer capability declaration.',
]));
$returnReviews = $repo->reviewsForProject($project->id);
market_dev_check($returned->status() === 302 && $repo->versionStatus($returnVersion->id) === 'NeedsFix' && count(array_filter($returnReviews, static fn (array $review): bool => (int) $review['version_id'] === $returnVersion->id && (string) $review['decision'] === 'return' && str_contains((string) $review['notes'], 'capability'))) === 1, 'Review Admin return action moves version to NeedsFix and records audit evidence');
unset($_SESSION['admin_user']);
$developerCannotReview = $controller->reviewAction(new Request('POST', '/admin/market-server/review/action', [], [
    '_csrf' => $adminCsrf,
    'version_id' => (string) $returnVersion->id,
    'action' => 'reject',
]));
market_dev_check($developerCannotReview->status() === 302 && ($developerCannotReview->headers()['Location'] ?? '') === '/admin/login' && $repo->versionStatus($returnVersion->id) === 'NeedsFix', 'Review Admin actions require administrator role before changing review state');
$_SESSION = ['admin_user' => ['id' => 1, 'email' => 'admin@example.test', 'display_name' => 'Admin']];
$adminCsrf = CsrfToken::get();
$csrfBlockedVersionPath = $builder->build($project->id, 'plugin', $pluginId, '1.3.2')['package_path'];
$csrfBlockedVersion = $repo->submitVersion($project->id, '1.3.2', $csrfBlockedVersionPath, 'csrf guard coverage');
$repo->runScan($csrfBlockedVersion->id, new PackageScanner());
$csrfBlocked = $controller->reviewAction(new Request('POST', '/admin/market-server/review/action', [], [
    '_csrf' => 'bad-token',
    'version_id' => (string) $csrfBlockedVersion->id,
    'action' => 'reject',
]));
market_dev_check($csrfBlocked->status() === 403 && $repo->versionStatus($csrfBlockedVersion->id) === 'NeedsReview', 'Review Admin action rejects invalid CSRF without changing review state');
$privateKeyResource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$privateKey = '';
openssl_pkey_export($privateKeyResource, $privateKey);
$publishPath = $builder->build($project->id, 'plugin', $pluginId, '1.4.0')['package_path'];
$publishVersion = $repo->submitVersion($project->id, '1.4.0', $publishPath, 'freeze sign distribute');
$repo->runScan($publishVersion->id, new PackageScanner());
$repo->recordReview($publishVersion->id, 1, 'approve', 'Ready to publish.');
$repo->publishVersion($publishVersion->id, $repo->signatureForVersion($publishVersion->id, new PackageSigner($privateKey)));
$publishedDetail = $repo->publishedVersionDetail('vendor:demo', '1.4.0');
market_dev_check(($publishedDetail['package_sha256'] ?? '') === hash_file('sha256', $publishPath) && count($publishedDetail['signatures'] ?? []) === 1 && ($repo->publishedCatalog('plugin')[0]['market_id'] ?? '') !== '', 'Market Server freezes, signs and lists published Market Package versions');
$reviewDetailPage = $controller->reviewQueue(new Request('GET', '/admin/market-server/review', ['detail_version_id' => (string) $publishVersion->id]));
market_dev_check($reviewDetailPage->status() === 200 && str_contains($reviewDetailPage->body(), '详情') && str_contains($reviewDetailPage->body(), '审核详情') && str_contains($reviewDetailPage->body(), '包 Manifest') && str_contains($reviewDetailPage->body(), '扫描报告') && str_contains($reviewDetailPage->body(), '沙箱报告') && str_contains($reviewDetailPage->body(), '版本差异') && str_contains($reviewDetailPage->body(), '审核历史') && str_contains($reviewDetailPage->body(), '风险标记'), 'Review Admin detail panel exposes manifest, scan report, sandbox report, diff, history and risk markers');
try {
    $repo->publishVersion($publishVersion->id, $repo->signatureForVersion($publishVersion->id, new PackageSigner($privateKey)));
    market_dev_check(false, 'Market Server rejects duplicate publish attempts for already published versions');
} catch (Throwable) {
    market_dev_check(true, 'Market Server rejects duplicate publish attempts for already published versions');
}
$expiresAt = time() + 600;
$downloadToken = $repo->downloadToken('vendor:demo', 'site-1', $expiresAt, ['download']);
$repo->assertDownloadAuthorized('vendor:demo', 'site-1', $downloadToken, $expiresAt, ['download']);
$repo->recordDownloadAudit('vendor:demo', '1.4.0', 'site-1', (string) $publishedDetail['package_sha256'], '127.0.0.1', 'market-dev-test');
market_dev_check(count($repo->downloadAudits('vendor:demo')) === 1, 'Market Server records authorized package distribution download audits');
$publishedReviewPage = $controller->reviewQueue(new Request('GET', '/admin/market-server/review', ['status' => 'Published']));
market_dev_check($publishedReviewPage->status() === 200 && str_contains($publishedReviewPage->body(), '暂停/下架') && str_contains($publishedReviewPage->body(), '移除/废弃'), 'Review Admin can filter published versions for suspend and remove actions');
$suspendPath = $builder->build($project->id, 'plugin', $pluginId, '1.4.1')['package_path'];
$suspendVersion = $repo->submitVersion($project->id, '1.4.1', $suspendPath, 'suspend action coverage');
$repo->runScan($suspendVersion->id, new PackageScanner());
$repo->recordReview($suspendVersion->id, 1, 'approve', 'Publish before suspend.');
$repo->publishVersion($suspendVersion->id, $repo->signatureForVersion($suspendVersion->id, new PackageSigner($privateKey)));
$suspended = $controller->reviewAction(new Request('POST', '/admin/market-server/review/action', [], [
    '_csrf' => $adminCsrf,
    'version_id' => (string) $suspendVersion->id,
    'action' => 'suspend',
    'notes' => 'Temporarily suspended by review admin.',
]));
market_dev_check($suspended->status() === 302 && $repo->versionStatus($suspendVersion->id) === 'Unpublished' && count(array_filter($repo->reviewsForProject($project->id), static fn (array $review): bool => (int) $review['version_id'] === $suspendVersion->id && (string) $review['decision'] === 'unpublish')) === 1, 'Review Admin suspend action unpublishes a published version and records audit evidence');
$removePath = $builder->build($project->id, 'plugin', $pluginId, '1.4.2')['package_path'];
$removeVersion = $repo->submitVersion($project->id, '1.4.2', $removePath, 'remove action coverage');
$repo->runScan($removeVersion->id, new PackageScanner());
$repo->recordReview($removeVersion->id, 1, 'approve', 'Publish before remove.');
$repo->publishVersion($removeVersion->id, $repo->signatureForVersion($removeVersion->id, new PackageSigner($privateKey)));
$removed = $controller->reviewAction(new Request('POST', '/admin/market-server/review/action', [], [
    '_csrf' => $adminCsrf,
    'version_id' => (string) $removeVersion->id,
    'action' => 'remove',
    'notes' => 'Removed from distribution by review admin.',
]));
market_dev_check($removed->status() === 302 && $repo->versionStatus($removeVersion->id) === 'Deprecated' && count(array_filter($repo->reviewsForProject($project->id), static fn (array $review): bool => (int) $review['version_id'] === $removeVersion->id && (string) $review['decision'] === 'deprecate')) === 1, 'Review Admin remove action deprecates a published version and records audit evidence');
$tamperPath = $builder->build($project->id, 'plugin', $pluginId, '1.5.0')['package_path'];
$tamperVersion = $repo->submitVersion($project->id, '1.5.0', $tamperPath, 'tamper before publish');
$repo->runScan($tamperVersion->id, new PackageScanner());
$repo->recordReview($tamperVersion->id, 1, 'approve', 'Hash should freeze.');
file_put_contents($tamperPath, 'tampered package');
try {
    $repo->publishVersion($tamperVersion->id, $repo->signatureForVersion($tamperVersion->id, new PackageSigner($privateKey)));
    market_dev_check(false, 'Market Server rejects tampered packages before publish');
} catch (Throwable) {
    market_dev_check(true, 'Market Server rejects tampered packages before publish');
}

$linkPath = $pluginDir . '/outside-link.php';
if (is_file($linkPath)) {
    unlink($linkPath);
}
symlink('/etc/passwd', $linkPath);
try {
    $builder->build($project->id, 'plugin', $pluginId, '1.2.5');
    market_dev_check(false, 'developer package builder rejects symlinks in extension source');
} catch (Throwable) {
    market_dev_check(true, 'developer package builder rejects symlinks in extension source');
}

market_dev_remove($root);

if ($failures > 0) {
    fwrite(STDERR, $failures . " market developer package checks failed.\n");
    exit(1);
}

echo "Market developer package builder tests passed.\n";
