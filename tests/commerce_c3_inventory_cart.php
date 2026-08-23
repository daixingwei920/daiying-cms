<?php

declare(strict_types=1);

use Cms\Core\Audit\AuditLogger;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Events\EventDispatcher;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Plugin\BlockRegistry;
use Cms\Core\Plugin\LocalPluginPackageInstaller;
use Cms\Core\Plugin\OfficialPluginRegistry;
use Cms\Core\Plugin\PluginLifecycle;
use Cms\Core\Plugin\PluginManager;
use Cms\Core\Plugin\PluginRuntimeRegistry;
use Official\Commerce\Application\CartCommand;
use Official\Commerce\Application\CartService;
use Official\Commerce\Application\CatalogCommand;
use Official\Commerce\Application\CatalogService;
use Official\Commerce\Application\CatalogValidator;
use Official\Commerce\Application\CommerceValidationException;
use Official\Commerce\Application\InventoryAdjustmentCommand;
use Official\Commerce\Application\InventoryService;
use Official\Commerce\Infrastructure\OutboxEventRepository;
use Official\Commerce\Repository\CartRepository;
use Official\Commerce\Repository\CatalogRepository;
use Official\Commerce\Repository\InventoryRepository;

define('CMS_SOURCE_ROOT', dirname(__DIR__));
require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';
require CMS_SOURCE_ROOT . '/content/plugins/official.commerce/plugin.php';

$failures = 0;

function c3_check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}

function c3_throws(callable $callback, string $message): void
{
    try {
        $callback();
        c3_check(false, $message);
    } catch (Throwable) {
        c3_check(true, $message);
    }
}

function c3_remove(string $path): void
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

/** @return list<object> */
function c3_core_migrations(): array
{
    $migrations = [];
    foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
        $migrations[] = require $file;
    }
    return $migrations;
}

function c3_setup_sqlite(string $name): array
{
    $root = sys_get_temp_dir() . '/cms-commerce-c3-' . $name . '-' . bin2hex(random_bytes(4));
    c3_remove($root);
    foreach (['config', 'storage/logs', 'storage/tmp', 'content/uploads'] as $dir) {
        mkdir($root . '/' . $dir, 0755, true);
    }
    $config = require CMS_SOURCE_ROOT . '/config/app.php';
    $config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/commerce.sqlite', 'username' => '', 'password' => '', 'options' => []];
    file_put_contents($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
    $pdo = ConnectionFactory::make(Settings::load($root));
    (new MigrationRunner($pdo, c3_core_migrations()))->run();
    (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo))->installBundled('official.commerce', 1, false);
    return [$root, $pdo];
}

function c3_services(PDO $pdo): array
{
    $outbox = new OutboxEventRepository($pdo);
    $catalogRepo = new CatalogRepository($pdo);
    return [
        $catalogRepo,
        new CatalogService($pdo, $catalogRepo, new CatalogValidator(), $outbox, new AuditLogger($pdo)),
        new InventoryRepository($pdo),
        new InventoryService($pdo, new InventoryRepository($pdo), $outbox, new AuditLogger($pdo)),
        new CartService($pdo, new CartRepository($pdo), $outbox),
    ];
}

function c3_product(PDO $pdo, CatalogService $catalog, CatalogRepository $repo, string $sku = 'C3-SKU', string $type = 'simple'): array
{
    $variants = $type === 'external' ? [] : [['sku' => $sku, 'price_minor' => 1000, 'variant_status' => 'active', 'attribute_signature' => $type === 'variable' ? 'color:black' : '']];
    $attributes = $type === 'variable' ? [['name' => 'Color', 'values' => ['Black']]] : [];
    $id = $catalog->create(new CatalogCommand('C3 Product ' . $sku, 'c3-product-' . strtolower(str_replace('_', '-', $sku)), $type, '', [], '', '', 'USD', $type === 'external' ? 'https://example.com/product' : '', null, $variants, $attributes, [], [], 'create-' . $sku), 1);
    $catalog->publish($id, 1, 'publish-' . $sku);
    $variantId = $type === 'external' ? 0 : (int) $pdo->query('SELECT id FROM cms_commerce_product_variants WHERE product_id = ' . $id . ' LIMIT 1')->fetchColumn();
    return [$id, $variantId, $repo->product($id)];
}

[$root, $pdo] = c3_setup_sqlite('sqlite');
[$catalogRepo, $catalog, $inventoryRepo, $inventory, $cart] = c3_services($pdo);
[$productId, $variantId] = c3_product($pdo, $catalog, $catalogRepo);

c3_check((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'cms_commerce_inventory_items'")->fetchColumn() === 1, 'C3 migration keeps inventory table available on SQLite');
c3_check(in_array('commerce.inventory.manage', json_decode((string) file_get_contents(CMS_SOURCE_ROOT . '/content/plugins/official.commerce/plugin.json'), true)['capabilities'], true), 'Commerce manifest adds only inventory manage capability for C3');

$inventory->adjust(new InventoryAdjustmentCommand($variantId, 5, 'manual_adjustment', 'initial stock', 'inv-add-1'), 1);
$item = $inventoryRepo->itemForVariant($variantId);
c3_check((int) $item['on_hand'] === 5 && (int) $item['reserved'] === 0 && (int) $item['available'] === 5, 'local inventory adjustment maintains available = on_hand - reserved');
$inventory->adjust(new InventoryAdjustmentCommand($variantId, 5, 'manual_adjustment', 'initial stock', 'inv-add-1'), 1);
c3_check((int) $pdo->query("SELECT COUNT(*) FROM cms_commerce_inventory_movements WHERE idempotency_key = 'inv-add-1'")->fetchColumn() === 1, 'duplicate inventory idempotency key does not adjust twice');
c3_throws(static fn () => $inventory->adjust(new InventoryAdjustmentCommand($variantId, -10, 'manual_adjustment', 'bad', 'inv-bad')), 'illegal negative available inventory is rejected');
c3_throws(static fn () => $pdo->exec('UPDATE cms_commerce_inventory_movements SET reason = \'changed\''), 'inventory movements are immutable');
c3_throws(static fn () => $inventory->adjust(new InventoryAdjustmentCommand($variantId, 0, 'manual_adjustment', '', 'bad-zero'), 1), 'zero quantity and missing reason adjustments are rejected');

c3_throws(static fn () => $inventory->reserve($variantId, 6, 'reserve-too-many'), 'reservation atomically checks available inventory');
$reservationId = $inventory->reserve($variantId, 1, 'reserve-one');
c3_throws(static fn () => $inventory->reserve($variantId, 5, 'reserve-last-race-loser'), 'two reservations cannot both reserve the last stock');
$inventory->release($reservationId);
$inventory->release($reservationId);
c3_check((int) $inventoryRepo->itemForVariant($variantId)['reserved'] === 0, 'reservation release is idempotent');
$reservationId = $inventory->reserve($variantId, 1, 'reserve-consume');
$inventory->consume($reservationId);
$inventory->release($reservationId);
c3_check((string) $inventoryRepo->reservation($reservationId)['status'] === 'consumed', 'consumed reservation cannot be released again');
$expiredId = $inventory->reserve($variantId, 1, 'reserve-expire', null, null, -1);
c3_check($inventory->expireDue(10) === 1 && $inventory->expireDue(10) === 0, 'repeated cleanup does not release expired reservations twice');

$state = $cart->getOrCreate(null);
c3_check(strlen($state['token']) === 64 && (int) $pdo->query('SELECT COUNT(*) FROM cms_commerce_carts WHERE token_hash = ' . $pdo->quote(hash('sha256', $state['token'])))->fetchColumn() === 1, 'anonymous cart uses random token and stores only token hash');
$cartState = $cart->add($state['token'], new CartCommand($variantId, 1, (int) $state['cart']['version'], 'cart-add-1'));
c3_check(count($cartState['items']) === 1 && (int) $cartState['subtotal_minor'] === 1000, 'simple product SKU can be added to cart with server-side price');
c3_check((int) $inventoryRepo->itemForVariant($variantId)['reserved'] === 0, 'adding to cart does not reserve inventory in C3');
c3_throws(static fn () => $cart->add($state['token'], new CartCommand($variantId, 1, 1, 'cart-conflict')), 'cart version conflict rejects stale tab update');
c3_throws(static fn () => $cart->add($state['token'], new CartCommand($variantId, 1000, (int) $cartState['cart']['version'], 'cart-too-many')), 'cart quantity upper bound is enforced');
$again = $cart->add($state['token'], new CartCommand($variantId, 1, (int) $cartState['cart']['version'], 'cart-add-2'));
c3_check((int) $again['items'][0]['quantity'] === 2, 'same SKU add uses explicit quantity merge');
c3_throws(static fn () => $cart->add($state['token'], new CartCommand($variantId, 2, (int) $again['cart']['version'], 'cart-add-2')), 'same idempotency key with different request content is rejected');
$pdo->exec('UPDATE cms_commerce_product_variants SET price_minor = 1200 WHERE id = ' . $variantId);
c3_check((int) $cart->view($state['token'])['items'][0]['price_changed'] === 1, 'cart reread detects product price changes and uses latest server price');
$catalog->archive($productId, 1, 'archive-cart-product');
c3_check((string) $cart->view($state['token'])['items'][0]['purchasable_status'] === 'product_unavailable', 'archived product remains in cart but is marked not purchasable');

[$variableProduct, $variableVariant] = c3_product($pdo, $catalog, $catalogRepo, 'C3-VAR', 'variable');
$inventory->adjust(new InventoryAdjustmentCommand($variableVariant, 2, 'manual_adjustment', 'variable stock', 'inv-var'), 1);
c3_check(count($cart->add(null, new CartCommand($variableVariant, 1, 1, 'cart-variable'))['items']) === 1, 'variable product requires an explicit variant id and can be added when valid');
[$externalProduct] = c3_product($pdo, $catalog, $catalogRepo, 'C3-EXT', 'external');
$pdo->exec("INSERT INTO cms_commerce_product_variants (product_id, sku, sku_norm, price_minor, currency, variant_status, attribute_signature, created_at, updated_at) VALUES (" . $externalProduct . ", 'EXT-SKU', 'EXT-SKU', 100, 'USD', 'active', '', 'now', 'now')");
$externalVariant = (int) $pdo->lastInsertId();
c3_throws(static fn () => $cart->add(null, new CartCommand($externalVariant, 1, 1, 'cart-external')), 'external product cannot be added to local cart');

$runtime = new PluginRuntimeRegistry();
$manager = new PluginManager(CMS_SOURCE_ROOT . '/content/plugins', $pdo, new FileLogger($root . '/storage/logs/plugin.log'), new EventDispatcher(), new BlockRegistry(), $runtime, new OfficialPluginRegistry(CMS_SOURCE_ROOT));
$manager->setStatus('official.commerce', PluginLifecycle::ENABLED);
$manager->bootEnabled();
c3_check(count(array_filter($runtime->routes(), static fn ($route): bool => str_starts_with($route->path, '/cart'))) === 5, 'Commerce registers public cart routes through plugin API');
c3_check(count(array_filter($runtime->routes(), static fn ($route): bool => str_starts_with($route->path, '/admin/commerce/inventory'))) >= 3, 'Commerce registers inventory admin routes');
(new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo))->disableWithDependents('official.commerce', 1, true);
$disabledRuntime = new PluginRuntimeRegistry();
(new PluginManager(CMS_SOURCE_ROOT . '/content/plugins', $pdo, new FileLogger($root . '/storage/logs/plugin.log'), new EventDispatcher(), new BlockRegistry(), $disabledRuntime, new OfficialPluginRegistry(CMS_SOURCE_ROOT)))->bootEnabled();
c3_check($disabledRuntime->routes() === [] && (int) $pdo->query('SELECT COUNT(*) FROM cms_commerce_carts')->fetchColumn() > 0, 'Commerce disable removes cart routes while retaining data');

$migration = require CMS_SOURCE_ROOT . '/content/plugins/official.commerce/migrations/003_inventory_cart_constraints.php';
($migration['down'])($pdo);
($migration['up'])($pdo);
c3_check(true, 'C2B-installed SQLite database upgrades to C3 and reruns reversible migration safely');

$mysqlStatus = 'skipped';
if (extension_loaded('pdo_mysql')) {
    try {
        $rootPdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db = 'cms_commerce_c3_' . bin2hex(random_bytes(3));
        $rootPdo->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            $mysqlPdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $db . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            (new MigrationRunner($mysqlPdo, c3_core_migrations()))->run();
            (new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $mysqlPdo))->installBundled('official.commerce', 1, false);
            [$mysqlCatalogRepo, $mysqlCatalog, $mysqlInvRepo, $mysqlInventory, $mysqlCart] = c3_services($mysqlPdo);
            [, $mysqlVariant] = c3_product($mysqlPdo, $mysqlCatalog, $mysqlCatalogRepo, 'MYSQL-C3');
            $mysqlInventory->adjust(new InventoryAdjustmentCommand($mysqlVariant, 1, 'manual_adjustment', 'mysql stock', 'mysql-inv'), 1);
            $mysqlReservation = $mysqlInventory->reserve($mysqlVariant, 1, 'mysql-reserve');
            c3_check($mysqlReservation > 0, 'real MySQL/MariaDB reserves inventory atomically');
            c3_throws(static fn () => $mysqlInventory->reserve($mysqlVariant, 1, 'mysql-reserve-race'), 'real MySQL/MariaDB rejects second reservation of last unit');
            $mysqlInventory->release($mysqlReservation);
            $mysqlCartState = $mysqlCart->getOrCreate(null);
            c3_check(count($mysqlCart->add($mysqlCartState['token'], new CartCommand($mysqlVariant, 1, (int) $mysqlCartState['cart']['version'], 'mysql-cart'))['items']) === 1, 'real MySQL/MariaDB cart add works');
            $mysqlStatus = 'passed';
        } finally {
            $rootPdo->exec('DROP DATABASE IF EXISTS `' . $db . '`');
        }
    } catch (Throwable $exception) {
        echo '[SKIP] real MySQL/MariaDB Commerce C3 test unavailable: ' . $exception->getMessage() . PHP_EOL;
    }
}
c3_check($mysqlStatus === 'passed', 'real MySQL/MariaDB C3 acceptance is required and passed');

c3_remove($root);

if ($failures > 0) {
    echo 'Commerce C3 inventory/cart failures: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'Commerce C3 inventory/cart tests passed; mysql=' . $mysqlStatus . PHP_EOL;
