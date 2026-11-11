<?php

declare(strict_types=1);

define('CMS_ROOT', getenv('RESTAURANT_TEST_ROOT') ?: __DIR__ . '/..');
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';
require CMS_ROOT . '/content/plugins/official.commerce/src/CommerceRepository.php';
foreach (['RestaurantRepository', 'RestaurantPrintService', 'RestaurantTicketService', 'RestaurantCheckoutService', 'RestaurantController'] as $class) {
    require CMS_ROOT . '/content/plugins/official.restaurant/src/' . $class . '.php';
}

use Cms\Core\Http\Request;
use Daiying\Commerce\CommerceRepository;
use Official\Restaurant\RestaurantCheckoutService;
use Official\Restaurant\RestaurantController;
use Official\Restaurant\RestaurantPrintService;
use Official\Restaurant\RestaurantRepository;
use Official\Restaurant\RestaurantTicketService;

session_start();
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
    echo "[PASS] $message\n";
};
$reject = static function (callable $operation, string $message) use ($assert): void {
    try {
        $operation();
    } catch (InvalidArgumentException | RuntimeException) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
foreach (glob(CMS_ROOT . '/system/migrations/*payment*.php') as $filename) {
    (require $filename)->up($pdo);
}
foreach (glob(CMS_ROOT . '/content/plugins/official.commerce/migrations/*.php') as $filename) {
    (require $filename)['up']($pdo);
}
foreach (['001_restaurant_core.php', '002_restaurant_checkout.php', '003_agent_claims.php', '004_print_bridge.php'] as $filename) {
    (require CMS_ROOT . '/content/plugins/official.restaurant/migrations/' . $filename)['up']($pdo);
}
$repo = new RestaurantRepository($pdo);
$print = new RestaurantPrintService($repo);
$tickets = new RestaurantTicketService($repo, $print);
$commerce = new CommerceRepository($pdo, 'tax-contract-only');
$checkout = new RestaurantCheckoutService($repo, $tickets, $commerce, $pdo, 'tax-contract-only');
$controller = new RestaurantController($repo, $tickets, $print, $checkout);
$settings = ['status' => 'open', 'pickup_available' => true, 'delivery_available' => true, 'delivery_postal_codes' => ['TEST01'], 'delivery_fee_minor' => 350, 'merchant_region' => 'US', 'transaction_region' => 'international'];
$save = static fn (array $taxSettings) => $repo->saveStorefrontSettings(['name' => 'LOCAL TAX CONTRACT ONLY', 'timezone' => 'America/New_York', 'settings' => $taxSettings]);
$save($settings);
$assert(!$repo->taxSettings()['configured'], 'Missing tax settings remain unconfirmed, not an assumed exemption.');
$metadata = $repo->withPaidAddonRows([], [['kind' => 'rice', 'price' => '1.50'], ['kind' => 'chicken', 'price' => '3.00']]);
$itemId = $repo->saveMenuItem(['name' => 'LOCAL TAX TEST', 'category' => 'Wok', 'price' => '9.95', 'currency' => 'USD', 'metadata' => $metadata]);
$chosen = array_map(static fn (array $option): array => ['group_id' => 'paid_addons', 'option_id' => $option['id'], 'price' => '0.00'], $metadata['modifiers'][0]['options']);
$cart = $repo->createCart(['currency' => 'USD']);
$summary = $repo->addCartItem($cart['token'], $itemId, 2, $chosen, 'Local test only');
$assert($summary['subtotal_minor'] === 2890, 'Server subtotal includes paid add-ons and quantity, ignores claimed option price.');
$reject(fn () => $checkout->prepareCommerceOrder($cart['token'], 'test.provider'), 'Unconfirmed tax rules block new Commerce payment orders.');
$assert((int) ($repo->cartByToken($cart['token'])['commerce_order_id'] ?? 0) === 0, 'Missing tax rules do not create an order handoff.');
$taxSettings = $repo->withTaxSettings($settings, '8.25', 'no');
$save($taxSettings);
$assert($repo->taxSettings()['rate_basis_points'] === 825 && $repo->taxSettings()['rate_percent'] === '8.25', 'Percent is stored as exact integer basis points.');
$summary = $repo->cartSummary($cart['token'], 'pickup');
$assert($summary['tax_minor'] === 238 && $summary['total_minor'] === 3128 && $summary['tax_amount'] === '2.38', 'Hypothetical 8.25% pickup: 28.90 + 2.38 = 31.28 USD.');
$beforeQuote = $repo->cartByToken($cart['token']);
$delivery = $repo->cartSummary($cart['token'], 'delivery');
$assert($delivery['fee_minor'] === 350 && $delivery['tax_minor'] === 238 && $delivery['total_minor'] === 3478, 'Delivery fee is excluded from tax when explicitly configured.');
$assert($repo->cartByToken($cart['token']) === $beforeQuote, 'Delivery quote is read-only, preserves pickup cart and customer state.');
$save($repo->withTaxSettings($settings, '8.25', 'yes'));
$delivery = $repo->cartSummary($cart['token'], 'delivery');
$assert($delivery['tax_minor'] === 267 && $delivery['total_minor'] === 3507, 'Taxable delivery fee is included in the tax base once.');
$assert($repo->cartSummary($cart['token'], 'pickup')['tax_minor'] === 238, 'Switching back to pickup removes delivery fee and its tax.');
$reject(fn () => $repo->cartSummary($cart['token'], 'unknown'), 'Unknown quote service mode is rejected.');
$response = $controller->apiCart(new Request('GET', '/restaurant/api/cart', ['service_mode' => 'delivery', 'tax_rate_basis_points' => 0], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $cart['token']]));
$api = json_decode($response->body(), true);
$assert($response->status() === 200 && $api['tax_minor'] === 267 && $api['service_mode'] === 'delivery', 'Bearer public API returns server-authoritative mode, fee, and tax quote.');
$assert(!str_contains($response->body(), 'customer_phone') && !str_contains($response->body(), 'pricing_snapshot'), 'Tax quote does not leak private cart fields or snapshots.');
$response = $controller->apiCart(new Request('GET', '/restaurant/api/cart', ['service_mode' => 'invalid'], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $cart['token']]));
$assert($response->status() === 400, 'Invalid public quote mode returns 400.');
$save($repo->withTaxSettings($settings, '0', 'no'));
$assert($repo->cartSummary($cart['token'])['tax']['configured'] && $repo->cartSummary($cart['token'])['tax_minor'] === 0, 'Explicit zero rate is distinct from missing configuration.');
$assert($repo->withTaxSettings($settings, '0.01', 'no')['tax_rate_basis_points'] === 1, 'Minimum two-decimal rate retains precision.');
foreach (['-1', '100.01', '8.255', 'abc', '1e1'] as $invalidRate) {
    $reject(fn () => $repo->withTaxSettings($settings, $invalidRate, 'no'), 'Invalid rate rejected: ' . $invalidRate);
}
$reject(fn () => $repo->withTaxSettings($settings, '8', ''), 'Delivery tax treatment must be explicitly chosen.');
foreach ([true, 825.5, '825'] as $invalidRate) {
    $reject(fn () => $save($settings + ['tax_rate_basis_points' => $invalidRate, 'delivery_taxable' => false]), 'Advanced tax JSON requires an integer rate.');
}
$reject(fn () => $save($settings + ['tax_rate_basis_points' => 825, 'delivery_taxable' => 'false']), 'Boolean delivery tax setting cannot be a truthy string.');
$cleared = $repo->withTaxSettings($taxSettings, '', '');
$assert(!isset($cleared['tax_rate_basis_points']) && $cleared['delivery_postal_codes'] === ['TEST01'], 'Clearing tax preserves other merchant settings.');
$save($repo->withTaxSettings($settings, '10', 'no'));
$tinyId = $repo->saveMenuItem(['name' => 'LOCAL ROUNDING TEST', 'category' => 'Test', 'price' => '0.05', 'currency' => 'USD']);
$tinyCart = $repo->createCart(['currency' => 'USD']);
$tinySummary = $repo->addCartItem($tinyCart['token'], $tinyId, 1);
$assert($tinySummary['tax_minor'] === 1 && $tinySummary['total_minor'] === 6, 'Half-cent tax rounds half up at order level, using integer arithmetic.');
$empty = $repo->createCart(['currency' => 'USD']);
$assert($repo->cartSummary($empty['token'], 'delivery')['total_minor'] === 0, 'Empty cart has no delivery charge or tax.');
$save($taxSettings);
$quote = $repo->cartSummary($cart['token'], 'pickup');
$save($repo->withTaxSettings($settings, '9', 'no'));
$reject(fn () => $checkout->prepareCommerceOrder($cart['token'], 'test.provider', ['expected_total_minor' => $quote['total_minor']]), 'Merchant tax change blocks payment against an outdated displayed amount.');
$assert((int) ($repo->cartByToken($cart['token'])['commerce_order_id'] ?? 0) === 0, 'Stale price rejection does not create a Commerce order.');
$save($taxSettings);
$order = $checkout->prepareCommerceOrder($cart['token'], 'test.provider', ['service_mode' => 'delivery', 'delivery_address' => 'LOCAL TEST ADDRESS', 'delivery_postal_code' => 'TEST01', 'expected_total_minor' => 3478, 'tax_minor' => 0]);
$storedCart = $repo->cartByToken($cart['token']);
$product = $commerce->product((int) $storedCart['commerce_product_id']);
$assert((int) $order['order']['amount_minor'] === 3478, 'Actual in-memory Commerce order amount equals food + fee + tax, not an external payment.');
$assert((int) $product['price_minor'] === 2890 && (int) $product['shipping_fee_minor'] === 350 && (int) $product['tax_fee_minor'] === 238, 'Commerce public product API keeps separate subtotal, delivery, and tax without double charging.');
$save($repo->withTaxSettings($settings, '12', 'yes'));
$frozen = $repo->cartSummary($cart['token'], 'pickup');
$assert($frozen['total_minor'] === 3478 && $frozen['tax_minor'] === 238 && $frozen['service_mode'] === 'delivery' && $frozen['tax']['rate_basis_points'] === 825, 'Submitted order pricing is frozen despite tax edits or a different quote mode.');
$retry = $checkout->prepareCommerceOrder($cart['token'], 'test.provider', ['service_mode' => 'delivery', 'delivery_address' => 'LOCAL TEST ADDRESS', 'delivery_postal_code' => 'TEST01']);
$assert($retry['order']['id'] === $order['order']['id'] && $retry['payment_key'] === $order['payment_key'], 'Pending retry reuses the public bound Commerce order and payment key, without reserving stock twice.');
$assert($repo->cartSummary($cart['token'])['total_minor'] === 3478 && $repo->cartByToken($cart['token'])['commerce_order_id'] === $storedCart['commerce_order_id'], 'Retry preserves frozen tax, amount and original order binding.');
$reject(fn () => $checkout->prepareCommerceOrder($cart['token'], 'different.provider'), 'Pending retry cannot change the payment provider.');
$reject(fn () => $repo->addCartItem($cart['token'], $itemId, 1), 'Checkout-started cart cannot add food after amount is frozen.');
$response = $controller->saveStorefrontSettings(new Request('POST', '/admin/restaurant/storefront/save', [], ['name' => 'LOCAL ADMIN TAX TEST', 'timezone' => 'America/New_York', 'settings_json' => json_encode($settings), 'tax_fields_present' => '1', 'tax_rate' => '8.25', 'delivery_taxable' => 'no']));
$assert($response->status() === 302 && $repo->taxSettings()['rate_basis_points'] === 825, 'Native admin form stores the explicit rate and delivery rule.');
$beforeInvalid = $repo->storefrontSettings();
$response = $controller->saveStorefrontSettings(new Request('POST', '/admin/restaurant/storefront/save', [], ['name' => 'INVALID', 'settings_json' => json_encode($settings), 'tax_fields_present' => '1', 'tax_rate' => '-9', 'delivery_taxable' => 'no']));
$assert($response->status() === 400 && $repo->storefrontSettings() === $beforeInvalid, 'Invalid admin tax input preserves all merchant settings.');
$assert(str_contains($controller->storefrontSettings()->body(), 'name="tax_rate"') && str_contains($controller->storefrontSettings()->body(), 'name="delivery_taxable"'), 'Existing protected admin page exposes explicit tax controls.');
echo "official_restaurant_tax: PASS ($assertions assertions; no gateway call)\n";
