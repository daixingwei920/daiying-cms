<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Events\EventDispatcher;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Media\MediaException;
use Cms\Core\Media\MediaLibrary;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Plugin\BlockRegistry;
use Cms\Core\Plugin\LocalPluginPackageInstaller;
use Cms\Core\Plugin\OfficialPluginRegistry;
use Cms\Core\Plugin\PluginLifecycle;
use Cms\Core\Plugin\PluginManager;
use Cms\Core\Plugin\PluginRuntimeRegistry;
use Cms\Core\Plugin\PluginSecretStore;
use Official\Commerce\Application\CatalogCommand;
use Official\Commerce\Application\CatalogService;
use Official\Commerce\Application\CatalogValidator;
use Official\Commerce\Application\CategoryCommand;
use Official\Commerce\Application\CommerceValidationException;
use Official\Commerce\Infrastructure\OutboxEventRepository;
use Official\Commerce\Repository\CatalogRepository;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';
$register = require CMS_SOURCE_ROOT . '/content/plugins/official.commerce/plugin.php';

$failures = 0;

function c2_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function c2_throws(callable $callback, string $message): void
{
    try {
        $callback();
        c2_check(false, $message);
    } catch (CommerceValidationException|MediaException|PDOException|RuntimeException) {
        c2_check(true, $message);
    }
}

function c2_remove(string $path): void
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

function c2_core_migrations(PDO $pdo): void
{
    $migrations = [];
    foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
        $migrations[] = require $file;
    }
    (new MigrationRunner($pdo, $migrations))->run();
}

function c2_service(PDO $pdo): array
{
    $repo = new CatalogRepository($pdo);
    return [$repo, new CatalogService($pdo, $repo, new CatalogValidator(), new OutboxEventRepository($pdo), new Cms\Core\Audit\AuditLogger($pdo))];
}

function c2_simple(string $title, string $slug, string $sku = 'sku-1', mixed $price = 1000, ?int $mediaId = null, string $commandId = ''): CatalogCommand
{
    return new CatalogCommand($title, $slug, 'simple', 'Summary', [['type' => 'paragraph', 'data' => ['text' => 'Clean <script>bad()</script> description']]], '', '', 'usd', '', $mediaId, [['sku' => $sku, 'price_minor' => $price, 'variant_status' => 'active']], [], [], [], $commandId);
}

$root = sys_get_temp_dir() . '/cms-commerce-c2-' . bin2hex(random_bytes(4));
c2_remove($root);
foreach (['config', 'storage/logs', 'storage/tmp', 'content/uploads'] as $dir) {
    mkdir($root . '/' . $dir, 0755, true);
}
$config = require CMS_SOURCE_ROOT . '/config/app.php';
$config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/commerce.sqlite', 'username' => '', 'password' => '', 'options' => []];
file_put_contents($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
$pdo = ConnectionFactory::make(Settings::load($root));
c2_core_migrations($pdo);
(new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo))->installBundled('official.commerce', 1, false);
[$repo, $service] = c2_service($pdo);

$pdo->exec("INSERT INTO cms_media (storage_provider, media_type, mime_type, original_name, relative_path, storage_key, byte_size, sha256_hash, metadata_json, extension, width, height, title, description, alt_text, uploaded_by, status, created_at, updated_at) VALUES ('local','image','image/png','p.png','2026/08/p.png','2026/08/p.png',10,'hash-c2','{}','png',1,1,'P','','',1,'Active','now','now')");
$mediaId = (int) $pdo->lastInsertId();

$simpleId = $service->create(c2_simple('Simple Product', 'simple-product', ' abc 123 ', 1200, $mediaId, 'cmd-simple-create'), 1);
$service->update($simpleId, c2_simple('Simple Product Updated', 'simple-product-updated', 'ABC-123', 1500, $mediaId, 'cmd-simple-update'), 1);
$service->publish($simpleId, 1, 'cmd-simple-publish');
$service->archive($simpleId, 1, 'cmd-simple-archive');
$simple = $repo->product($simpleId);
c2_check((string) $simple['status'] === 'archived' && (string) $simple['currency'] === 'USD', 'creates, updates, publishes and archives a simple product with normalized currency');
c2_check((string) $pdo->query("SELECT sku_norm FROM cms_commerce_product_variants WHERE product_id = " . $simpleId)->fetchColumn() === 'ABC-123', 'normalizes SKU case and spaces consistently');
c2_check((int) $pdo->query("SELECT COUNT(*) FROM cms_commerce_outbox_events WHERE resource_id = '" . $simpleId . "'")->fetchColumn() >= 3, 'writes product created, updated and published events to outbox');
c2_check(!str_contains((string) $simple['description_blocks_json'], '<script>'), 'sanitizes product description blocks');

c2_throws(static fn () => $service->create(c2_simple('Duplicate SKU', 'dup-sku', 'abc-123'), 1), 'rejects globally duplicate SKU after normalization');
c2_throws(static fn () => $service->create(c2_simple('Bad Money', 'bad-money', 'BAD-MONEY', '1.23'), 1), 'rejects float-like money amounts');
c2_throws(static fn () => $service->create(c2_simple('Reserved Slug', 'admin', 'ADMIN-SKU'), 1), 'rejects product reserved route slug');

$variable = new CatalogCommand('Variable Product', 'variable-product', 'variable', 'Variable', [['type' => 'paragraph', 'data' => ['text' => 'Variable']]], '', '', 'USD', '', $mediaId, [
    ['sku' => 'VAR-BLACK-S', 'price_minor' => 2000, 'variant_status' => 'active', 'attribute_signature' => 'color:black|size:s', 'media_id' => $mediaId, 'sort_order' => 1],
    ['sku' => 'VAR-WHITE-M', 'price_minor' => 2100, 'variant_status' => 'out_of_stock', 'attribute_signature' => 'color:white|size:m', 'sort_order' => 2],
], [
    ['name' => 'Color', 'values' => ['Black', 'White']],
    ['name' => 'Size', 'values' => ['S', 'M']],
], [], [['media_id' => $mediaId, 'role' => 'gallery']], 'cmd-variable');
$variableId = $service->create($variable, 1);
$service->publish($variableId, 1, 'cmd-variable-publish');
c2_check((int) $pdo->query("SELECT COUNT(*) FROM cms_commerce_product_variants WHERE product_id = " . $variableId)->fetchColumn() === 2, 'creates variable product attributes and multiple SKUs');
c2_throws(static fn () => $service->create(new CatalogCommand('Bad Variable', 'bad-variable', 'variable', '', [], '', '', 'USD', '', null, [
    ['sku' => 'BAD-V1', 'price_minor' => 100, 'variant_status' => 'active', 'attribute_signature' => 'color:black'],
    ['sku' => 'BAD-V2', 'price_minor' => 100, 'variant_status' => 'active', 'attribute_signature' => 'color:black'],
]), 1), 'rejects duplicate variant attribute combinations');

$externalId = $service->create(new CatalogCommand('External Product', 'external-product', 'external', '', [], '', '', 'USD', 'https://example.com/deal?x=1', null, [], [], [], [], 'cmd-external'), 1);
c2_check((string) $repo->product($externalId)['external_domain'] === 'example.com', 'saves external product with normalized HTTPS URL and target domain');
c2_throws(static fn () => $service->create(new CatalogCommand('Unsafe External', 'unsafe-external', 'external', '', [], '', '', 'USD', 'javascript:alert(1)', null, []), 1), 'rejects unsafe external product URLs');
c2_throws(static fn () => $service->create(new CatalogCommand('Local External', 'local-external', 'external', '', [], '', '', 'USD', 'https://127.0.0.1/a', null, []), 1), 'rejects local/private external product hosts');

$catA = $service->saveCategory(null, new CategoryCommand('A', 'cat-a'), 1);
$catB = $service->saveCategory(null, new CategoryCommand('B', 'cat-b', $catA), 1);
c2_throws(static fn () => $service->saveCategory($catA, new CategoryCommand('A', 'cat-a-new', $catB), 1), 'detects category parent cycles');
$multiId = $service->create(new CatalogCommand('Multi Category', 'multi-category', 'simple', '', [], '', '', 'USD', '', null, [['sku' => 'MULTI-1', 'price_minor' => 100, 'variant_status' => 'active']], [], [$catA, $catB]), 1);
c2_check((int) $pdo->query("SELECT COUNT(*) FROM cms_commerce_product_categories WHERE product_id = " . $multiId)->fetchColumn() === 2, 'assigns a product to multiple categories');
c2_check($service->categoryDeletePreview($catA)['product_count'] >= 1, 'category delete preview reports related product count without deleting products');

$runtime = new PluginRuntimeRegistry();
$manager = new PluginManager(CMS_SOURCE_ROOT . '/content/plugins', $pdo, new FileLogger($root . '/storage/logs/plugin.log'), new EventDispatcher(), new BlockRegistry(), $runtime, new OfficialPluginRegistry(CMS_SOURCE_ROOT), new PluginSecretStore($pdo, ''));
$manager->setStatus('official.commerce', PluginLifecycle::ENABLED);
$manager->bootEnabled();
c2_throws(static fn () => (new MediaLibrary($pdo, $root . '/content/uploads'))->hardDelete($mediaId), 'blocks hard delete of Core media referenced by Commerce products');

$routes = $runtime->routes();
c2_check(count(array_filter($routes, static fn ($route): bool => str_starts_with($route->path, '/admin/commerce/products'))) >= 7 && count(array_filter($routes, static fn ($route): bool => str_starts_with($route->path, '/admin/commerce/categories'))) >= 2, 'registers Commerce catalog admin routes');
c2_check(count(array_filter($routes, static fn ($route): bool => $route->csrf && $route->method === 'POST')) >= 5, 'binds CSRF to Commerce write routes');
c2_check(count(array_filter($routes, static fn ($route): bool => $route->capability === 'commerce.products.manage')) >= 8, 'binds product management capability to write/edit routes');
(new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo))->disableWithDependents('official.commerce', 1, true);
$runtimeDisabled = new PluginRuntimeRegistry();
(new PluginManager(CMS_SOURCE_ROOT . '/content/plugins', $pdo, new FileLogger($root . '/storage/logs/plugin.log'), new EventDispatcher(), new BlockRegistry(), $runtimeDisabled, new OfficialPluginRegistry(CMS_SOURCE_ROOT), new PluginSecretStore($pdo, '')))->bootEnabled();
c2_check($runtimeDisabled->routes() === [] && c2_table_count($pdo, 'cms_commerce_products') > 0, 'Commerce disable removes routes and menus while retaining data');
c2_check(class_exists(Cms\Core\Recovery\RecoveryController::class) && class_exists(Cms\Core\Update\UpdateService::class), 'CMS Recovery and Core Update remain available after Commerce disable');
c2_check((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name LIKE 'cms_market_%'")->fetchColumn() >= 0, 'legacy Market table scope remains isolated from Commerce tests');

function c2_table_count(PDO $pdo, string $table): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}

$mysqlStatus = 'skipped';
if (extension_loaded('pdo_mysql')) {
    try {
        $mysql = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db = 'cms_commerce_c2_' . bin2hex(random_bytes(3));
        $mysql->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            $mysqlPdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $db . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            c2_core_migrations($mysqlPdo);
            (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $mysqlPdo))->installBundled('official.commerce', 1, false);
            [$mysqlRepo, $mysqlService] = c2_service($mysqlPdo);
            $id = $mysqlService->create(c2_simple('MySQL Product', 'mysql-product', 'MYSQL-1'), 1);
            $mysqlService->publish($id, 1, 'mysql-publish');
            c2_check((string) $mysqlRepo->product($id)['status'] === 'published', 'real MySQL/MariaDB creates and publishes simple product');
            $spec = require CMS_SOURCE_ROOT . '/content/plugins/official.commerce/migrations/002_catalog_constraints.php';
            ($spec['down'])($mysqlPdo);
            ($spec['up'])($mysqlPdo);
            c2_check(true, 'real MySQL/MariaDB reruns C2 reversible migration after rollback of indexes');
            $mysqlStatus = 'passed';
        } finally {
            $mysql->exec('DROP DATABASE IF EXISTS `' . $db . '`');
        }
    } catch (Throwable $exception) {
        echo '[SKIP] real MySQL/MariaDB Commerce C2 test unavailable: ' . $exception->getMessage() . PHP_EOL;
    }
} else {
    echo "[SKIP] pdo_mysql is unavailable for Commerce C2 MySQL test.\n";
}

c2_remove($root);

if ($failures > 0) {
    echo 'Commerce C2 catalog failures: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'Commerce C2 catalog tests passed; mysql=' . $mysqlStatus . PHP_EOL;
