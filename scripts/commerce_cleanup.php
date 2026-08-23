<?php

declare(strict_types=1);

use Cms\Core\Audit\AuditLogger;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Official\Commerce\Application\CartService;
use Official\Commerce\Application\CheckoutService;
use Official\Commerce\Application\InventoryService;
use Official\Commerce\Infrastructure\FlatRateShippingProvider;
use Official\Commerce\Infrastructure\NoTaxCalculator;
use Official\Commerce\Infrastructure\OutboxEventRepository;
use Official\Commerce\Repository\CartRepository;
use Official\Commerce\Repository\InventoryRepository;
use Official\Commerce\Repository\OrderRepository;

$root = dirname(__DIR__);
require $root . '/system/core/Bootstrap/autoload.php';
require $root . '/content/plugins/official.commerce/plugin.php';

$settings = Settings::load($root);
$pdo = ConnectionFactory::make($settings);
$lock = $root . '/storage/tmp/commerce-cleanup.lock';
$handle = fopen($lock, 'c');
if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "Commerce cleanup already running.\n");
    exit(0);
}

$outbox = new OutboxEventRepository($pdo);
$audit = new AuditLogger($pdo);
$inventoryRepo = new InventoryRepository($pdo);
$cartRepo = new CartRepository($pdo);
$orderRepo = new OrderRepository($pdo);
$inventory = new InventoryService($pdo, $inventoryRepo, $outbox, $audit);
$cart = new CartService($pdo, $cartRepo, $outbox);
$checkout = new CheckoutService($pdo, $cartRepo, $inventoryRepo, $orderRepo, new FlatRateShippingProvider(), new NoTaxCalculator(), $outbox, $audit);
$expiredReservations = $inventory->expireDue(100);
$expiredCarts = $cart->expireCarts(100);
$expiredOrders = $checkout->expirePendingOrders(100);

fwrite(STDOUT, 'Commerce cleanup completed reservations=' . $expiredReservations . ' carts=' . $expiredCarts . ' orders=' . $expiredOrders . PHP_EOL);
