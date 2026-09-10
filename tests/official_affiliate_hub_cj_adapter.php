<?php

declare(strict_types=1);

const CMS_ROOT = __DIR__ . '/..';

require_once CMS_ROOT . '/system/core/Bootstrap/autoload.php';
require_once CMS_ROOT . '/content/plugins/official.affiliate-hub/src/AffiliateContracts.php';
require_once CMS_ROOT . '/content/plugins/official.affiliate-hub/src/AffiliateRepository.php';
require_once CMS_ROOT . '/content/plugins/official.affiliate-hub/src/AffiliateConnectionRepository.php';
require_once CMS_ROOT . '/content/plugins/official.affiliate-hub/src/CjAffiliateClient.php';
require_once CMS_ROOT . '/content/plugins/official.affiliate-hub/src/CjAffiliateAdapter.php';

use Cms\Core\Plugin\PluginSecretStore;
use Daiying\AffiliateHub\AffiliateConnectionRepository;
use Daiying\AffiliateHub\AffiliateRepository;
use Daiying\AffiliateHub\CjAffiliateAdapter;
use Daiying\AffiliateHub\CjAffiliateClient;
use Daiying\AffiliateHub\CjAffiliateRateLimitException;
use Daiying\AffiliateHub\CjHttpTransportInterface;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures++;
        fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
};

final class MockCjTransport implements CjHttpTransportInterface
{
    /** @var list<array{method:string,url:string,body:string}> */
    public array $requests = [];
    public bool $rateLimit = false;

    public function request(string $method, string $url, array $headers = [], string $body = '', int $timeout = 20): array
    {
        unset($timeout);
        $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body];
        if (($headers['Authorization'] ?? '') !== 'Bearer test-personal-access-token-1234567890') {
            return ['status' => 401, 'headers' => ['content-type' => 'application/json'], 'body' => '{"errors":[{"message":"unauthorized"}]}'];
        }
        if ($this->rateLimit) {
            return ['status' => 429, 'headers' => ['content-type' => 'application/json'], 'body' => '{"message":"limit"}'];
        }
        if (str_contains($body, 'productFeeds')) {
            return ['status' => 200, 'headers' => ['content-type' => 'application/json'], 'body' => '{"data":{"productFeeds":{"totalCount":1,"count":1,"resultList":[{"advertiserId":"111","advertiserName":"CJ Merchant","feedName":"Main","productCount":10}]}}}'];
        }
        return ['status' => 200, 'headers' => ['content-type' => 'application/json'], 'body' => '{"data":{"products":{"totalCount":1,"count":1,"limit":25,"resultList":[{"advertiserId":"111","advertiserName":"CJ Merchant","catalogId":"CAT-1","id":"SKU-1","title":"CJ &amp; Daiying Product","description":"<p>Safe description</p>","brand":"Daiying","sku":"SKU-1","imageLink":"https://cdn.example/cj.jpg","availability":"in stock","price":{"amount":"23.45","currency":"USD"},"linkCode":{"clickUrl":"https://www.kqzyfj.com/click-999-111?url=https%3A%2F%2Fmerchant.example%2Fitem&cjsku=SKU-1"}}]}}}'];
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE cms_plugin_secrets (id INTEGER PRIMARY KEY AUTOINCREMENT, plugin_id TEXT, secret_key TEXT, ciphertext TEXT, created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE UNIQUE INDEX idx_plugin_secrets_plugin_key ON cms_plugin_secrets (plugin_id, secret_key)');
$migration1 = require CMS_ROOT . '/content/plugins/official.affiliate-hub/migrations/001_affiliate_hub.php';
$migration2 = require CMS_ROOT . '/content/plugins/official.affiliate-hub/migrations/002_cj_adapter.php';
($migration1['up'])($pdo);
($migration2['up'])($pdo);

$secrets = new PluginSecretStore($pdo, 'affiliate-hub-cj-test-key');
$connections = new AffiliateConnectionRepository($pdo, $secrets);
$connectionId = $connections->saveCjConnection([
    'status' => 'enabled',
    'company_id' => '999',
    'website_id' => '9999999',
    'advertiser_ids' => '111,222',
    'timeout' => 7,
    'personal_access_token' => 'test-personal-access-token-1234567890',
]);
$connection = $connections->cjConnection();
$assert($connectionId > 0 && is_array($connection), 'CJ connection is saved.');
$assert(($connection['public_config']['company_id'] ?? '') === '999', 'CJ public config stores company id.');
$assert(!str_contains(json_encode($connection, JSON_UNESCAPED_SLASHES) ?: '', 'test-personal-access-token'), 'CJ connection output never exposes token.');
$ciphertext = (string) $pdo->query('SELECT ciphertext FROM cms_plugin_secrets LIMIT 1')->fetchColumn();
$assert($ciphertext !== '' && !str_contains($ciphertext, 'test-personal-access-token'), 'CJ Personal Access Token is encrypted at rest.');

$transport = new MockCjTransport();
$client = new CjAffiliateClient($transport);
$adapter = new CjAffiliateAdapter($client);
$runtimeConfig = $connections->cjRuntimeConfig();
$test = $adapter->validateCredentials($runtimeConfig);
$assert($test['ok'] === true && str_contains($test['message'], 'CJ 连接成功'), 'CJ adapter validates credentials with a real productFeeds API call shape.');
$search = $adapter->searchProducts(['config' => $runtimeConfig, 'keywords' => 'daiying', 'limit' => 25]);
$assert(count($search['items']) === 1, 'CJ adapter returns normalized product search results.');
$item = $search['items'][0];
$assert(($item['provider_id'] ?? '') === 'affiliate.cj' && ($item['external_product_id'] ?? '') === 'SKU-1', 'CJ product keeps CJ source identity inside adapter output.');
$assert(($item['name'] ?? '') === 'CJ & Daiying Product', 'CJ adapter decodes product title safely.');
$assert(($item['destination_url'] ?? '') === 'https://merchant.example/item', 'CJ adapter derives destination URL from returned tracking link when available.');
$assert($adapter->buildTrackingUrl($item) === (string) $item['affiliate_url'], 'CJ adapter exposes returned linkCode clickUrl as tracking link.');

$repo = new AffiliateRepository($pdo);
$import = $repo->importProviderProducts('affiliate.cj', $search['items'], [
    'source_name' => 'cj:999',
    'connection_id' => (int) ($runtimeConfig['connection_id'] ?? 0),
    'status' => 'active',
]);
$assert($import['created'] === 1 && $import['failed'] === 0, 'CJ import creates an affiliate product through the generic provider pipeline.');
$repeat = $repo->importProviderProducts('affiliate.cj', $search['items'], ['source_name' => 'cj:999', 'connection_id' => (int) ($runtimeConfig['connection_id'] ?? 0), 'status' => 'active']);
$assert($repeat['updated'] === 1 && $repeat['created'] === 0, 'CJ repeated import updates instead of duplicating.');
$offer = $pdo->query("SELECT * FROM affiliate_offers WHERE provider_id = 'affiliate.cj' LIMIT 1")->fetch();
$assert(is_array($offer) && str_starts_with((string) $offer['affiliate_url'], 'https://www.kqzyfj.com/click-'), 'CJ import stores the affiliate tracking link in offers.');

$blocked = false;
try {
    $bad = new CjAffiliateClient(new class implements CjHttpTransportInterface {
        public function request(string $method, string $url, array $headers = [], string $body = '', int $timeout = 20): array
        {
            unset($method, $url, $headers, $body, $timeout);
            return ['status' => 200, 'headers' => [], 'body' => '{}'];
        }
    });
    $ref = new ReflectionMethod($bad, 'searchLinks');
    $ref->invoke($bad, ['personal_access_token' => 'test-personal-access-token-1234567890', 'company_id' => '999', 'website_id' => '999'], ['keywords' => 'x']);
} catch (Throwable) {
    $blocked = true;
}
$assert(!$blocked, 'CJ client allows the official link-search API host.');

$transport->rateLimit = true;
$limited = false;
try {
    $adapter->searchProducts(['config' => $runtimeConfig, 'keywords' => 'daiying']);
} catch (CjAffiliateRateLimitException) {
    $limited = true;
}
$assert($limited, 'CJ adapter surfaces API rate limits without converting them to successful imports.');

if ($failures > 0) {
    fwrite(STDERR, 'official_affiliate_hub_cj_adapter failed: ' . $failures . PHP_EOL);
    exit(1);
}

echo 'official_affiliate_hub_cj_adapter passed' . PHP_EOL;
