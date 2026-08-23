<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Official\Commerce\Application\CatalogCommand;
use Official\Commerce\Application\CatalogService;
use Official\Commerce\Application\CatalogValidator;
use Official\Commerce\Infrastructure\OutboxEventRepository;
use Official\Commerce\Repository\CatalogRepository;

$root = (string) ($argv[1] ?? '');
$action = (string) ($argv[2] ?? '');
$mediaId = (int) ($argv[3] ?? 0);
$id = (int) ($argv[4] ?? 0);

if ($root === '') {
    fwrite(STDERR, "Missing root.\n");
    exit(1);
}

require $root . '/system/core/Bootstrap/autoload.php';
require $root . '/content/plugins/official.commerce/plugin.php';

$pdo = ConnectionFactory::make(Settings::load($root));
$repo = new CatalogRepository($pdo);
$service = new CatalogService($pdo, $repo, new CatalogValidator(), new OutboxEventRepository($pdo), new Cms\Core\Audit\AuditLogger($pdo));

if ($action === 'enable') {
    (new Cms\Core\Plugin\LocalPluginPackageInstaller($root, $pdo))->installBundled('official.commerce', 1, true);
    echo "enabled\n";
    exit(0);
}

if ($action === 'simple') {
    $productId = $service->create(new CatalogCommand('Browser Simple Product', 'browser-simple-product', 'simple', 'Browser summary', [['type' => 'paragraph', 'data' => ['text' => 'Browser description']]], '', '', 'USD', '', $mediaId ?: null, [['sku' => 'BROWSER-SIMPLE-1', 'price_minor' => 1999, 'variant_status' => 'active']], [], [], [['media_id' => $mediaId, 'role' => 'gallery']], 'browser-simple'), 1);
    $service->publish($productId, 1, 'browser-simple-publish');
    echo $productId . "\n";
    exit(0);
}

if ($action === 'variable') {
    $productId = $service->create(new CatalogCommand('Browser Variable Product', 'browser-variable-product', 'variable', 'Browser variable summary', [['type' => 'paragraph', 'data' => ['text' => 'Browser variable description']]], '', '', 'USD', '', $mediaId ?: null, [
        ['sku' => 'BROWSER-VAR-BLACK-S', 'price_minor' => 2999, 'variant_status' => 'active', 'attribute_signature' => 'color:black|size:s', 'media_id' => $mediaId],
        ['sku' => 'BROWSER-VAR-WHITE-M', 'price_minor' => 3099, 'variant_status' => 'out_of_stock', 'attribute_signature' => 'color:white|size:m'],
    ], [
        ['name' => 'Color', 'values' => ['Black', 'White']],
        ['name' => 'Size', 'values' => ['S', 'M']],
    ], [], [['media_id' => $mediaId, 'role' => 'gallery']], 'browser-variable'), 1);
    $service->publish($productId, 1, 'browser-variable-publish');
    echo $productId . "\n";
    exit(0);
}

if ($action === 'archive') {
    $service->archive($id, 1, 'browser-archive');
    echo "archived\n";
    exit(0);
}

fwrite(STDERR, "Unknown action.\n");
exit(1);
