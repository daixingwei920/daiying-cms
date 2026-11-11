<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 2 || $argv[1] !== '--once') {
    fwrite(STDERR, "CLI only: php reconcile-pos-orders.php --once\n");
    exit(2);
}

$root = dirname(__DIR__, 4);
define('CMS_ROOT', $root);

try {
    $autoload = $root . '/system/core/Bootstrap/autoload.php';
    $pointerPath = $root . '/storage/updates/current-release.json';
    if (is_file($pointerPath)) {
        $pointer = json_decode((string) file_get_contents($pointerPath), true, 512, JSON_THROW_ON_ERROR);
        $active = realpath((string) ($pointer['path'] ?? ''));
        $releases = realpath($root . '/storage/updates/releases');
        if ($active === false || $releases === false || !str_starts_with($active, $releases . DIRECTORY_SEPARATOR) || !is_file($active . '/system/core/Bootstrap/autoload.php')) {
            throw new RuntimeException('Invalid native active-release pointer; stop without fallback.');
        }
        $autoload = $active . '/system/core/Bootstrap/autoload.php';
    }
    require $autoload;
    $settings = Cms\Core\Config\Settings::load($root);
    Cms\Core\Integrity\CoreBoundary::assertWritablePaths($root);
    $mode = Cms\Core\Recovery\RunMode::detect($root, (string) $settings->get('app.mode', Cms\Core\Recovery\RunMode::NORMAL));
    if (!is_file($root . '/storage/installed.lock') || is_file($root . '/storage/maintenance.mode') || !Cms\Core\Recovery\RunMode::safeLoadsPlugins($mode)) {
        throw new RuntimeException('CMS is uninstalled, in maintenance, safe, or recovery mode.');
    }
    $pdo = Cms\Core\Database\ConnectionFactory::make($settings);
    $directory = $root . '/storage/restaurant-pos';
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
        throw new RuntimeException('Cannot create private worker state.');
    }
    $lock = fopen($directory . '/reconcile.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another reconciler is running.');
    }
    chmod($directory . '/reconcile.lock', 0600);
    $trust = new Cms\Core\Plugin\OfficialPluginRegistry($root, $pdo);
    if (!$trust->hasActiveTrustGrant('official.restaurant')) {
        throw new RuntimeException('Active restaurant official trust is required.');
    }
    $runtime = new Cms\Core\Plugin\PluginRuntimeRegistry();
    $manager = new Cms\Core\Plugin\PluginManager(
        $root . '/content/plugins',
        $pdo,
        new Cms\Core\Logging\FileLogger($directory . '/bootstrap.log'),
        new Cms\Core\Events\EventDispatcher(),
        new Cms\Core\Plugin\BlockRegistry(),
        $runtime,
        $trust,
        new Cms\Core\Plugin\PluginSecretStore($pdo, (string) $settings->get('security.encryption_key', '')),
        $settings,
    );
    $manager->bootEnabled();
    $enabled = false;
    foreach ($runtime->routes() as $route) {
        if ($route->pluginId === 'official.restaurant' && $route->path === '/restaurant/bridge/jobs/claim') {
            $enabled = true;
        }
    }
    if (!$enabled) {
        throw new RuntimeException('Restaurant plugin is disabled, untrusted, or dependencies failed to boot.');
    }
    $repo = new Official\Restaurant\RestaurantRepository($pdo);
    $tickets = new Official\Restaurant\RestaurantTicketService($repo, new Official\Restaurant\RestaurantPrintService($repo));
    $commerce = new Daiying\Commerce\CommerceRepository($pdo, (string) $settings->get('security.encryption_key', ''));
    $checkout = new Official\Restaurant\RestaurantCheckoutService($repo, $tickets, $commerce, $pdo, (string) $settings->get('security.encryption_key', ''));
    $service = new Official\Restaurant\RestaurantPosService(new Official\Restaurant\RestaurantPosRepository($pdo), $repo, new Cms\Core\Plugin\PluginSecretStore($pdo, (string)$settings->get('security.encryption_key', '')), (string)$settings->get('site.url', ''), [$checkout, 'orderReceiptForCart']);
    $result = $service->run(10);
    echo json_encode(['worker'=>'restaurant-pos','result'=>$result,'physical_print_verified'=>false], JSON_THROW_ON_ERROR), PHP_EOL;
    exit($result['needs_review'] === 0 ? 0 : 1);
} catch (Throwable $exception) {
    if (isset($directory) && is_dir($directory)) {
        (new Cms\Core\Logging\FileLogger($directory . '/bootstrap.log'))->error('Restaurant POS reconciler failed', ['error' => $exception->getMessage()]);
    }
    fwrite(STDERR, "Restaurant POS synchronization stopped; inspect the authenticated POS dashboard. Do not blindly retry uncertain orders.\n");
    exit(1);
}
