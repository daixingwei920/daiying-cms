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
use Cms\Core\Plugin\PluginManager;
use Cms\Core\Plugin\PluginRuntimeRegistry;
use Cms\Core\Plugin\PluginSecretStore;
use Official\Commerce\Application\CartCommand;
use Official\Commerce\Application\CartService;
use Official\Commerce\Application\CatalogCommand;
use Official\Commerce\Application\CatalogService;
use Official\Commerce\Application\CatalogValidator;
use Official\Commerce\Application\CheckoutService;
use Official\Commerce\Application\InventoryAdjustmentCommand;
use Official\Commerce\Application\InventoryService;
use Official\Commerce\Application\PaymentService;
use Official\Commerce\Infrastructure\FlatRateShippingProvider;
use Official\Commerce\Infrastructure\NoTaxCalculator;
use Official\Commerce\Infrastructure\OutboxEventRepository;
use Official\Commerce\Infrastructure\ProviderRegistry;
use Official\Commerce\Repository\CartRepository;
use Official\Commerce\Repository\CatalogRepository;
use Official\Commerce\Repository\FulfillmentRepository;
use Official\Commerce\Repository\InventoryRepository;
use Official\Commerce\Repository\OrderRepository;

if (!defined('CMS_SOURCE_ROOT')) {
    define('CMS_SOURCE_ROOT', dirname(__DIR__));
}

require_once CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

function p1_remove(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) {
        $item->isDir() ? rmdir((string) $item->getPathname()) : unlink((string) $item->getPathname());
    }
    rmdir($path);
}

function p1_write(string $path, string $content): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, $content);
}

function p1_copy_dir(string $source, string $target): void
{
    if (!is_dir($source)) {
        throw new RuntimeException('Missing fixture source: ' . $source);
    }
    p1_remove($target);
    mkdir($target, 0755, true);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $item) {
        $dest = $target . '/' . $it->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($dest)) {
                mkdir($dest, 0755, true);
            }
            continue;
        }
        copy((string) $item->getPathname(), $dest);
    }
}

function p1_site_root_for(PDO $pdo): string
{
    $registered = $GLOBALS['p1_site_roots'] ?? [];
    $id = spl_object_id($pdo);
    if (isset($registered[$id])) {
        return (string) $registered[$id];
    }
    static $roots = [];
    if (!isset($roots[$id])) {
        $stmt = $pdo->query('PRAGMA database_list');
        $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        $file = is_array($row) ? (string) ($row['file'] ?? '') : '';
        if ($file === '') {
            throw new RuntimeException('Payment P1 helper cannot infer site root.');
        }
        $roots[$id] = dirname(dirname($file));
    }

    return $roots[$id];
}

function p1_seed_site_root(string $root, array $databaseConfig): void
{
    p1_remove($root);
    foreach (['config', 'storage/logs', 'storage/tmp', 'content/uploads', 'content/plugins', 'system'] as $dir) {
        mkdir($root . '/' . $dir, 0755, true);
    }
    copy(CMS_SOURCE_ROOT . '/system/official-plugins.php', $root . '/system/official-plugins.php');
    p1_copy_dir(CMS_SOURCE_ROOT . '/content/plugins/official.commerce', $root . '/content/plugins/official.commerce');
    p1_copy_dir(CMS_SOURCE_ROOT . '/content/plugins/official.payment-fixture', $root . '/content/plugins/official.payment-fixture');
    $config = require CMS_SOURCE_ROOT . '/config/app.php';
    $config['database'] = $databaseConfig;
    $config['security']['encryption_key'] = 'payment-p1-test-key';
    p1_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
}

/** @return list<mixed> */
function p1_core_migrations(): array
{
    $migrations = [];
    foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
        $migrations[] = require $file;
    }
    return $migrations;
}

/** @return array{root:string,pdo:PDO} */
function p1_sqlite_root(string $name): array
{
    $root = sys_get_temp_dir() . '/cms-payment-p1-' . $name . '-' . bin2hex(random_bytes(4));
    p1_seed_site_root($root, ['dsn' => 'sqlite:' . $root . '/storage/payment.sqlite', 'username' => '', 'password' => '', 'options' => []]);
    $pdo = ConnectionFactory::make(Settings::load($root));
    (new MigrationRunner($pdo, p1_core_migrations()))->run();
    $GLOBALS['p1_site_roots'][spl_object_id($pdo)] = $root;
    return ['root' => $root, 'pdo' => $pdo];
}

/** @return array{root:string,pdo:PDO,root_pdo:PDO,database:string} */
function p1_mysql_root(string $name): array
{
    $db = 'cms_payment_p1_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($name)) . '_' . bin2hex(random_bytes(3));
    $root = sys_get_temp_dir() . '/cms-payment-p1-mysql-' . $name . '-' . bin2hex(random_bytes(4));
    $rootPdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $rootPdo->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    p1_seed_site_root($root, ['dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=' . $db . ';charset=utf8mb4', 'username' => 'root', 'password' => '', 'options' => []]);
    $pdo = ConnectionFactory::make(Settings::load($root));
    (new MigrationRunner($pdo, p1_core_migrations()))->run();
    $GLOBALS['p1_site_roots'][spl_object_id($pdo)] = $root;
    return ['root' => $root, 'pdo' => $pdo, 'root_pdo' => $rootPdo, 'database' => $db];
}

/** @param array{root:string,root_pdo?:PDO,database?:string} $env */
function p1_cleanup_env(array $env): void
{
    if (isset($env['root_pdo'], $env['database']) && $env['root_pdo'] instanceof PDO) {
        $env['root_pdo']->exec('DROP DATABASE IF EXISTS `' . (string) $env['database'] . '`');
    }
    p1_remove($env['root']);
}

function p1_install(PDO $pdo, bool $commerce = true, bool $payment = true): void
{
    $installer = new LocalPluginPackageInstaller(p1_site_root_for($pdo), $pdo);
    if ($commerce) {
        $installer->installBundled('official.commerce', 1, true);
    }
    if ($payment) {
        $installer->installBundled('official.payment-fixture', 1, true);
    }
}

function p1_boot(PDO $pdo, ?string $pluginsPath = null): PluginRuntimeRegistry
{
    if (class_exists(ProviderRegistry::class)) {
        ProviderRegistry::clear();
    }
    $root = p1_site_root_for($pdo);
    $runtime = new PluginRuntimeRegistry();
    $manager = new PluginManager(
        $pluginsPath ?? ($root . '/content/plugins'),
        $pdo,
        new FileLogger(sys_get_temp_dir() . '/payment-p1-plugin.log'),
        new EventDispatcher(),
        new BlockRegistry(),
        $runtime,
        new OfficialPluginRegistry($root),
        new PluginSecretStore($pdo, 'payment-p1-test-key'),
    );
    $manager->syncDiscovered();
    $manager->bootEnabled();
    return $runtime;
}

/** @return array{catalog:CatalogService,inventory:InventoryService,cart:CartService,checkout:CheckoutService,payment:PaymentService,orders:OrderRepository,inventory_repo:InventoryRepository,fulfillments:FulfillmentRepository,pdo:PDO} */
function p1_services(PDO $pdo): array
{
    $outbox = new OutboxEventRepository($pdo);
    $audit = new AuditLogger($pdo);
    $catalogRepo = new CatalogRepository($pdo);
    $inventoryRepo = new InventoryRepository($pdo);
    $cartRepo = new CartRepository($pdo);
    $orderRepo = new OrderRepository($pdo);
    return [
        'catalog' => new CatalogService($pdo, $catalogRepo, new CatalogValidator(), $outbox, $audit),
        'inventory' => new InventoryService($pdo, $inventoryRepo, $outbox, $audit),
        'cart' => new CartService($pdo, $cartRepo, $outbox),
        'checkout' => new CheckoutService($pdo, $cartRepo, $inventoryRepo, $orderRepo, new FlatRateShippingProvider(500, null), new NoTaxCalculator(), $outbox, $audit),
        'payment' => new PaymentService($pdo, $orderRepo, $inventoryRepo, $outbox, $audit),
        'orders' => $orderRepo,
        'inventory_repo' => $inventoryRepo,
        'fulfillments' => new FulfillmentRepository($pdo),
        'pdo' => $pdo,
    ];
}

function p1_address(): array
{
    return ['full_name' => 'Fixture Buyer', 'email' => 'fixture@example.test', 'phone' => '5550100', 'line1' => '1 Test St', 'line2' => '', 'city' => 'Boston', 'region' => 'MA', 'postal_code' => '02110', 'country_code' => 'US'];
}

/** @return array{order:array<string,mixed>,variant_id:int,total:int,public_token:string} */
function p1_pending_order(PDO $pdo, string $suffix = 'A', int $quantity = 2): array
{
    $services = p1_services($pdo);
    $variantSku = 'P1-SKU-' . $suffix;
    $productId = $services['catalog']->create(new CatalogCommand('P1 Product ' . $suffix, 'p1-product-' . strtolower($suffix) . '-' . bin2hex(random_bytes(2)), 'simple', '', [], '', '', 'USD', '', null, [['sku' => $variantSku, 'price_minor' => 1000, 'variant_status' => 'active', 'attribute_signature' => '']], [], [], [], 'p1-create-' . $suffix . '-' . bin2hex(random_bytes(2))), 1);
    $services['catalog']->publish($productId, 1, 'p1-publish-' . $suffix . '-' . bin2hex(random_bytes(2)));
    $variantId = (int) $pdo->query('SELECT id FROM cms_commerce_product_variants WHERE product_id = ' . (int) $productId . ' LIMIT 1')->fetchColumn();
    $services['inventory']->adjust(new InventoryAdjustmentCommand($variantId, 10, 'manual_adjustment', 'opening stock', 'p1-stock-' . $suffix . '-' . bin2hex(random_bytes(2))), 1);
    $state = $services['cart']->getOrCreate(null);
    $carted = $services['cart']->add((string) $state['token'], new CartCommand($variantId, $quantity, (int) $state['cart']['version'], 'p1-cart-' . $suffix . '-' . bin2hex(random_bytes(2))));
    $quote = $services['checkout']->quote((string) $state['token'], (int) $carted['cart']['version'], p1_address());
    $result = $services['checkout']->placeOrder((string) $state['token'], (int) $carted['cart']['version'], p1_address(), (string) $quote['quote_hash'], 'p1-order-' . $suffix . '-' . bin2hex(random_bytes(2)));
    return ['order' => $result['order'], 'variant_id' => $variantId, 'total' => (int) $quote['total_minor'], 'public_token' => (string) ($result['public_token'] ?? '')];
}
