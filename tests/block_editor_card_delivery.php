<?php

declare(strict_types=1);

use Cms\Core\Admin\AdminController;
use Cms\Core\Bootstrap\Application;
use Cms\Core\CardDelivery\CardDeliveryRepository;
use Cms\Core\CardDelivery\CardDeliveryService;
use Cms\Core\CardDelivery\CardDeliveryController;
use Cms\Core\Config\Settings;
use Cms\Core\Content\ContentFrontController;
use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Logging\FileLogger;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Payment\FixturePaymentProvider;
use Cms\Core\Payment\PaymentException;
use Cms\Core\Payment\PaymentProviderRegistry;
use Cms\Core\Payment\PaymentProviderSettingsRepository;
use Cms\Core\Payment\PaymentRepository;
use Cms\Core\Payment\PaymentService;
use Cms\Core\Payment\PaymentWebhookController;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Security\SessionManager;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;

function block_card_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function block_card_remove(string $path): void
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

function block_card_write(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($path, $contents);
}

function block_card_copy_dir(string $source, string $target): void
{
    if (!is_dir($target)) {
        mkdir($target, 0755, true);
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($items as $item) {
        $destination = $target . '/' . $items->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($destination)) {
                mkdir($destination, 0755, true);
            }
        } else {
            copy((string) $item->getPathname(), $destination);
        }
    }
}

function block_card_table_columns(PDO $pdo, string $table): array
{
    $columns = [];
    foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll() as $row) {
        $columns[] = (string) ($row['name'] ?? '');
    }

    return $columns;
}

function block_card_indexes(PDO $pdo, string $table): array
{
    $indexes = [];
    foreach ($pdo->query('PRAGMA index_list(' . $table . ')')->fetchAll() as $row) {
        $indexes[(string) ($row['name'] ?? '')] = (int) ($row['unique'] ?? 0);
    }

    return $indexes;
}

SessionManager::start(false);

$root = sys_get_temp_dir() . '/cms-block-card-delivery-' . bin2hex(random_bytes(4));
block_card_remove($root);
foreach (['config', 'system/core', 'system/admin', 'system/recovery', 'system/migrations', 'storage/logs', 'storage/cache', 'storage/tmp', 'storage/database', 'content/themes/default/templates', 'content/themes/safe/templates', 'content/plugins', 'content/uploads'] as $dir) {
    mkdir($root . '/' . $dir, 0755, true);
}
block_card_copy_dir(CMS_SOURCE_ROOT . '/content/themes/default', $root . '/content/themes/default');
block_card_copy_dir(CMS_SOURCE_ROOT . '/content/themes/safe', $root . '/content/themes/safe');
block_card_write($root . '/content/themes/default/templates/content.php', <<<'PHP'
<?php
use Cms\Core\Theme\TemplateContext;
/** @var TemplateContext $context */
?>
<!doctype html><html lang="zh-CN"><body><main><?= $context->get('rendered_blocks', '') ?></main></body></html>
PHP);

$config = require CMS_SOURCE_ROOT . '/config/app.php';
$config['database'] = ['dsn' => 'sqlite:' . $root . '/storage/database/test.sqlite', 'username' => '', 'password' => '', 'options' => []];
$config['site'] = ['name' => 'Block Card Site', 'url' => 'https://cms.example.test', 'id' => 'block-card', 'secret' => 'block-card-secret'];
$config['security']['encryption_key'] = 'block-card-delivery-secret-key';
block_card_write($root . '/config/app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");

$settings = Settings::load($root);
$pdo = ConnectionFactory::make($settings);
$migrations = [];
foreach (glob(CMS_SOURCE_ROOT . '/system/migrations/*.php') ?: [] as $file) {
    $migrations[] = require $file;
}
(new MigrationRunner($pdo, $migrations))->run();
block_card_write($root . '/storage/installed.lock', '{}');

$productColumns = block_card_table_columns($pdo, 'cms_card_products');
$inventoryColumns = block_card_table_columns($pdo, 'cms_card_inventory');
$orderColumns = block_card_table_columns($pdo, 'cms_card_orders');
$deliveryColumns = block_card_table_columns($pdo, 'cms_card_deliveries');
$inventoryIndexes = block_card_indexes($pdo, 'cms_card_inventory');
$orderIndexes = block_card_indexes($pdo, 'cms_card_orders');
$deliveryIndexes = block_card_indexes($pdo, 'cms_card_deliveries');
block_card_check(
    count(array_intersect(['id', 'name', 'price_minor', 'currency', 'status', 'max_quantity_per_order', 'commerce_product_id', 'created_at', 'updated_at'], $productColumns)) === 9
    && count(array_intersect(['id', 'product_id', 'secret_value', 'secret_hash', 'status', 'order_id', 'delivery_id', 'created_at', 'delivered_at'], $inventoryColumns)) === 9
    && count(array_intersect(['id', 'product_id', 'quantity', 'amount_minor', 'currency', 'status', 'payment_id', 'idempotency_key', 'paid_at'], $orderColumns)) === 9
    && count(array_intersect(['id', 'product_id', 'inventory_id', 'order_id', 'order_item_index', 'transaction_id', 'status', 'delivered_at'], $deliveryColumns)) === 8,
    'Card Delivery migration creates product, inventory, order and delivery tables with required Core fields'
);
block_card_check(
    ($inventoryIndexes['cms_card_inventory_secret_hash_unique'] ?? 0) === 1
    && ($orderIndexes['cms_card_orders_idempotency_unique'] ?? 0) === 1
    && ($deliveryIndexes['cms_card_deliveries_order_product_item_unique'] ?? 0) === 1,
    'Card Delivery migration creates unique constraints for secret hashes, order idempotency and per-order item delivery'
);

$app = Application::boot($root);
$checkoutOptions = $app->handle(new Request('OPTIONS', '/card-delivery/1/checkout'));
$completeOptions = $app->handle(new Request('OPTIONS', '/card-delivery/orders/1/complete'));
$adminOptions = $app->handle(new Request('OPTIONS', '/admin/card-delivery'));
$adminImportOptions = $app->handle(new Request('OPTIONS', '/admin/card-delivery/inventory/1/import'));
block_card_check(
    $checkoutOptions->status() === 204 && str_contains((string) ($checkoutOptions->headers()['Allow'] ?? ''), 'POST')
    && $completeOptions->status() === 204 && str_contains((string) ($completeOptions->headers()['Allow'] ?? ''), 'GET')
    && $adminOptions->status() === 204 && str_contains((string) ($adminOptions->headers()['Allow'] ?? ''), 'GET')
    && $adminImportOptions->status() === 204 && str_contains((string) ($adminImportOptions->headers()['Allow'] ?? ''), 'POST'),
    'Application registers Core Card Delivery frontend and admin routes without plugin routing'
);

$_SESSION['admin_user'] = ['id' => 1, 'email' => 'admin@example.test', 'display_name' => 'Admin'];
$csrf = CsrfToken::get();
$admin = new AdminController($settings, new FileLogger($root . '/storage/logs/app.log'), $root);
$repo = new CardDeliveryRepository($pdo, 'block-card-delivery-secret-key');
$productId = $repo->saveProduct(null, 'Steam 激活码', 999, 'USD', 'active', 1);
$imported = $repo->importInventory($productId, "AAAA-BBBB-CCCC-1234\nDDDD-EEEE-FFFF-5678\n");
block_card_check($imported === 2, 'Card Delivery imports one-secret-per-line inventory outside content block JSON');
$storedSecret = (string) $pdo->query('SELECT secret_value FROM cms_card_inventory WHERE product_id = ' . (int) $productId . ' ORDER BY id LIMIT 1')->fetchColumn();
block_card_check(str_starts_with($storedSecret, 'enc:v1:') && !str_contains($storedSecret, 'AAAA-BBBB'), 'Card Delivery stores imported card secrets encrypted when Core security key is configured');

$adminCreateForm = $admin->cardDeliveryCreate();
block_card_check($adminCreateForm->status() === 200 && str_contains($adminCreateForm->body(), '新建发卡商品') && str_contains($adminCreateForm->body(), '保存商品后可批量导入卡密'), 'admin Card Delivery create screen exposes product form and deferred inventory import guidance');
$productCountBeforeGetStore = (int) $pdo->query('SELECT COUNT(*) FROM cms_card_products')->fetchColumn();
$adminGetStore = $admin->cardDeliveryStore(new Request('GET', '/admin/card-delivery', [], [
    '_csrf' => $csrf,
    'name' => 'GET 不应创建发卡商品',
    'price_minor' => '1999',
    'currency' => 'USD',
    'status' => 'active',
    'max_quantity_per_order' => '2',
]));
block_card_check(
    $adminGetStore->status() === 405
    && ($adminGetStore->headers()['Allow'] ?? '') === 'POST'
    && str_contains($adminGetStore->body(), '发卡商品保存必须通过 POST 请求提交')
    && (int) $pdo->query('SELECT COUNT(*) FROM cms_card_products')->fetchColumn() === $productCountBeforeGetStore,
    'admin Card Delivery product save rejects non-POST direct calls without creating products'
);
$adminStore = $admin->cardDeliveryStore(new Request('POST', '/admin/card-delivery', [], [
    '_csrf' => $csrf,
    'name' => '后台创建发卡商品',
    'price_minor' => '1999',
    'currency' => 'USD',
    'status' => 'active',
    'max_quantity_per_order' => '2',
    'commerce_product_id' => '0',
    'description' => '后台创建测试',
]));
$adminProductLocation = (string) ($adminStore->headers()['Location'] ?? '');
$adminProductId = preg_match('#/admin/card-delivery/edit/([1-9][0-9]*)$#', $adminProductLocation, $adminProductMatch) === 1 ? (int) $adminProductMatch[1] : 0;
block_card_check($adminStore->status() === 302 && $adminProductId > 0, 'admin Card Delivery creates a card product and redirects to inventory management');
$inventoryCountBeforeGetImport = (int) $pdo->query('SELECT COUNT(*) FROM cms_card_inventory WHERE product_id = ' . (int) $adminProductId)->fetchColumn();
$adminGetImport = $admin->cardDeliveryInventoryImport(new Request('GET', '/admin/card-delivery/inventory/' . $adminProductId . '/import', [], [
    '_csrf' => $csrf,
    'secrets_text' => "GET-CARD-0001\n",
]));
block_card_check(
    $adminGetImport->status() === 405
    && ($adminGetImport->headers()['Allow'] ?? '') === 'POST'
    && str_contains($adminGetImport->body(), '库存导入必须通过 POST 请求提交')
    && (int) $pdo->query('SELECT COUNT(*) FROM cms_card_inventory WHERE product_id = ' . (int) $adminProductId)->fetchColumn() === $inventoryCountBeforeGetImport,
    'admin Card Delivery inventory import rejects non-POST direct calls without importing inventory'
);
$adminImport = $admin->cardDeliveryInventoryImport(new Request('POST', '/admin/card-delivery/inventory/' . $adminProductId . '/import', [], [
    '_csrf' => $csrf,
    'secrets_text' => "ADMIN-CARD-1111\nADMIN-CARD-2222\n",
]));
$adminCsvImport = $admin->cardDeliveryInventoryImport(new Request('POST', '/admin/card-delivery/inventory/' . $adminProductId . '/import', [], [
    '_csrf' => $csrf,
    'secrets_text' => "secret,note\nADMIN-CSV-CARD-3333,first csv row\nADMIN-CSV-CARD-4444,second csv row\n",
]));
$adminEdit = $admin->cardDeliveryEdit(new Request('GET', '/admin/card-delivery/edit/' . $adminProductId));
block_card_check($adminImport->status() === 302 && $adminCsvImport->status() === 302 && $adminEdit->status() === 200 && str_contains($adminEdit->body(), 'CSV 第一列为卡密') && str_contains($adminEdit->body(), 'class="admin-danger"') && str_contains($adminEdit->body(), CardDeliveryRepository::maskSecret('ADMIN-CARD-1111')) && str_contains($adminEdit->body(), CardDeliveryRepository::maskSecret('ADMIN-CSV-CARD-3333')) && !str_contains($adminEdit->body(), 'ADMIN-CARD-1111') && !str_contains($adminEdit->body(), 'ADMIN-CSV-CARD-3333'), 'admin Card Delivery imports line-based and CSV first-column inventory and displays masked card secrets only');
$adminInventoryId = (int) $pdo->query('SELECT id FROM cms_card_inventory WHERE product_id = ' . $adminProductId . ' ORDER BY id LIMIT 1')->fetchColumn();
$adminDisableGet = $admin->cardDeliveryInventoryDisable(new Request('GET', '/admin/card-delivery/inventory/disable/' . $adminInventoryId, [], [
    '_csrf' => $csrf,
    'product_id' => (string) $adminProductId,
]));
block_card_check(
    $adminDisableGet->status() === 405
    && ($adminDisableGet->headers()['Allow'] ?? '') === 'POST'
    && str_contains($adminDisableGet->body(), '库存禁用必须通过 POST 请求提交')
    && (string) $pdo->query('SELECT status FROM cms_card_inventory WHERE id = ' . $adminInventoryId)->fetchColumn() === 'available',
    'admin Card Delivery inventory disable rejects non-POST direct calls without changing inventory status'
);
$adminDisable = $admin->cardDeliveryInventoryDisable(new Request('POST', '/admin/card-delivery/inventory/disable/' . $adminInventoryId, [], [
    '_csrf' => $csrf,
    'product_id' => (string) $adminProductId,
]));
$disabledStatus = (string) $pdo->query('SELECT status FROM cms_card_inventory WHERE id = ' . $adminInventoryId)->fetchColumn();
$adminIndex = $admin->cardDeliveryIndex();
block_card_check($adminDisable->status() === 302 && $disabledStatus === 'disabled' && $adminIndex->status() === 200 && str_contains($adminIndex->body(), '发卡商品') && str_contains($adminIndex->body(), '发卡订单') && str_contains($adminIndex->body(), '发卡记录'), 'admin Card Delivery lists products, orders and deliveries and can disable available inventory');

$newForm = $admin->contentCreate()->body();
block_card_check(str_contains($newForm, 'data-block-type-select') && str_contains($newForm, 'data-block-editor-fields') && str_contains($newForm, 'window.CMS_CARD_PRODUCTS'), 'content editor exposes dynamic Block Editor UI Renderer hooks');
block_card_check(str_contains($newForm, 'card_delivery') && str_contains($newForm, '自动发卡') && str_contains($newForm, 'block-table-editor') && str_contains($newForm, 'block-list-editor'), 'dynamic renderer includes dedicated table, list and card delivery editing UIs');
block_card_check(str_contains($newForm, "document.addEventListener('change'") && str_contains($newForm, "fields.innerHTML=render(i,select.value)"), 'Block Editor UI Renderer immediately rerenders fields when block type changes');
block_card_check(str_contains($newForm, "type==='paragraph'") && str_contains($newForm, "type==='heading'") && str_contains($newForm, "type==='image'") && str_contains($newForm, "type==='card_delivery'"), 'Block Editor UI Renderer maps paragraph, heading, image and card_delivery to distinct editing components');
block_card_check(str_contains($newForm, "name=\"'+name(i,'style')+'\"") && str_contains($newForm, "name=\"'+name(i,'bold')+'\"") && str_contains($newForm, "name=\"'+name(i,'italic')+'\""), 'paragraph block editor exposes body style and basic formatting controls');
block_card_check(str_contains($newForm, 'H1') && str_contains($newForm, "media(i,'media_id','image'") && str_contains($newForm, '发卡商品<select') && str_contains($newForm, '每单最多'), 'dynamic switch targets expose heading level, image picker and full card delivery product controls');
block_card_check(str_contains($newForm, 'data-card-product-select') && str_contains($newForm, 'data-card-product-summary') && str_contains($newForm, '商品名称') && str_contains($newForm, '当前库存'), 'card_delivery block editor exposes a dedicated selected-product summary panel');
block_card_check(str_contains($newForm, '上传/管理媒体') && str_contains($newForm, "name=\"'+name(i,'width')+'\"") && str_contains($newForm, '显示宽度（px）'), 'image and media block editors expose upload entry and image size controls');
block_card_check(str_contains($newForm, "type==='gallery'") && str_contains($newForm, 'data-gallery-editor') && str_contains($newForm, 'data-gallery-caption-output') && str_contains($newForm, '单图 Caption'), 'gallery block editor exposes multi-image picker, ordering/removal controls and per-image Caption UI');
block_card_check(str_contains($newForm, "name=\"'+name(i,'source_url')+'\"") && str_contains($newForm, "name=\"'+name(i,'autoplay')+'\"") && str_contains($newForm, '自动播放（将自动静音）'), 'video block editor exposes safe external URL and playback behavior settings');
block_card_check(str_contains($newForm, "name=\"'+name(i,'show_price')+'\" value=\"0\"") && str_contains($newForm, "name=\"'+name(i,'show_button')+'\" value=\"0\""), 'dynamic card_delivery renderer preserves unchecked display toggles with hidden false inputs');
block_card_check(str_contains($newForm, "name=\"'+name(i,'controls')+'\" value=\"0\""), 'dynamic audio and video renderers preserve unchecked media controls with hidden false inputs');

$paragraphFormatCreate = $admin->contentStore(new Request('POST', '/admin/content', [], [
    '_csrf' => $csrf,
    'content_type' => 'article',
    'title' => 'Paragraph Format Article',
    'slug' => 'paragraph-format-article',
    'status' => 'published',
    'content_action' => 'publish',
    'blocks' => [
        [
            'type' => 'paragraph',
            'data' => [
                'text' => 'Lead paragraph',
                'style' => 'lead',
                'bold' => '1',
                'italic' => '1',
                'alignment' => 'center',
                'link' => 'https://example.test/lead',
            ],
        ],
        [
            'type' => 'paragraph',
            'data' => [
                'text' => 'Plain paragraph',
                'style' => 'unsafe-style',
                'bold' => '0',
                'italic' => '0',
                'alignment' => 'left',
                'link' => 'javascript:alert(1)',
            ],
        ],
    ],
]));
$paragraphFormatContent = (new ContentRepository($pdo, ContentTypeRegistry::defaults()))->publicBySlug('article', 'paragraph-format-article');
$paragraphFormatBlocks = is_array($paragraphFormatContent) ? ($paragraphFormatContent['blocks'] ?? []) : [];
block_card_check($paragraphFormatCreate->status() === 302 && ($paragraphFormatBlocks[0]['data']['style'] ?? '') === 'lead' && ($paragraphFormatBlocks[0]['data']['bold'] ?? false) === true && ($paragraphFormatBlocks[0]['data']['italic'] ?? false) === true && ($paragraphFormatBlocks[1]['data']['style'] ?? '') === 'body' && ($paragraphFormatBlocks[1]['data']['link'] ?? 'unsafe') === '', 'paragraph block editor saves controlled format fields and strips unsafe values');
$frontForParagraphFormat = new ContentFrontController($root, $settings, new FileLogger($root . '/storage/logs/app.log'));
$paragraphFormatArticle = $frontForParagraphFormat->article(new Request('GET', '/articles/paragraph-format-article'));
block_card_check($paragraphFormatArticle->status() === 200 && str_contains($paragraphFormatArticle->body(), 'class="paragraph-lead text-align-center"') && str_contains($paragraphFormatArticle->body(), '<a href="https://example.test/lead"><em><strong>Lead paragraph</strong></em></a>') && !str_contains($paragraphFormatArticle->body(), 'javascript:alert'), 'frontend renders paragraph basic formatting as safe structure and classes');

$mediaControlsCreate = $admin->contentStore(new Request('POST', '/admin/content', [], [
    '_csrf' => $csrf,
    'content_type' => 'article',
    'title' => 'Media Controls Article',
    'slug' => 'media-controls-article',
    'status' => 'published',
    'content_action' => 'publish',
    'blocks' => [
        [
            'type' => 'audio',
            'data' => ['media_id' => '0', 'title' => '无控制条音频', 'controls' => '0', 'preload' => 'none'],
        ],
        [
            'type' => 'video',
            'data' => ['media_id' => '0', 'poster_media_id' => '0', 'controls' => '0', 'preload' => 'none'],
        ],
    ],
]));
$mediaControlsContent = (new ContentRepository($pdo, ContentTypeRegistry::defaults()))->publicBySlug('article', 'media-controls-article');
$mediaControlsBlocks = is_array($mediaControlsContent) ? ($mediaControlsContent['blocks'] ?? []) : [];
block_card_check($mediaControlsCreate->status() === 302 && ($mediaControlsBlocks[0]['data']['controls'] ?? true) === false && ($mediaControlsBlocks[1]['data']['controls'] ?? true) === false, 'audio and video block editors save unchecked controls as explicit false values');

$externalVideoCreate = $admin->contentStore(new Request('POST', '/admin/content', [], [
    '_csrf' => $csrf,
    'content_type' => 'article',
    'title' => 'External Video Article',
    'slug' => 'external-video-article',
    'status' => 'published',
    'content_action' => 'publish',
    'blocks' => [
        [
            'type' => 'video',
            'data' => [
                'media_id' => '0',
                'poster_media_id' => '0',
                'source_url' => 'https://cdn.example.test/demo.mp4',
                'controls' => '0',
                'autoplay' => '1',
                'muted' => '0',
                'loop' => '1',
                'playsinline' => '1',
                'preload' => 'none',
            ],
        ],
        [
            'type' => 'video',
            'data' => [
                'media_id' => '0',
                'poster_media_id' => '0',
                'source_url' => 'javascript:alert(1)',
                'controls' => '1',
                'autoplay' => '0',
                'muted' => '0',
                'loop' => '0',
                'playsinline' => '0',
                'preload' => 'metadata',
            ],
        ],
    ],
]));
$externalVideoContent = (new ContentRepository($pdo, ContentTypeRegistry::defaults()))->publicBySlug('article', 'external-video-article');
$externalVideoBlocks = is_array($externalVideoContent) ? ($externalVideoContent['blocks'] ?? []) : [];
block_card_check($externalVideoCreate->status() === 302 && ($externalVideoBlocks[0]['data']['source_url'] ?? '') === 'https://cdn.example.test/demo.mp4' && ($externalVideoBlocks[0]['data']['autoplay'] ?? false) === true && ($externalVideoBlocks[0]['data']['muted'] ?? false) === true && ($externalVideoBlocks[1]['data']['source_url'] ?? 'not-cleaned') === '', 'video block sanitizer keeps safe external media URLs, strips unsafe URLs and forces autoplay videos muted');
$frontForExternalVideo = new ContentFrontController($root, $settings, new FileLogger($root . '/storage/logs/app.log'));
$externalVideoArticle = $frontForExternalVideo->article(new Request('GET', '/articles/external-video-article'));
block_card_check($externalVideoArticle->status() === 200 && str_contains($externalVideoArticle->body(), 'src="https://cdn.example.test/demo.mp4"') && str_contains($externalVideoArticle->body(), ' autoplay') && str_contains($externalVideoArticle->body(), ' muted') && str_contains($externalVideoArticle->body(), ' loop') && str_contains($externalVideoArticle->body(), ' playsinline') && !str_contains($externalVideoArticle->body(), 'javascript:alert'), 'frontend renders sanitized external video source with safe playback attributes');

$mediaFixture = $pdo->prepare("INSERT INTO cms_media (id, storage_provider, media_type, mime_type, original_name, relative_path, byte_size, sha256_hash, metadata_json, created_at, updated_at, status) VALUES (:id, 'local', 'image', 'image/png', :name, :path, 10, :hash, '{}', :created, :updated, 'Active')");
foreach ([4 => 'single-image.png', 12 => 'gallery-a.png', 8 => 'gallery-b.png', 15 => 'gallery-c.png'] as $mediaId => $mediaName) {
    block_card_write($root . '/content/uploads/gallery/' . $mediaName, 'image-data-' . $mediaId);
    $mediaFixture->execute([
        ':id' => $mediaId,
        ':name' => $mediaName,
        ':path' => 'gallery/' . $mediaName,
        ':hash' => hash('sha256', 'gallery-caption-fixture-' . $mediaId),
        ':created' => gmdate('c'),
        ':updated' => gmdate('c'),
    ]);
}
$imageSizeCreate = $admin->contentStore(new Request('POST', '/admin/content', [], [
    '_csrf' => $csrf,
    'content_type' => 'article',
    'title' => 'Image Size Article',
    'slug' => 'image-size-article',
    'status' => 'published',
    'content_action' => 'publish',
    'blocks' => [[
        'type' => 'image',
        'data' => [
            'media_id' => '4',
            'alt' => '带尺寸图片',
            'caption' => '图片尺寸说明',
            'width' => '640',
            'alignment' => 'center',
            'link' => 'https://example.test/image',
        ],
    ]],
]));
$imageSizeContent = (new ContentRepository($pdo, ContentTypeRegistry::defaults()))->publicBySlug('article', 'image-size-article');
$imageSizeBlock = is_array($imageSizeContent) ? ($imageSizeContent['blocks'][0]['data'] ?? []) : [];
$frontForImageSize = new ContentFrontController($root, $settings, new FileLogger($root . '/storage/logs/app.log'));
$imageSizeArticle = $frontForImageSize->article(new Request('GET', '/articles/image-size-article'));
block_card_check($imageSizeCreate->status() === 302 && (int) ($imageSizeBlock['width'] ?? 0) === 640, 'image block editor saves sanitized image width setting');
block_card_check($imageSizeArticle->status() === 200 && str_contains($imageSizeArticle->body(), 'width="640"') && str_contains($imageSizeArticle->body(), '带尺寸图片'), 'frontend renders image block width attribute from sanitized size setting');
$galleryCreate = $admin->contentStore(new Request('POST', '/admin/content', [], [
    '_csrf' => $csrf,
    'content_type' => 'article',
    'title' => 'Gallery Caption Article',
    'slug' => 'gallery-caption-article',
    'status' => 'published',
    'content_action' => 'publish',
    'blocks' => [[
        'type' => 'gallery',
        'data' => [
            'media_ids_text' => '12, 8, 15',
            'columns' => '4',
            'caption' => '整组图片说明',
            'gallery_captions_text' => "12 | 第一张说明\n8 | 第二张说明\n15 | 第三张说明",
        ],
    ]],
]));
$galleryContent = (new ContentRepository($pdo, ContentTypeRegistry::defaults()))->publicBySlug('article', 'gallery-caption-article');
$galleryBlock = is_array($galleryContent) ? ($galleryContent['blocks'][0]['data'] ?? []) : [];
$galleryItems = is_array($galleryBlock['items'] ?? null) ? $galleryBlock['items'] : [];
block_card_check($galleryCreate->status() === 302 && ($galleryBlock['media_ids'] ?? []) === [12, 8, 15] && (int) ($galleryBlock['columns'] ?? 0) === 4, 'gallery block editor saves selected image order and layout columns');
block_card_check(count($galleryItems) === 3 && (string) ($galleryItems[1]['caption'] ?? '') === '第二张说明', 'gallery block editor saves per-image Caption values into sanitized gallery items');

$create = $admin->contentStore(new Request('POST', '/admin/content', [], [
    '_csrf' => $csrf,
    'content_type' => 'article',
    'title' => 'Card Article',
    'slug' => 'card-article',
    'status' => 'published',
    'content_action' => 'publish',
    'blocks' => [[
        'type' => 'card_delivery',
        'data' => [
            'card_product_id' => (string) $productId,
            'show_name' => '1',
            'show_price' => '1',
            'show_stock' => '1',
            'show_button' => '1',
            'button_text' => '立即购买',
            'secret_value' => 'SHOULD-NOT-STAY-IN-BLOCK',
        ],
    ]],
]));
block_card_check($create->status() === 302, 'content editor saves card_delivery block');
$content = (new ContentRepository($pdo, ContentTypeRegistry::defaults()))->publicBySlug('article', 'card-article');
$blocks = is_array($content) ? ($content['blocks'] ?? []) : [];
$blockData = is_array($blocks[0]['data'] ?? null) ? $blocks[0]['data'] : [];
block_card_check(($blocks[0]['type'] ?? '') === 'card_delivery' && ($blockData['card_product_id'] ?? 0) === $productId, 'card_delivery block stores only product reference and display settings');
block_card_check(!array_key_exists('secret_value', $blockData), 'card_delivery block JSON excludes raw card secrets');
$contentId = (int) ($content['id'] ?? 0);
$editForm = $admin->contentEdit(new Request('GET', '/admin/content/edit/' . $contentId))->body();
block_card_check(str_contains($editForm, 'value="' . $productId . '" selected') && str_contains($editForm, '立即购买') && str_contains($editForm, '<dt>商品名称</dt><dd>Steam 激活码</dd>') && str_contains($editForm, '<dt>当前库存</dt><dd>2</dd>'), 'saved card_delivery block reloads in the editor without losing product, summary or button settings');

$headingUpdate = $admin->contentUpdate(new Request('POST', '/admin/content/edit/' . $contentId, [], [
    '_csrf' => $csrf,
    'content_type' => 'article',
    'title' => 'Card Article',
    'slug' => 'card-article',
    'status' => 'published',
    'content_action' => 'save',
    'blocks' => [[
        'type' => 'heading',
        'data' => ['level' => '3', 'text' => '切换后的标题', 'alignment' => 'center'],
    ]],
]));
$headingContent = (new ContentRepository($pdo, ContentTypeRegistry::defaults()))->publicBySlug('article', 'card-article');
$headingBlocks = is_array($headingContent) ? ($headingContent['blocks'] ?? []) : [];
block_card_check($headingUpdate->status() === 302 && ($headingBlocks[0]['type'] ?? '') === 'heading' && (int) ($headingBlocks[0]['data']['level'] ?? 0) === 3, 'switching card_delivery to heading then saving preserves the selected heading component data');

$imageUpdate = $admin->contentUpdate(new Request('POST', '/admin/content/edit/' . $contentId, [], [
    '_csrf' => $csrf,
    'content_type' => 'article',
    'title' => 'Card Article',
    'slug' => 'card-article',
    'status' => 'published',
    'content_action' => 'save',
    'blocks' => [[
        'type' => 'image',
        'data' => ['media_id' => '0', 'alt' => '切换后的图片', 'caption' => '图片说明', 'alignment' => 'center', 'link' => 'https://example.test/image'],
    ]],
]));
$imageContent = (new ContentRepository($pdo, ContentTypeRegistry::defaults()))->publicBySlug('article', 'card-article');
$imageBlocks = is_array($imageContent) ? ($imageContent['blocks'] ?? []) : [];
block_card_check($imageUpdate->status() === 302 && ($imageBlocks[0]['type'] ?? '') === 'image' && (string) ($imageBlocks[0]['data']['alt'] ?? '') === '切换后的图片', 'switching heading to image then saving preserves image configuration data');

$cardUpdate = $admin->contentUpdate(new Request('POST', '/admin/content/edit/' . $contentId, [], [
    '_csrf' => $csrf,
    'content_type' => 'article',
    'title' => 'Card Article',
    'slug' => 'card-article',
    'status' => 'published',
    'content_action' => 'save',
    'blocks' => [[
        'type' => 'card_delivery',
        'data' => [
            'card_product_id' => (string) $productId,
            'show_name' => '1',
            'show_price' => '1',
            'show_stock' => '1',
            'show_button' => '1',
            'button_text' => '立即购买',
        ],
    ]],
]));
block_card_check($cardUpdate->status() === 302, 'switching image to card_delivery saves the card delivery component data');

$unknownContentId = (new ContentRepository($pdo, ContentTypeRegistry::defaults()))->create('article', 'Unknown Block Article', 'unknown-block-article', [[
    'type' => 'legacy_custom_block',
    'plugin_id' => 'missing.plugin',
    'data' => ['text' => 'legacy data'],
]], 'draft');
$unknownEdit = $admin->contentEdit(new Request('GET', '/admin/content/edit/' . $unknownContentId));
block_card_check($unknownEdit->status() === 200 && str_contains($unknownEdit->body(), 'missing-extension'), 'unknown old block type opens in the editor with safe fallback');

$front = new ContentFrontController($root, $settings, new FileLogger($root . '/storage/logs/app.log'));
$article = $front->article(new Request('GET', '/articles/card-article'));
block_card_check($article->status() === 200 && str_contains($article->body(), 'Steam 激活码') && str_contains($article->body(), 'USD 9.99') && str_contains($article->body(), '剩余库存：2') && str_contains($article->body(), '/card-delivery/' . $productId . '/checkout'), 'frontend renders card_delivery block from Core card product data');

$pageCreate = $admin->contentStore(new Request('POST', '/admin/content', [], [
    '_csrf' => $csrf,
    'content_type' => 'page',
    'title' => 'Card Landing Page',
    'slug' => 'card-landing-page',
    'status' => 'published',
    'content_action' => 'publish',
    'blocks' => [[
        'type' => 'card_delivery',
        'data' => [
            'card_product_id' => (string) $productId,
            'show_name' => '1',
            'show_price' => '1',
            'show_stock' => '1',
            'show_button' => '1',
            'button_text' => '购买页面卡密',
        ],
    ]],
]));
$page = $front->page(new Request('GET', '/card-landing-page'));
block_card_check($pageCreate->status() === 302 && $page->status() === 200 && str_contains($page->body(), 'Steam 激活码') && str_contains($page->body(), '购买页面卡密') && str_contains($page->body(), '/card-delivery/' . $productId . '/checkout'), 'frontend renders card_delivery block on Page routes as well as Articles');

$soldOutProductId = $repo->saveProduct(null, '售罄兑换卡', 299, 'USD', 'active', 1);
$soldOutContentId = (new ContentRepository($pdo, ContentTypeRegistry::defaults()))->create('article', 'Sold Out Card Article', 'sold-out-card-article', [[
    'type' => 'card_delivery',
    'data' => [
        'card_product_id' => $soldOutProductId,
        'show_name' => true,
        'show_price' => true,
        'show_stock' => true,
        'show_button' => true,
        'button_text' => '立即购买',
    ],
]], 'published');
$soldOutArticle = $front->article(new Request('GET', '/articles/sold-out-card-article'));
block_card_check($soldOutContentId > 0 && $soldOutArticle->status() === 200 && str_contains($soldOutArticle->body(), '暂时缺货') && !str_contains($soldOutArticle->body(), '/card-delivery/' . $soldOutProductId . '/checkout'), 'frontend card_delivery block shows sold-out state without rendering a checkout form when inventory is zero');

$hiddenDisplayProductId = $repo->saveProduct(null, '隐藏展示发卡商品', 499, 'USD', 'active', 1);
$repo->importInventory($hiddenDisplayProductId, "HIDDEN-DISPLAY-CARD\n");
$hiddenDisplayCreate = $admin->contentStore(new Request('POST', '/admin/content', [], [
    '_csrf' => $csrf,
    'content_type' => 'article',
    'title' => 'Hidden Display Article',
    'slug' => 'hidden-display-article',
    'status' => 'published',
    'content_action' => 'publish',
    'blocks' => [[
        'type' => 'card_delivery',
        'data' => [
            'card_product_id' => (string) $hiddenDisplayProductId,
            'show_name' => '0',
            'show_price' => '0',
            'show_stock' => '0',
            'show_button' => '0',
            'button_text' => '不要显示',
        ],
    ]],
]));
$hiddenDisplayContent = (new ContentRepository($pdo, ContentTypeRegistry::defaults()))->publicBySlug('article', 'hidden-display-article');
$hiddenDisplayBlock = is_array($hiddenDisplayContent) ? ($hiddenDisplayContent['blocks'][0]['data'] ?? []) : [];
$hiddenDisplayArticle = $front->article(new Request('GET', '/articles/hidden-display-article'));
block_card_check($hiddenDisplayCreate->status() === 302 && ($hiddenDisplayBlock['show_name'] ?? true) === false && ($hiddenDisplayBlock['show_price'] ?? true) === false && ($hiddenDisplayBlock['show_stock'] ?? true) === false && ($hiddenDisplayBlock['show_button'] ?? true) === false, 'card_delivery display toggles save explicit false values from the editor');
block_card_check($hiddenDisplayArticle->status() === 200 && !str_contains($hiddenDisplayArticle->body(), '隐藏展示发卡商品') && !str_contains($hiddenDisplayArticle->body(), '价格：') && !str_contains($hiddenDisplayArticle->body(), '剩余库存：') && !str_contains($hiddenDisplayArticle->body(), '/card-delivery/' . $hiddenDisplayProductId . '/checkout'), 'frontend card_delivery block honors disabled display toggles without leaking hidden product details or checkout form');

$deliveryService = new CardDeliveryService($pdo);
$first = $deliveryService->deliverPaidOrder($productId, 'ORDER-1', 'TX-1');
$again = $deliveryService->deliverPaidOrder($productId, 'ORDER-1', 'TX-1');
$second = $deliveryService->deliverPaidOrder($productId, 'ORDER-2', 'TX-2');
$third = $deliveryService->deliverPaidOrder($productId, 'ORDER-3', 'TX-3');
block_card_check(($first['status'] ?? '') === 'delivered' && ($again['idempotent'] ?? false) === true && ($second['status'] ?? '') === 'delivered', 'Card Delivery service is idempotent for repeated paid-order delivery');
block_card_check(($third['status'] ?? '') === 'out_of_stock', 'Card Delivery service records out_of_stock instead of failing fatally when paid inventory is exhausted');
block_card_check((int) $pdo->query("SELECT COUNT(*) FROM cms_card_inventory WHERE status = 'delivered'")->fetchColumn() === 2, 'Card Delivery never delivers the same card inventory row twice');

$competeProductId = $repo->saveProduct(null, '竞争库存卡', 300, 'USD', 'active', 1);
$repo->importInventory($competeProductId, "ONE-STOCK-CARD\n");
$competeFirst = $deliveryService->deliverPaidOrder($competeProductId, 'COMPETE-ORDER-1', 'TX-COMPETE-1');
$competeSecond = $deliveryService->deliverPaidOrder($competeProductId, 'COMPETE-ORDER-2', 'TX-COMPETE-2');
block_card_check((string) ($competeFirst['status'] ?? '') === 'delivered' && (string) ($competeSecond['status'] ?? '') === 'out_of_stock' && (int) $pdo->query("SELECT COUNT(DISTINCT inventory_id) FROM cms_card_deliveries WHERE product_id = " . (int) $competeProductId . " AND inventory_id IS NOT NULL")->fetchColumn() === 1, 'competing paid orders cannot receive the same single remaining card inventory row');

PaymentProviderRegistry::clear();
PaymentProviderRegistry::register(FixturePaymentProvider::PROVIDER_ID, new FixturePaymentProvider());
$settingsRepo = new PaymentProviderSettingsRepository($pdo, 'block-card-delivery-secret-key');
$settingsRepo->save(FixturePaymentProvider::PROVIDER_ID, '核心模拟支付', 'enabled', ['default_provider' => true], []);
$checkoutProductId = $repo->saveProduct(null, '会员兑换卡', 1299, 'USD', 'active', 1);
$repo->importInventory($checkoutProductId, "MEMBER-CARD-0001\n");
$checkout = (new CardDeliveryService($pdo, $settings))->checkout($checkoutProductId, FixturePaymentProvider::PROVIDER_ID, 'card-checkout-test-1');
block_card_check(($checkout['provider_redirect'] ?? true) === false && ($checkout['pending_confirmation'] ?? true) === false, 'Card Delivery checkout completes through Core Payment without standalone payment code');
block_card_check((string) ($checkout['payment']['subject_type'] ?? '') === 'card_delivery_order' && str_starts_with((string) ($checkout['payment']['subject_id'] ?? ''), 'order:'), 'Card Delivery payment uses Core Payment subject for the card order');
block_card_check((string) ($checkout['delivery']['status'] ?? '') === 'delivered' && (string) ($checkout['delivery']['secret'] ?? '') === 'MEMBER-CARD-0001', 'Paid card delivery order auto-delivers the purchased card secret');
block_card_check((string) ($checkout['order']['status'] ?? '') === 'delivered', 'Card Delivery order status is updated after automatic fulfillment');
$againCheckout = (new CardDeliveryService($pdo, $settings))->checkout($checkoutProductId, FixturePaymentProvider::PROVIDER_ID, 'card-checkout-test-1');
block_card_check((int) ($againCheckout['delivery']['id'] ?? 0) === (int) ($checkout['delivery']['id'] ?? -1) && ($againCheckout['delivery']['idempotent'] ?? false) === true, 'Card Delivery checkout is idempotent for repeated payment completion');

$multiProductId = $repo->saveProduct(null, '多张兑换卡', 500, 'USD', 'active', 3);
$repo->importInventory($multiProductId, "MULTI-CARD-0001\nMULTI-CARD-0002\nMULTI-CARD-0003\n");
$multiCheckout = (new CardDeliveryService($pdo, $settings))->checkout($multiProductId, FixturePaymentProvider::PROVIDER_ID, 'card-checkout-multi-1', 2);
$multiItems = is_array($multiCheckout['delivery']['items'] ?? null) ? $multiCheckout['delivery']['items'] : [];
$multiSecrets = array_values(array_filter(array_map(static fn (array $item): string => (string) ($item['secret'] ?? ''), $multiItems)));
block_card_check((int) ($multiCheckout['order']['quantity'] ?? 0) === 2 && (int) ($multiCheckout['payment']['amount_minor'] ?? 0) === 1000, 'Card Delivery checkout creates one Core Payment for the requested multi-card quantity');
block_card_check(count($multiItems) === 2 && $multiSecrets === ['MULTI-CARD-0001', 'MULTI-CARD-0002'], 'one card order buying multiple quantity delivers multiple card secrets');
$multiAgain = (new CardDeliveryService($pdo, $settings))->deliverPaidOrder($multiProductId, (string) ($multiCheckout['order']['id'] ?? ''), 'TX-MULTI-REPEAT', 2);
block_card_check(($multiAgain['idempotent'] ?? false) === true && count(is_array($multiAgain['items'] ?? null) ? $multiAgain['items'] : []) === 2, 'repeated paid delivery callback for a multi-card order does not issue extra cards');

$shortProductId = $repo->saveProduct(null, '缺货后补发卡', 700, 'USD', 'active', 3);
$repo->importInventory($shortProductId, "SHORT-CARD-0001\n");
$shortFirst = (new CardDeliveryService($pdo, $settings))->checkout($shortProductId, FixturePaymentProvider::PROVIDER_ID, 'card-checkout-short-1', 2);
block_card_check((string) ($shortFirst['delivery']['status'] ?? '') === 'out_of_stock' && (int) ($shortFirst['delivery']['delivered_count'] ?? 0) === 1, 'paid multi-card order records out_of_stock without fatal error when inventory is short');
$shortAdminIndex = $admin->cardDeliveryIndex();
block_card_check($shortAdminIndex->status() === 200 && str_contains($shortAdminIndex->body(), '库存不足，补库存后重试发卡。') && str_contains($shortAdminIndex->body(), '重试发卡'), 'admin Card Delivery explicitly flags out_of_stock orders for manual restock and retry');
$repo->importInventory($shortProductId, "SHORT-CARD-0002\n");
$shortRetryGet = $admin->cardDeliveryOrderFulfill(new Request('GET', '/admin/card-delivery/orders/' . (int) ($shortFirst['order']['id'] ?? 0) . '/fulfill', [], [
    '_csrf' => $csrf,
]));
$shortRetryOrderBeforePost = $repo->order((int) ($shortFirst['order']['id'] ?? 0));
block_card_check(
    $shortRetryGet->status() === 405
    && ($shortRetryGet->headers()['Allow'] ?? '') === 'POST'
    && str_contains($shortRetryGet->body(), '重试发卡必须通过 POST 请求提交')
    && (string) ($shortRetryOrderBeforePost['status'] ?? '') === 'out_of_stock',
    'admin Card Delivery manual retry rejects non-POST direct calls before changing order status'
);
$shortRetryResponse = $admin->cardDeliveryOrderFulfill(new Request('POST', '/admin/card-delivery/orders/' . (int) ($shortFirst['order']['id'] ?? 0) . '/fulfill', [], [
    '_csrf' => $csrf,
]));
$shortRetryOrder = $repo->order((int) ($shortFirst['order']['id'] ?? 0));
block_card_check($shortRetryResponse->status() === 302 && (string) ($shortRetryOrder['status'] ?? '') === 'delivered' && (int) $pdo->query("SELECT COUNT(*) FROM cms_card_deliveries WHERE product_id = " . (int) $shortProductId . " AND status = 'delivered'")->fetchColumn() === 2, 'admin manual retry can fulfill a previously out_of_stock card order after restock');

$settingsRepo->save(FixturePaymentProvider::PROVIDER_ID, '核心模拟支付', 'enabled', ['default_provider' => true], ['webhook_secret' => 'whsec_card_delivery']);
$webhookProductId = $repo->saveProduct(null, 'Webhook 发卡', 600, 'USD', 'active', 1);
$repo->importInventory($webhookProductId, "WEBHOOK-CARD-0001\n");
$webhookOrder = $repo->createOrder($webhookProductId, 600, 'USD', 'card-webhook-order-1', 1);
$webhookPayment = (new PaymentService($pdo, new PaymentRepository($pdo), 'block-card-delivery-secret-key'))->createProviderPayment(
    'card_delivery_order',
    'order:' . (int) ($webhookOrder['id'] ?? 0),
    FixturePaymentProvider::PROVIDER_ID,
    600,
    'USD',
    'card-webhook-payment-1',
    'authorized',
);
$repo->attachPayment((int) ($webhookOrder['id'] ?? 0), (int) ($webhookPayment['id'] ?? 0));
$webhookController = new PaymentWebhookController($settings);
$webhookPayloadOne = json_encode([
    'id' => 'evt-card-delivery-paid-1',
    'provider_payment_id' => (string) ($webhookPayment['remote_id'] ?? ''),
    'status' => 'paid',
], JSON_UNESCAPED_SLASHES);
$webhookTimestampOne = (string) time();
$webhookResponseOne = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $webhookPayloadOne,
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $webhookTimestampOne,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $webhookTimestampOne . '.' . (string) $webhookPayloadOne, 'whsec_card_delivery'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-card-delivery-paid-1',
]));
$webhookPayloadTwo = json_encode([
    'id' => 'evt-card-delivery-paid-2',
    'provider_payment_id' => (string) ($webhookPayment['remote_id'] ?? ''),
    'status' => 'paid',
], JSON_UNESCAPED_SLASHES);
$webhookTimestampTwo = (string) time();
$webhookResponseTwo = $webhookController->receive(new Request('POST', '/payment/webhooks/' . FixturePaymentProvider::PROVIDER_ID, [], [], [
    'RAW_BODY' => (string) $webhookPayloadTwo,
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_CMS_PAYMENT_TIMESTAMP' => $webhookTimestampTwo,
    'HTTP_X_CMS_PAYMENT_SIGNATURE' => hash_hmac('sha256', $webhookTimestampTwo . '.' . (string) $webhookPayloadTwo, 'whsec_card_delivery'),
    'HTTP_X_CMS_PAYMENT_EVENT' => 'evt-card-delivery-paid-2',
]));
$webhookBodyOne = json_decode($webhookResponseOne->body(), true) ?: [];
$webhookDeliveryCount = (int) $pdo->query("SELECT COUNT(*) FROM cms_card_deliveries WHERE product_id = " . (int) $webhookProductId . " AND status = 'delivered'")->fetchColumn();
block_card_check($webhookResponseOne->status() === 200 && (int) ($webhookBodyOne['card_delivery_order_id'] ?? 0) === (int) ($webhookOrder['id'] ?? 0), 'signed Core Payment webhook triggers Card Delivery fulfillment');
block_card_check($webhookResponseTwo->status() === 200 && $webhookDeliveryCount === 1, 'repeated paid payment webhook does not duplicate card delivery');

$paymentId = (int) ($checkout['payment']['id'] ?? 0);
(new PaymentService($pdo, new PaymentRepository($pdo), 'block-card-delivery-secret-key'))->refundProviderPayment($paymentId, 1299, 'customer refund', 'card-refund-test-1');
block_card_check((int) $pdo->query("SELECT COUNT(*) FROM cms_card_inventory WHERE product_id = " . (int) $checkoutProductId . " AND status = 'available'")->fetchColumn() === 0, 'refunding an already delivered card payment does not return exposed card inventory to available');

PaymentProviderRegistry::clear();
$noProviderProductId = $repo->saveProduct(null, '未配置支付卡', 888, 'USD', 'active', 1);
$repo->importInventory($noProviderProductId, "NO-PROVIDER-CARD\n");
$noProviderFailed = false;
try {
    (new CardDeliveryService($pdo, $settings))->checkout($noProviderProductId, '', 'card-checkout-no-provider-1');
} catch (PaymentException) {
    $noProviderFailed = true;
}
block_card_check($noProviderFailed, 'Card Delivery checkout fails safely when no Payment Provider is configured');
$noProviderResponse = (new CardDeliveryController($settings))->checkout(new Request('POST', '/card-delivery/' . $noProviderProductId . '/checkout', [], [
    '_csrf' => $csrf,
    'quantity' => '1',
]));
block_card_check($noProviderResponse->status() === 400 && str_contains($noProviderResponse->body(), '自动发卡') && str_contains((string) ($noProviderResponse->headers()['Cache-Control'] ?? ''), 'no-store'), 'Card Delivery controller returns a safe no-store checkout error instead of crashing when no Payment Provider is configured');

block_card_remove($root);

if ($failures > 0) {
    fwrite(STDERR, $failures . " block editor card delivery checks failed.\n");
    exit(1);
}

echo "Block editor card delivery tests passed.\n";
