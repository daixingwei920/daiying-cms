<?php

declare(strict_types=1);

use Cms\Core\Audit\AuditLogger;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Official\Commerce\Application\FulfillmentService;
use Official\Commerce\Infrastructure\FakeFulfillmentProvider;
use Official\Commerce\Infrastructure\OutboxEventRepository;
use Official\Commerce\Repository\FulfillmentRepository;
use Official\Commerce\Repository\OrderRepository;

$root = dirname(__DIR__);
require $root . '/system/core/Bootstrap/autoload.php';
require $root . '/content/plugins/official.commerce/plugin.php';

$settings = Settings::load($root);
$pdo = ConnectionFactory::make($settings);
$lock = $root . '/storage/tmp/commerce-fulfillment.lock';
$handle = fopen($lock, 'c');
if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "Commerce fulfillment already running.\n");
    exit(0);
}

$outbox = new OutboxEventRepository($pdo);
$audit = new AuditLogger($pdo);
$orders = new OrderRepository($pdo);
$fulfillments = new FulfillmentRepository($pdo);
$service = new FulfillmentService($pdo, $orders, $fulfillments, new FakeFulfillmentProvider(), $outbox, $audit);
$submitted = $service->submitPaidOrders(100);

fwrite(STDOUT, 'Commerce fulfillment completed submitted=' . $submitted . PHP_EOL);

