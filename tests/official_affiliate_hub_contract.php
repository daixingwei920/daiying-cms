<?php

declare(strict_types=1);

const CMS_ROOT = __DIR__ . '/..';

require_once CMS_ROOT . '/system/core/Bootstrap/autoload.php';
require_once CMS_ROOT . '/content/plugins/official.affiliate-hub/src/AffiliateContracts.php';
require_once CMS_ROOT . '/content/plugins/official.affiliate-hub/src/AffiliateRepository.php';
require_once CMS_ROOT . '/content/plugins/official.affiliate-hub/src/FeedImportService.php';
require_once CMS_ROOT . '/content/plugins/official.affiliate-hub/src/AffiliateController.php';

use Cms\Core\Http\Request;
use Daiying\AffiliateHub\AffiliateAdapterInterface;
use Daiying\AffiliateHub\AffiliateAdapterRegistry;
use Daiying\AffiliateHub\AffiliateProviderIsolation;
use Daiying\AffiliateHub\AffiliateRepository;
use Daiying\AffiliateHub\FeedAffiliateAdapter;
use Daiying\AffiliateHub\FeedImportService;
use Daiying\AffiliateHub\ManualAffiliateAdapter;
use Daiying\AffiliateHub\AffiliateController;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures++;
        fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
};

$manifest = json_decode((string) file_get_contents(CMS_ROOT . '/content/plugins/official.affiliate-hub/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
$assert(($manifest['plugin_id'] ?? '') === 'official.affiliate-hub', 'Manifest uses official.affiliate-hub plugin id.');
$assert(($manifest['version'] ?? '') === '0.1.0-alpha.2', 'Manifest version includes Feed mapping UI.');
$assert(($manifest['core']['min'] ?? '') === '1.2.39', 'Manifest targets the current Core notification/scheduler generation.');
$assert(($manifest['migrations'] ?? []) === ['migrations/001_affiliate_hub.php'], 'Manifest declares the Affiliate Hub migration.');
$assert(($manifest['table_prefixes'] ?? []) === ['affiliate_'], 'Manifest owns only affiliate_ tables.');
$assert(!in_array('payment.create', $manifest['capabilities'] ?? [], true), 'Affiliate Hub does not claim payment creation capability.');
$assert(!in_array('commerce.orders', $manifest['capabilities'] ?? [], true), 'Affiliate Hub does not claim Commerce order capability.');
$assert(in_array('affiliate.adapter', $manifest['capabilities'] ?? [], true), 'Manifest declares affiliate adapter capability.');
$assert(in_array('affiliate', $manifest['capability_namespaces'] ?? [], true), 'Manifest limits its main namespace to affiliate.');

$migration = require CMS_ROOT . '/content/plugins/official.affiliate-hub/migrations/001_affiliate_hub.php';
$assert(in_array('table:affiliate_products', $migration['affected_objects'] ?? [], true), 'Migration declares affiliate product table.');
$assert(in_array('table:affiliate_offers', $migration['affected_objects'] ?? [], true), 'Migration declares affiliate offer table.');
$assert(in_array('table:affiliate_clicks', $migration['affected_objects'] ?? [], true), 'Migration declares affiliate click table.');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
($migration['up'])($pdo);

$repo = new AffiliateRepository($pdo);
$productId = $repo->saveManualProduct([
    'name' => '示例联盟商品',
    'advertiser_name' => 'Example Merchant',
    'brand' => 'Daiying',
    'destination_url' => 'https://merchant.example/products/1',
    'affiliate_url' => 'https://tracking.example/click?offer=1',
    'image_url' => 'https://cdn.example/image.jpg',
    'price_current' => '29.99',
    'currency' => 'USD',
    'country' => 'US',
    'language' => 'en-US',
    'status' => 'active',
    'indexable' => '1',
]);
$product = $repo->product($productId);
$offers = $repo->offersForProduct($productId);
$assert(is_array($product) && ($product['provider_id'] ?? '') === 'affiliate.manual', 'Manual product is stored as a unified affiliate product.');
$assert(abs((float) ($product['price_current'] ?? 0) - 29.99) < 0.0001 && ($product['currency'] ?? '') === 'USD', 'Affiliate product keeps decimal money plus ISO currency.');
$assert(count($offers) === 1 && ($offers[0]['affiliate_url'] ?? '') === 'https://tracking.example/click?offer=1', 'Manual product creates one active affiliate offer.');
$assert((int) $pdo->query('SELECT COUNT(*) FROM affiliate_clicks')->fetchColumn() === 0, 'Saving affiliate products does not create click events.');

$controller = new AffiliateController($repo, new FeedImportService());
$response = $controller->go(new Request('GET', '/go/affiliate', ['offer_id' => (string) $offers[0]['id']], [], [
    'REMOTE_ADDR' => '203.0.113.10',
    'HTTP_USER_AGENT' => 'ContractBrowser/1.0',
    'HTTP_REFERER' => 'https://www.daiyingcms.com/review',
    'REQUEST_URI' => '/go/affiliate?offer_id=' . (int) $offers[0]['id'],
]));
$assert($response->status() === 302, 'Affiliate go endpoint returns a redirect.');
$assert(($response->headers()['Location'] ?? '') === 'https://tracking.example/click?offer=1', 'Affiliate go endpoint redirects to the tracking URL.');
$assert((int) $pdo->query('SELECT COUNT(*) FROM affiliate_clicks')->fetchColumn() === 1, 'Affiliate go endpoint records a click event.');
$click = $pdo->query('SELECT * FROM affiliate_clicks LIMIT 1')->fetch();
$assert(is_array($click) && ($click['ip_hash'] ?? '') !== '203.0.113.10', 'Affiliate click stores hashed IP, not raw IP.');

$reusedId = $repo->saveManualProduct([
    'name' => '示例联盟商品',
    'advertiser_name' => 'Example Merchant',
    'brand' => 'Daiying',
    'destination_url' => 'https://merchant.example/products/1',
    'affiliate_url' => 'https://tracking.example/click?offer=1',
    'price_current' => '29.99',
    'currency' => 'USD',
    'status' => 'active',
]);
$assert($reusedId === $productId, 'Manual product import is idempotent for the same source payload.');

$badUrlBlocked = false;
try {
    $repo->saveManualProduct([
        'name' => 'Bad URL',
        'destination_url' => 'http://merchant.example/item',
        'affiliate_url' => 'https://tracking.example/click',
    ]);
} catch (InvalidArgumentException) {
    $badUrlBlocked = true;
}
$assert($badUrlBlocked, 'Affiliate Hub rejects non-HTTPS product and tracking URLs.');

$feed = new FeedImportService();
$csv = "id,name,merchant,price,currency,url,affiliate_url,image_url\nSKU-1,Feed Product,Feed Merchant,12.50,USD,https://merchant.example/feed-1,https://tracking.example/feed-1,https://cdn.example/feed-1.jpg\nSKU-2,Second Product,Feed Merchant,19.00,USD,https://merchant.example/feed-2,https://tracking.example/feed-2,\n";
$parsed = $feed->parseCsv($csv, 10);
$mapping = $feed->suggestMapping($parsed['headers']);
$items = $feed->mapRows($parsed['rows'], $mapping, 10);
$assert($mapping['name'] === 'name' && $mapping['affiliate_url'] === 'affiliate_url', 'Feed import suggests obvious CSV field mappings.');
$assert(count($items) === 2 && ($items[0]['name'] ?? '') === 'Feed Product', 'Feed import maps CSV rows into product payloads.');
$import = $repo->importFeedProducts($items, [
    'source_name' => 'unit-test-feed',
    'status' => 'active',
    'indexable' => true,
]);
$assert($import['processed'] === 2 && $import['created'] === 2 && $import['failed'] === 0, 'Feed import creates mapped affiliate products.');
$assert((int) $pdo->query("SELECT COUNT(*) FROM affiliate_products WHERE provider_id = 'affiliate.feed'")->fetchColumn() === 2, 'Feed products use affiliate.feed provider identity.');
$repeat = $repo->importFeedProducts($items, [
    'source_name' => 'unit-test-feed',
    'status' => 'active',
]);
$assert($repeat['updated'] === 2 && $repeat['created'] === 0, 'Feed import is idempotent by source name and external product id.');

$previewPage = $controller->previewImport(new Request('POST', '/admin/affiliate-hub/import/preview', [], [
    'source_name' => 'unit-test-feed',
    'feed_text' => $csv,
]));
$assert($previewPage->status() === 200 && str_contains($previewPage->body(), 'Feed 字段映射') && str_contains($previewPage->body(), 'Feed Product'), 'Feed preview UI renders mapped products.');

AffiliateAdapterRegistry::clear();
$manual = new ManualAffiliateAdapter();
$feed = new FeedAffiliateAdapter();
AffiliateAdapterRegistry::register($manual);
AffiliateAdapterRegistry::register($feed);
$assert($manual instanceof AffiliateAdapterInterface && $feed instanceof AffiliateAdapterInterface, 'Manual and Feed adapters implement the public adapter contract.');
$assert(isset(AffiliateAdapterRegistry::all()['affiliate.manual'], AffiliateAdapterRegistry::all()['affiliate.feed']), 'Adapter registry stores independent adapters.');
$assert(in_array('field_mapping', $feed->capabilities(), true), 'Feed adapter declares field mapping capability.');
$assert($manual->buildTrackingUrl(['affiliate_url' => 'https://tracking.example/a']) === 'https://tracking.example/a', 'Manual adapter returns explicit tracking URL.');

$isolated = AffiliateProviderIsolation::capture('affiliate.feed', 'syncProducts', static function (): void {
    throw new RuntimeException('provider temporarily unavailable');
});
$assert(($isolated['ok'] ?? true) === false && ($isolated['provider'] ?? '') === 'affiliate.feed', 'Provider failures are captured without crashing the Hub.');

$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
$assert(!in_array('commerce_orders', $tables, true), 'Affiliate Hub migration does not create Commerce order tables.');
$assert(!in_array('cms_payments', $tables, true), 'Affiliate Hub migration does not create payment tables.');

if ($failures > 0) {
    fwrite(STDERR, 'official_affiliate_hub_contract failed: ' . $failures . PHP_EOL);
    exit(1);
}

echo 'official_affiliate_hub_contract passed' . PHP_EOL;
