<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Media\MediaLibrary;
use Cms\Core\Plugin\LocalPluginPackageInstaller;
use Official\Commerce\Application\CatalogCommand;
use Official\Commerce\Application\CatalogService;
use Official\Commerce\Application\CatalogValidator;
use Official\Commerce\Application\CategoryCommand;
use Official\Commerce\Infrastructure\OutboxEventRepository;
use Official\Commerce\Repository\CatalogRepository;

$root = (string) ($argv[1] ?? '');
$action = (string) ($argv[2] ?? '');
$id = (int) ($argv[3] ?? 0);

require $root . '/system/core/Bootstrap/autoload.php';
require $root . '/content/plugins/official.commerce/plugin.php';

$pdo = ConnectionFactory::make(Settings::load($root));
$adminId = (int) $pdo->query("SELECT id FROM cms_admin_users WHERE email = 'admin@example.test'")->fetchColumn();

function c2a_browser_service(PDO $pdo): CatalogService
{
    $repo = new CatalogRepository($pdo);
    return new CatalogService($pdo, $repo, new CatalogValidator(), new OutboxEventRepository($pdo), new Cms\Core\Audit\AuditLogger($pdo));
}

if ($action === 'enable') {
    (new LocalPluginPackageInstaller($root, $pdo))->installBundled('official.commerce', 1, true);
    echo "enabled\n";
    exit(0);
}

if ($action === 'admin-id') {
    echo (int) $pdo->query("SELECT id FROM cms_admin_users WHERE email = 'admin@example.test'")->fetchColumn() . "\n";
    exit(0);
}

if ($action === 'latest-product-id') {
    echo (int) $pdo->query('SELECT id FROM cms_commerce_products ORDER BY id DESC LIMIT 1')->fetchColumn() . "\n";
    exit(0);
}

if ($action === 'latest-category-id') {
    echo (int) $pdo->query('SELECT id FROM cms_commerce_categories ORDER BY id DESC LIMIT 1')->fetchColumn() . "\n";
    exit(0);
}

if ($action === 'create-category') {
    c2a_browser_service($pdo)->saveCategory(null, new CategoryCommand('Browser Category', 'browser-category'), $adminId);
    echo "category\n";
    exit(0);
}

if ($action === 'create-simple') {
    $mediaId = $id;
    $service = c2a_browser_service($pdo);
    $productId = $service->create(new CatalogCommand('C2A Browser Simple', 'c2a-browser-simple', 'simple', 'Browser simple', [['type' => 'paragraph', 'data' => ['text' => 'Browser structured description']]], 'C2A Browser Simple', 'Browser simple SEO', 'USD', '', $mediaId, [['sku' => 'C2A-BROWSER-SIMPLE', 'price_minor' => 1999, 'variant_status' => 'active', 'media_id' => $mediaId]], [], [(int) $pdo->query('SELECT id FROM cms_commerce_categories ORDER BY id DESC LIMIT 1')->fetchColumn()], [['media_id' => $mediaId, 'role' => 'gallery']], 'c2a-browser-simple'), $adminId);
    $service->publish($productId, $adminId, 'c2a-browser-simple-publish');
    echo $productId . "\n";
    exit(0);
}

if ($action === 'create-variable') {
    $mediaId = $id;
    $service = c2a_browser_service($pdo);
    $productId = $service->create(new CatalogCommand('C2A Browser Variable', 'c2a-browser-variable', 'variable', 'Browser variable', [['type' => 'paragraph', 'data' => ['text' => 'Browser variable description']]], 'C2A Browser Variable', 'Browser variable SEO', 'USD', '', $mediaId, [
        ['sku' => 'C2A-BROWSER-BLACK-S', 'price_minor' => 2999, 'variant_status' => 'active', 'attribute_signature' => 'color:black|size:s', 'media_id' => $mediaId],
        ['sku' => 'C2A-BROWSER-WHITE-M', 'price_minor' => 3099, 'variant_status' => 'active', 'attribute_signature' => 'color:white|size:m'],
    ], [
        ['name' => 'Color', 'values' => ['Black', 'White']],
        ['name' => 'Size', 'values' => ['S', 'M']],
    ], [], [['media_id' => $mediaId, 'role' => 'gallery']], 'c2a-browser-variable'), $adminId);
    $service->publish($productId, $adminId, 'c2a-browser-variable-publish');
    echo $productId . "\n";
    exit(0);
}

if ($action === 'audit-actor') {
    $stmt = $pdo->prepare("SELECT actor_id FROM cms_audit_logs WHERE action = :action ORDER BY id DESC LIMIT 1");
    $stmt->execute([':action' => (string) ($argv[3] ?? '')]);
    echo (int) $stmt->fetchColumn() . "\n";
    exit(0);
}

if ($action === 'disable') {
    (new LocalPluginPackageInstaller($root, $pdo))->disableWithDependents('official.commerce', 1, true);
    echo "disabled\n";
    exit(0);
}

if ($action === 'hard-delete-media') {
    try {
        (new MediaLibrary($pdo, $root . '/content/uploads'))->hardDelete($id);
        echo "deleted\n";
    } catch (Throwable) {
        echo "blocked\n";
    }
    exit(0);
}

fwrite(STDERR, "Unknown action.\n");
exit(1);
