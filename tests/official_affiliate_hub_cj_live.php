<?php

declare(strict_types=1);

const CMS_ROOT = __DIR__ . '/..';

require_once CMS_ROOT . '/system/core/Bootstrap/autoload.php';
require_once CMS_ROOT . '/content/plugins/official.affiliate-hub/src/AffiliateContracts.php';
require_once CMS_ROOT . '/content/plugins/official.affiliate-hub/src/CjAffiliateClient.php';
require_once CMS_ROOT . '/content/plugins/official.affiliate-hub/src/CjAffiliateAdapter.php';

use Daiying\AffiliateHub\CjAffiliateAdapter;

$token = trim((string) getenv('CJ_PERSONAL_ACCESS_TOKEN'));
$companyId = trim((string) getenv('CJ_COMPANY_ID'));
$websiteId = trim((string) (getenv('CJ_WEBSITE_ID') ?: getenv('CJ_PID')));
$keywords = trim((string) (getenv('CJ_SEARCH_KEYWORDS') ?: 'test'));
$advertisers = trim((string) getenv('CJ_ADVERTISER_IDS'));

if ($token === '' || $companyId === '' || $websiteId === '') {
    echo '[SKIP] CJ live E2E requires CJ_PERSONAL_ACCESS_TOKEN, CJ_COMPANY_ID, and CJ_WEBSITE_ID/CJ_PID.' . PHP_EOL;
    exit(0);
}

$adapter = new CjAffiliateAdapter();
$config = [
    'personal_access_token' => $token,
    'company_id' => $companyId,
    'website_id' => $websiteId,
    'advertiser_ids' => $advertisers,
    'timeout' => 30,
];

$test = $adapter->validateCredentials($config);
if (!$test['ok']) {
    fwrite(STDERR, '[FAIL] CJ live credential test failed: ' . $test['message'] . PHP_EOL);
    exit(1);
}
echo '[PASS] CJ live credential test: ' . $test['message'] . PHP_EOL;

$result = $adapter->searchProducts([
    'config' => $config,
    'keywords' => $keywords,
    'advertiser_ids' => $advertisers,
    'limit' => 5,
]);
if (($result['items'] ?? []) === []) {
    echo '[PASS] CJ live product search returned zero products for current account/query; API path is reachable.' . PHP_EOL;
    exit(0);
}

$first = $result['items'][0];
foreach (['external_product_id', 'name', 'affiliate_url'] as $field) {
    if (trim((string) ($first[$field] ?? '')) === '') {
        fwrite(STDERR, '[FAIL] CJ live product is missing required normalized field: ' . $field . PHP_EOL);
        exit(1);
    }
}

echo '[PASS] CJ live product search normalized ' . count($result['items']) . ' product(s).' . PHP_EOL;
