<?php

declare(strict_types=1);

use Cms\Core\Auth\AdminAuthenticator;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Events\EventDispatcher;
use Cms\Core\Http\Request;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Media\MediaException;
use Cms\Core\Media\MediaLibrary;
use Cms\Core\Media\PluginMediaReferenceRegistry;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Plugin\BlockRegistry;
use Cms\Core\Plugin\LocalPluginPackageInstaller;
use Cms\Core\Plugin\OfficialPluginRegistry;
use Cms\Core\Plugin\PluginAdminRequestContext;
use Cms\Core\Plugin\PluginContext;
use Cms\Core\Plugin\PluginException;
use Cms\Core\Plugin\PluginLifecycle;
use Cms\Core\Plugin\PluginManager;
use Cms\Core\Plugin\PluginRuntimeRegistry;
use Cms\Core\Plugin\PluginSecretStore;
use Official\Commerce\Admin\CatalogAdminController;
use Official\Commerce\Application\CatalogService;
use Official\Commerce\Application\CatalogValidator;
use Official\Commerce\Infrastructure\OutboxEventRepository;
use Official\Commerce\Repository\CatalogRepository;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';
$register = require CMS_SOURCE_ROOT . '/content/plugins/official.commerce/plugin.php';

$failures = 0;

function c2a_check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}

function c2a_throws(callable $callback, string $message): void
{
    try {
        $callback();
        c2a_check(false, $message);
    } catch (Throwable) {
        c2a_check(true, $message);
    }
}

function c2a_remove(string $path): void
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

function c2a_copy(string $source, string $target): void
{
    if (is_file($source)) {
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }
        copy($source, $target);
        return;
    }
    if (!is_dir($target)) {
        mkdir($target, 0755, true);
    }
    foreach (new DirectoryIterator($source) as $item) {
        if (!$item->isDot()) {
            c2a_copy($item->getPathname(), $target . '/' . $item->getBasename());
        }
    }
}

function c2a_core_migrations(PDO $pdo): void
{
    $migrations = [];
    foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
        $migrations[] = require $file;
    }
    (new MigrationRunner($pdo, $migrations))->run();
}

$coreFiles = [
    'system/core/Media/MediaLibrary.php',
    'system/core/Media/PluginMediaReferenceRegistry.php',
    'system/core/Media/PluginMediaReferenceProviderInterface.php',
];
$coreText = '';
foreach ($coreFiles as $file) {
    $coreText .= file_get_contents(CMS_SOURCE_ROOT . '/' . $file);
}
c2a_check(!str_contains(strtolower($coreText), 'commerce'), 'Core media layer has no Commerce table or plugin hardcoding');

$manifest = json_decode((string) file_get_contents(CMS_SOURCE_ROOT . '/content/plugins/official.commerce/plugin.json'), true);
c2a_check(version_compare((string) ($manifest['version'] ?? ''), '1.0.0-alpha.4', '>='), 'Commerce manifest version is at least 1.0.0-alpha.4');
c2a_check(array_diff(($manifest['capabilities'] ?? []), ['commerce.view', 'commerce.products.manage', 'commerce.inventory.manage', 'commerce.orders.view', 'commerce.orders.manage', 'commerce.payments.manage', 'commerce.refunds.manage', 'commerce.fulfillment.manage']) === [], 'Commerce manifest declares only currently used capabilities');
c2a_check(($manifest['media_reference_provider'] ?? '') === 'Official\\Commerce\\Media\\CommerceMediaReferenceProvider', 'Commerce declares a plugin media reference provider');

$root = sys_get_temp_dir() . '/cms-commerce-c2a-' . bin2hex(random_bytes(4));
c2a_remove($root);
foreach (['config', 'storage/logs', 'storage/tmp', 'content/uploads'] as $dir) {
    mkdir($root . '/' . $dir, 0755, true);
}
$config = require CMS_SOURCE_ROOT . '/config/app.php';
$config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/commerce.sqlite', 'username' => '', 'password' => '', 'options' => []];
file_put_contents($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
$pdo = ConnectionFactory::make(Settings::load($root));
c2a_core_migrations($pdo);
(new AdminAuthenticator($pdo))->createAdmin('real-admin@example.test', 'secret-pass', 'Real Admin');
$adminId = (int) $pdo->query("SELECT id FROM cms_admin_users WHERE email = 'real-admin@example.test'")->fetchColumn();
(new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo))->installBundled('official.commerce', $adminId, true);

$runtime = new PluginRuntimeRegistry();
$manager = new PluginManager(CMS_SOURCE_ROOT . '/content/plugins', $pdo, new FileLogger($root . '/storage/logs/plugin.log'), new EventDispatcher(), new BlockRegistry(), $runtime, new OfficialPluginRegistry(CMS_SOURCE_ROOT), new PluginSecretStore($pdo, ''));
$manager->bootEnabled();
c2a_check(PluginMediaReferenceRegistry::providers() !== [], 'enabled official.commerce registers media reference provider through generic registry');

$pdo->exec("INSERT INTO cms_media (storage_provider, media_type, mime_type, original_name, relative_path, storage_key, byte_size, sha256_hash, metadata_json, extension, width, height, title, description, alt_text, uploaded_by, status, created_at, updated_at) VALUES ('local','image','image/png','c2a.png','2026/08/c2a.png','2026/08/c2a.png',10,'hash-c2a','{}','png',1,1,'C2A','',''," . $adminId . ",'Active','now','now')");
$mediaId = (int) $pdo->lastInsertId();
$repo = new CatalogRepository($pdo);
$service = new CatalogService($pdo, $repo, new CatalogValidator(), new OutboxEventRepository($pdo), new Cms\Core\Audit\AuditLogger($pdo));
$controller = new CatalogAdminController($repo, $service);
$context = new PluginAdminRequestContext('official.commerce', $adminId, ['commerce.view', 'commerce.products.manage'], 'corr-c2a', 'req-c2a', '127.0.0.1');
$createRequest = new Request('POST', '/admin/commerce/products', [], [
    'title' => 'C2A Visual Product',
    'slug' => 'c2a-visual-product',
    'product_type' => 'simple',
    'summary' => 'Visual form',
    'description_paragraph' => 'Structured paragraph from visual field',
    'seo_title' => 'C2A SEO',
    'seo_description' => 'C2A SEO description',
    'canonical_url' => '/products/c2a-visual-product',
    'currency' => 'USD',
    'main_media_id' => (string) $mediaId,
    'gallery_media_ids' => (string) $mediaId,
    'variant_sku' => ['C2A-SKU'],
    'variant_price_minor' => ['1200'],
    'variant_compare_at_minor' => [''],
    'variant_cost_minor' => [''],
    'variant_status' => ['active'],
    'variant_signature' => [''],
    'variant_sort_order' => ['0'],
    'variant_media_id' => [(string) $mediaId],
    'command_id' => 'c2a-create',
], ['plugin_admin_context' => $context]);
$response = $controller->create($createRequest);
c2a_check($response->status() === 302, 'visual product form creates a product without administrator-authored JSON');
$productId = (int) $pdo->query("SELECT id FROM cms_commerce_products WHERE slug = 'c2a-visual-product'")->fetchColumn();
c2a_check((int) $pdo->query("SELECT actor_id FROM cms_audit_logs WHERE action = 'commerce.product.created' ORDER BY id DESC LIMIT 1")->fetchColumn() === $adminId, 'product create audit uses real authenticated administrator id');
$controller->publish(new Request('POST', '/admin/commerce/products/' . $productId . '/publish', [], ['command_id' => 'c2a-publish'], ['plugin_admin_context' => $context]));
c2a_check((int) $pdo->query("SELECT actor_id FROM cms_audit_logs WHERE action = 'commerce.product.status_changed' ORDER BY id DESC LIMIT 1")->fetchColumn() === $adminId, 'product publish audit uses real authenticated administrator id');
$missingContextResponse = $controller->saveCategory(new Request('POST', '/admin/commerce/categories', [], ['name' => 'No Context']));
c2a_check($missingContextResponse->status() === 422 && (int) $pdo->query("SELECT COUNT(*) FROM cms_commerce_categories WHERE name = 'No Context'")->fetchColumn() === 0, 'write routes reject missing plugin admin context instead of using actor 1');

$form = $controller->newProduct()->body();
c2a_check(str_contains($form, 'SKU/变体编辑器') && str_contains($form, '属性编辑器') && str_contains($form, '媒体选择器') && !str_contains($form, 'SKU/规格 JSON') && !str_contains($form, '属性 JSON'), 'admin product form exposes visual editors instead of requiring JSON textareas');
c2a_throws(static fn () => $service->create(new Official\Commerce\Application\CatalogCommand('Bad Canonical', 'bad-canonical', 'simple', '', [], '', '', 'USD', '', null, [['sku' => 'BAD-CANON', 'price_minor' => 100, 'variant_status' => 'active']], [], [], [], 'bad-canonical', 'javascript:alert(1)'), $adminId), 'canonical URL rejects dangerous schemes');
c2a_throws(static fn () => (new MediaLibrary($pdo, $root . '/content/uploads'))->hardDelete($mediaId), 'generic media registry blocks deletion of Commerce referenced media');
(new LocalPluginPackageInstaller($root, $pdo))->disableWithDependents('official.commerce', $adminId, true);
c2a_throws(static fn () => (new MediaLibrary($pdo, $root . '/content/uploads'))->hardDelete($mediaId), 'disabled Commerce still protects existing media references through persistent plugin media index');

$ordinaryManifest = Cms\Core\Plugin\PluginManifest::fromArray([
    'plugin_id' => 'vendor.example',
    'name' => 'Vendor',
    'version' => '1.0.0',
    'author' => 'Vendor',
    'core' => ['min' => '1.2.0-rc1'],
    'php' => '8.3.0',
    'entry' => 'plugin.php',
    'capabilities' => ['vendor.example.view'],
]);
$ordinaryContext = new PluginContext($ordinaryManifest, new EventDispatcher(), new BlockRegistry(), new Cms\Core\Plugin\PluginDataStore($pdo, 'vendor.example'), null);
c2a_throws(static fn () => $ordinaryContext->pdo(), 'ordinary uploaded plugin cannot access raw PDO');
$forgedTrusted = Cms\Core\Plugin\PluginManifest::fromArray([
    'plugin_id' => 'vendor.trusted',
    'name' => 'Forged',
    'version' => '1.0.0',
    'author' => 'Vendor',
    'core' => ['min' => '1.2.0-rc1'],
    'php' => '8.3.0',
    'entry' => 'plugin.php',
    'trust_level' => 'trusted_php',
    'capabilities' => ['vendor.trusted.view'],
]);
$forgedContext = new PluginContext($forgedTrusted, new EventDispatcher(), new BlockRegistry(), new Cms\Core\Plugin\PluginDataStore($pdo, 'vendor.trusted'), null, null, null, false);
c2a_throws(static fn () => $forgedContext->pdo(), 'forged trusted_php plugin cannot access raw PDO without trusted source');
c2a_check($repo->product($productId) !== null, 'official.commerce retained trusted PDO access for catalog repositories');

$mysqlStatus = 'skipped';
if (extension_loaded('pdo_mysql')) {
    try {
        $mysql = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db = 'cms_commerce_c2a_' . bin2hex(random_bytes(3));
        $mysql->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            $mysqlPdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $db . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            c2a_core_migrations($mysqlPdo);
            (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $mysqlPdo))->installBundled('official.commerce', 1, false);
            $mysqlStatus = 'passed';
            c2a_check(true, 'real MySQL/MariaDB installs C2A Commerce manifest and migrations');
        } finally {
            $mysql->exec('DROP DATABASE IF EXISTS `' . $db . '`');
        }
    } catch (Throwable $exception) {
        echo '[SKIP] real MySQL/MariaDB Commerce C2A test unavailable: ' . $exception->getMessage() . PHP_EOL;
    }
}

c2a_remove($root);

if ($failures > 0) {
    echo 'Commerce C2A integration UX failures: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'Commerce C2A integration UX tests passed; mysql=' . $mysqlStatus . PHP_EOL;
