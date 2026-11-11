<?php

declare(strict_types=1);

define('CMS_ROOT', getenv('RESTAURANT_TEST_ROOT') ?: __DIR__ . '/..');

require_once CMS_ROOT . '/system/core/Bootstrap/autoload.php';
require_once CMS_ROOT . '/content/plugins/official.commerce/src/CommerceRepository.php';
require_once CMS_ROOT . '/content/plugins/official.restaurant/src/RestaurantRepository.php';
require_once CMS_ROOT . '/content/plugins/official.restaurant/src/RestaurantPrintService.php';
require_once CMS_ROOT . '/content/plugins/official.restaurant/src/RestaurantTicketService.php';
require_once CMS_ROOT . '/content/plugins/official.restaurant/src/RestaurantCheckoutService.php';
require_once CMS_ROOT . '/content/plugins/official.restaurant/src/RestaurantController.php';

use Cms\Core\Plugin\PluginManifest;
use Daiying\Commerce\CommerceRepository;
use Official\Restaurant\RestaurantCheckoutService;
use Official\Restaurant\RestaurantController;
use Official\Restaurant\RestaurantPrintService;
use Official\Restaurant\RestaurantRepository;
use Official\Restaurant\RestaurantTicketService;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures++;
        fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
};

$manifest = json_decode((string) file_get_contents(CMS_ROOT . '/content/plugins/official.restaurant/plugin.json'), true);
$assert(is_array($manifest), 'Restaurant manifest is valid JSON.');
$parsed = PluginManifest::fromArray($manifest);
$assert($parsed->id === 'official.restaurant', 'Restaurant plugin uses the official.restaurant ID.');
$assert(($manifest['name'] ?? '') === '餐厅外卖', 'Restaurant plugin display name is Chinese.');
$assert(($manifest['table_prefixes'] ?? []) === ['restaurant_'], 'Restaurant plugin declares only the restaurant_ table namespace.');
$assert(in_array('official.commerce', $manifest['required_plugins'] ?? [], true), 'Restaurant plugin depends on Commerce instead of reimplementing checkout.');
$assert(in_array('restaurant.print', $manifest['capabilities'] ?? [], true), 'Restaurant plugin declares print capability.');
$assert(($manifest['data_schema_version'] ?? '') === '0.1.0-alpha.16' && in_array('migrations/005_pos_connections.php', $manifest['migrations'], true), 'POS migration is explicitly declared with its new schema version.');
$assert(in_array('migrations/002_restaurant_checkout.php', $manifest['migrations'] ?? [], true), 'Restaurant checkout migration is declared.');

foreach (glob(CMS_ROOT . '/content/plugins/official.restaurant/{plugin.php,src/*.php,migrations/*.php}', GLOB_BRACE) ?: [] as $file) {
    $output = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
    $assert($code === 0, basename($file) . ' passes PHP syntax.');
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
foreach (glob(CMS_ROOT . '/system/migrations/*payment*.php') ?: [] as $paymentMigration) {
    (require $paymentMigration)->up($pdo);
}
foreach (glob(CMS_ROOT . '/content/plugins/official.commerce/migrations/*.php') ?: [] as $commerceMigration) {
    $migration = require $commerceMigration;
    $migration['up']($pdo);
}
foreach (['001_restaurant_core.php', '002_restaurant_checkout.php', '003_agent_claims.php', '004_print_bridge.php'] as $restaurantMigration) {
    $migration = require CMS_ROOT . '/content/plugins/official.restaurant/migrations/' . $restaurantMigration;
    $migration['up']($pdo);
}

foreach (['restaurant_kitchen_stations', 'restaurant_print_devices', 'restaurant_menu_items', 'restaurant_order_tickets', 'restaurant_ticket_items', 'restaurant_print_jobs', 'restaurant_carts', 'restaurant_cart_items'] as $table) {
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($table));
    $assert($stmt !== false && $stmt->fetchColumn() === $table, $table . ' table exists.');
}

$repo = new RestaurantRepository($pdo);
$print = new RestaurantPrintService($repo);
$tickets = new RestaurantTicketService($repo, $print);
$commerce = new CommerceRepository($pdo, 'restaurant-test-key');
$checkout = new RestaurantCheckoutService($repo, $tickets, $commerce, $pdo, 'restaurant-test-key');
$controller = new RestaurantController($repo, $tickets, $print, $checkout);

$repo->saveStation(['name' => '油锅', 'code' => 'fry', 'sort_order' => 10]);
$repo->saveStation(['name' => '寿司', 'code' => 'sushi', 'sort_order' => 20]);
$repo->saveStation(['name' => '炒锅', 'code' => 'wok', 'sort_order' => 30]);
$assert(count($repo->activeStations()) === 3, 'Multiple kitchen stations can be configured.');

$repo->savePrinter(['name' => '油锅主打印机', 'provider' => 'manual', 'station_codes' => ['fry']]);
$repo->savePrinter(['name' => '油锅备份打印机', 'provider' => 'manual', 'station_codes' => ['fry']]);
$repo->savePrinter(['name' => '寿司打印助手', 'provider' => 'agent', 'station_codes' => ['sushi'], 'agent_id' => 'sushi-tablet', 'agent_token' => 'agent-secret']);
$assert(count($repo->printersForStation('fry')) === 2, 'One station can fan out to multiple printers.');
$assert(count($repo->printersForStation('sushi')) === 1, 'Station printer mapping is scoped.');
$assert(count($repo->printersForStation('wok')) === 0, 'Station without printer is allowed and falls back to manual queue.');

$repo->saveMenuItem(['name' => '炸鸡', 'category' => '炸物', 'price' => '12.99', 'currency' => 'CNY', 'station_code' => 'fry', 'menu_section' => 'kids']);
$sushiId = $repo->saveMenuItem(['name' => '三文鱼卷', 'category' => '寿司', 'price' => '8.50', 'currency' => 'CNY', 'station_code' => 'sushi']);
$pdo->prepare('UPDATE restaurant_menu_items SET modifiers_json = :json WHERE id = :id')->execute([
    ':id' => $sushiId,
    ':json' => json_encode([
        'featured' => true,
        'image_url' => '/media/salmon-roll.jpg',
        'description' => ['zh-CN' => '三文鱼、米饭和海苔。', 'en-US' => 'Salmon, rice, and nori.'],
        'tags' => [['zh-CN' => '招牌', 'en-US' => 'Favorite']],
        'modifiers' => [['id' => 'addons', 'name' => ['zh-CN' => '加料', 'en-US' => 'Add-ons'], 'max' => 2, 'options' => [['id' => 'avocado', 'name' => ['zh-CN' => '加牛油果', 'en-US' => 'Avocado'], 'price' => '1.25']]]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
]);
$assert(count($repo->activeMenuItems()) === 2, 'Menu items can be assigned to station codes.');
$assert($repo->moneyToMinor('12.99') === 1299, 'Restaurant prices use normal decimal input and persist as minor units.');

$pdo->prepare('INSERT INTO restaurant_locations (name, status, phone, address, timezone, settings_json, created_at, updated_at) VALUES (:name, :status, :phone, :address, :timezone, :settings, :created_at, :updated_at)')->execute([
    ':name' => 'Daiying Kitchen',
    ':status' => 'active',
    ':phone' => '555-0200',
    ':address' => '100 Main Street',
    ':timezone' => 'America/New_York',
    ':settings' => json_encode([
        'name' => ['zh-CN' => '戴影餐厅', 'en-US' => 'Daiying Kitchen', 'es-US' => 'Cocina Daiying'],
        'status' => 'open',
        'today_hours' => ['zh-CN' => '今日 11:00-21:00', 'en-US' => 'Today 11:00 AM-9:00 PM'],
        'pickup_available' => true,
        'merchant_region' => 'US',
        'transaction_region' => 'international',
        'tax_rate_basis_points' => 0,
        'delivery_taxable' => false,
        'delivery_available' => false,
        'delivery_reason' => ['zh-CN' => '配送暂未开放', 'en-US' => 'Delivery is not available yet.'],
        'storefront_image_url' => '/media/storefront.jpg',
        'desktop_focal_point' => '50% 40%',
        'mobile_focal_point' => '45% 35%',
        'gallery' => [['url' => '/media/dining-room.jpg', 'alt' => ['zh-CN' => '大厅', 'en-US' => 'Dining room']]],
        'about_body' => ['zh-CN' => '本地餐馆。', 'en-US' => 'A local restaurant.'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ':created_at' => gmdate('Y-m-d H:i:s'),
    ':updated_at' => gmdate('Y-m-d H:i:s'),
]);
$storefront = $repo->storefrontPayload('en-US');
$menuPayload = $repo->menuPayload('en-US', 'salmon');
$assert(($storefront['restaurant']['name'] ?? '') === 'Daiying Kitchen', 'Storefront API returns localized restaurant identity.');
$assert(($storefront['restaurant']['service_modes']['delivery']['available'] ?? true) === false, 'Storefront API returns plugin-provided service mode availability.');
$assert(count($storefront['gallery'] ?? []) === 1, 'Storefront API exposes gallery data without exposing private plugin tables.');
$assert(count($menuPayload['items'] ?? []) === 1 && ($menuPayload['items'][0]['description'] ?? '') === 'Salmon, rice, and nori.', 'Menu API supports localized search payloads.');
$assert(count($menuPayload['featured'] ?? []) === 1, 'Menu API exposes house favorite items.');
$sections = array_column($repo->menuPayload('zh-CN')['items'], 'menu_section', 'name');
$assert(($sections['炸鸡'] ?? '') === 'kids' && ($sections['三文鱼卷'] ?? '') === 'all_day', 'Menu API exposes meal sections and defaults legacy items to all day.');
$originalSettings = (string) $pdo->query('SELECT settings_json FROM restaurant_locations LIMIT 1')->fetchColumn();
$scheduledSettings = json_decode($originalSettings, true);
$scheduledSettings['hours'] = ['thu' => [['open' => '11:00', 'close' => '21:00']]];
$scheduledSettings['preorder_available'] = true;
$pdo->prepare('UPDATE restaurant_locations SET settings_json = :settings')->execute([':settings' => json_encode($scheduledSettings, JSON_UNESCAPED_UNICODE)]);
$assert(($repo->storefrontPayload('en-US', new DateTimeImmutable('2026-10-08 12:00:00', new DateTimeZone('America/New_York')))['restaurant']['status'] ?? '') === 'open', 'Plugin computes open status from local opening hours.');
$assert(($repo->storefrontPayload('en-US', new DateTimeImmutable('2026-10-08 20:45:00', new DateTimeZone('America/New_York')))['restaurant']['status'] ?? '') === 'closing_soon', 'Plugin computes closing-soon status.');
$assert(($repo->storefrontPayload('en-US', new DateTimeImmutable('2026-10-08 22:00:00', new DateTimeZone('America/New_York')))['restaurant']['status'] ?? '') === 'closed', 'Plugin computes closed status.');
$scheduleNow = new DateTimeImmutable('2026-10-08 11:00:00', new DateTimeZone('America/New_York'));
$scheduled = $repo->validateFulfillment('pickup', '2026-10-08T12:00', '', '', $scheduleNow);
$assert(($scheduled['due_at'] ?? '') === '2026-10-08 16:00:00', 'Advance pickup stores validated restaurant-local time in UTC.');
foreach (['2026-10-08T11:15', '2026-10-16T12:00'] as $invalidTime) {
    try {
        $repo->validateFulfillment('pickup', $invalidTime, '', '', $scheduleNow);
        $assert(false, 'Preorder rejects lead-time or maximum-window violations.');
    } catch (RuntimeException) {
        $assert(true, 'Preorder rejects lead-time or maximum-window violations.');
    }
}
try {
    $repo->validateFulfillment('pickup', '2026-10-08T10:00', '', '', $scheduleNow);
    $assert(false, 'Preorder rejects time outside opening hours.');
} catch (RuntimeException) {
    $assert(true, 'Preorder rejects time outside opening hours.');
}
$pdo->prepare('UPDATE restaurant_locations SET settings_json = :settings')->execute([':settings' => $originalSettings]);
$pdo->prepare("UPDATE restaurant_menu_items SET status = 'sold_out' WHERE id = :id")->execute([':id' => $sushiId]);
$soldOut = $repo->menuPayload('es-US');
$assert(($soldOut['items'][1]['status'] ?? '') === 'sold_out' || count(array_filter($soldOut['items'], static fn (array $item): bool => $item['status'] === 'sold_out')) === 1, 'Sold-out items remain visible in public menu.');
$pdo->prepare("UPDATE restaurant_menu_items SET status = 'active' WHERE id = :id")->execute([':id' => $sushiId]);

$result = $tickets->createTicketWithPrintJobs([
    'customer_name' => '王小明',
    'customer_phone' => '555-0100',
    'service_mode' => 'pickup',
], [
    ['item_name' => '炸鸡', 'quantity' => 1, 'station_code' => 'fry'],
    ['item_name' => '三文鱼卷', 'quantity' => 2, 'station_code' => 'sushi'],
    ['item_name' => '牛肉炒饭', 'quantity' => 1, 'station_code' => 'wok'],
]);

$assert($result['ticket_id'] > 0, 'Ticket is created.');
$assert(count($result['print_job_ids']) === 4, 'Ticket creates two fry jobs, one sushi job, and one manual wok fallback job.');
$jobs = $repo->printJobs();
$stationCounts = [];
foreach ($jobs as $job) {
    $stationCounts[(string) $job['station_code']] = ($stationCounts[(string) $job['station_code']] ?? 0) + 1;
}
$assert(($stationCounts['fry'] ?? 0) === 2, 'Fry station prints to both main and backup printers.');
$assert(($stationCounts['sushi'] ?? 0) === 1, 'Sushi station gets one agent print job.');
$assert(($stationCounts['wok'] ?? 0) === 1, 'Wok station without printer creates one manual job.');
$assert(str_contains((string) $jobs[0]['payload_text'], 'Daiying 餐厅小票'), 'Print payload is rendered as a kitchen ticket.');

$badAgentJobs = $repo->queuedAgentJobs('sushi-tablet', 'wrong-token');
$goodAgentJobs = $repo->queuedAgentJobs('sushi-tablet', 'agent-secret');
$assert($badAgentJobs === [], 'Agent cannot read jobs with an invalid token.');
$assert(count($goodAgentJobs) === 1 && ($goodAgentJobs[0]['station_code'] ?? '') === 'sushi', 'Agent can read only its authorized station jobs.');
$assert($repo->queuedAgentJobs('sushi-tablet', 'agent-secret') === [], 'Claimed job cannot be taken by a second poll.');
$agentJobId = (int) $goodAgentJobs[0]['id'];
$claimToken = (string) ($goodAgentJobs[0]['claim_token'] ?? '');
$assert(!$repo->reportAgentJob('sushi-tablet', 'wrong-token', $agentJobId, $claimToken, 'printed'), 'Wrong agent token cannot report a print job.');
$assert(!$repo->reportAgentJob('sushi-tablet', 'agent-secret', $agentJobId, 'wrong-claim', 'printed'), 'Wrong claim cannot report a print job.');
$assert($repo->reportAgentJob('sushi-tablet', 'agent-secret', $agentJobId, $claimToken, 'printed'), 'Authorized claim can mark job printed.');
$assert($repo->reportAgentJob('sushi-tablet', 'agent-secret', $agentJobId, $claimToken, 'printed'), 'Repeated report is idempotent.');
$pdo->prepare("UPDATE restaurant_print_jobs SET status = 'printing', claim_expires_at = '2000-01-01 00:00:00' WHERE id = :id")->execute([':id' => $agentJobId]);
$assert(!$repo->reportAgentJob('sushi-tablet', 'agent-secret', $agentJobId, $claimToken, 'printed'), 'Expired agent claim cannot report success.');
$assert($repo->queuedAgentJobs('sushi-tablet', 'agent-secret') === [], 'Expired agent job is held for manual review, not automatically reprinted.');
$pdo->prepare("UPDATE restaurant_print_jobs SET status = 'printed' WHERE id = :id")->execute([':id' => $agentJobId]);
$printed = array_values(array_filter($repo->printJobs(), static fn (array $job): bool => (int) $job['id'] === (int) $goodAgentJobs[0]['id']))[0] ?? [];
$assert(($printed['status'] ?? '') === 'printed', 'Agent print report can mark a job printed.');
$unauthorizedReport = $controller->agentReport(new \Cms\Core\Http\Request('POST', '/restaurant/agent/jobs/report', [], ['job_id' => $agentJobId, 'status' => 'failed']));
$assert($unauthorizedReport->status() === 403, 'Public agent report refuses a bare job ID.');

$cartToken = 'cart_contract';
$cart = $repo->addCartItem($cartToken, $sushiId, 2, [['group_id' => 'addons', 'option_id' => 'avocado', 'price' => '0.01']], '少酱油');
$cartWithoutBearer = $controller->apiCart(new \Cms\Core\Http\Request('GET', '/restaurant/api/cart', ['token' => $cartToken]));
$assert($cartWithoutBearer->status() === 401, 'Cart read ignores token in URL.');
$cartWithBearer = $controller->apiCart(new \Cms\Core\Http\Request('GET', '/restaurant/api/cart', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $cartToken]));
$assert($cartWithBearer->status() === 200 && !str_contains($cartWithBearer->body(), 'customer_phone') && !str_contains($cartWithBearer->body(), 'completion_claim'), 'Public cart excludes customer data and payment claims.');
$assert(($cart['count'] ?? 0) === 2, 'Menu item can be added to Restaurant cart.');
$assert((int) ($cart['total_minor'] ?? 0) === 1950, 'Restaurant cart includes modifier pricing.');
$assert(($cart['currency'] ?? '') === 'CNY', 'Cart currency follows the item currency.');
try {
    $repo->addCartItem($cartToken, $sushiId, 1, [['group_id' => 'addons', 'option_id' => 'invented', 'price' => '0.00']]);
    $assert(false, 'Unknown modifier is rejected.');
} catch (InvalidArgumentException) {
    $assert(true, 'Unknown modifier is rejected.');
}
$assert((int) ($repo->cartSummary($cartToken)['total_minor'] ?? 0) === 1950, 'Rejected modifier does not change the cart.');
$highOptionId = $repo->saveMenuItem(['name' => '高价规格测试', 'category' => '测试', 'price' => '8.50', 'currency' => 'CNY', 'metadata' => ['modifiers' => [['id' => 'size', 'max' => 1, 'options' => [['id' => 'large', 'price' => '1000']]]]]]);
$highOptionCart = $repo->addCartItem('cart_large_option', $highOptionId, 1, [['group_id' => 'size', 'option_id' => 'large']]);
$assert((int) ($highOptionCart['total_minor'] ?? 0) === 100850, 'Integer-valued modifier prices are interpreted as decimal money, not minor units.');
$mealPrices = ['lunch' => '9.00', 'dinner' => '15.00', 'kids' => '6.00'];
foreach ($mealPrices as $section => $price) {
    $itemId = $repo->saveMenuItem(['name' => '分组套餐', 'category' => '套餐', 'price' => $price, 'currency' => 'CNY', 'menu_section' => $section]);
    $menuItem = array_values(array_filter($repo->menuPayload('zh-CN')['items'], static fn (array $item): bool => (int) $item['id'] === $itemId))[0] ?? [];
    $cartSummary = $repo->addCartItem('meal_price_' . $section, $itemId, 1);
    $assert(($menuItem['menu_section'] ?? '') === $section && ($menuItem['price'] ?? '') === $price && (int) ($cartSummary['total_minor'] ?? 0) === $repo->moneyToMinor($price), 'Menu and cart use the ' . $section . ' price independently.');
    $mealCart = $repo->cartByToken('meal_price_' . $section);
    $repo->assertCartSellable($mealCart, $repo->cartItems((int) $mealCart['id']));
}
$pdo->prepare("UPDATE restaurant_menu_items SET status = 'sold_out' WHERE id = :id")->execute([':id' => $sushiId]);
try {
    $checkout->prepareCommerceOrder($cartToken, 'test.provider', ['service_mode' => 'delivery']);
    $assert(false, 'Unconfigured delivery checkout is rejected.');
} catch (RuntimeException) {
    $assert(true, 'Unconfigured delivery checkout is rejected.');
}
try {
    $checkout->prepareCommerceOrder($cartToken, 'test.provider', ['service_mode' => 'pickup']);
    $assert(false, 'Checkout rejects a cart item that became sold out.');
} catch (RuntimeException) {
    $assert(true, 'Checkout rejects a cart item that became sold out.');
}
$pdo->prepare("UPDATE restaurant_menu_items SET status = 'active' WHERE id = :id")->execute([':id' => $sushiId]);
$handoff = $checkout->prepareCommerceOrder($cartToken, 'test.provider', [
    'service_mode' => 'pickup',
    'customer_name' => '李小白',
    'customer_phone' => '555-0300',
    'customer_email' => 'buyer@example.com',
]);
$commerceOrder = is_array($handoff['order'] ?? null) ? $handoff['order'] : [];
$assert(!str_contains((string) ($handoff['success_url'] ?? ''), 'token=') && !str_contains((string) ($handoff['success_url'] ?? ''), 'payment_key='), 'Payment return URL excludes cart and payment keys.');
$assert((int) ($commerceOrder['amount_minor'] ?? 0) === 1950, 'Restaurant checkout creates a Commerce order with cart total.');
$storedCart = $repo->cartByToken($cartToken);
$assert((int) ($storedCart['commerce_order_id'] ?? 0) === (int) ($commerceOrder['id'] ?? 0), 'Restaurant cart stores Commerce order handoff.');
$commerceProduct = $commerce->product((int) ($storedCart['commerce_product_id'] ?? 0));
$commerceAction = $commerce->action((int) ($storedCart['commerce_action_id'] ?? 0));
$assert(is_array($commerceProduct) && ($commerceProduct['source_url'] ?? null) === null && ($commerceAction['action_type'] ?? '') === 'site_checkout', 'Restaurant handoff creates an on-site Commerce checkout action, not an affiliate link.');
$assert(($commerceProduct['region'] ?? '') === 'US' && ($commerceProduct['transaction_region'] ?? '') === 'international', 'Restaurant checkout uses configured merchant and Commerce region.');
$commerce->markOrderPaid((int) ($commerceOrder['id'] ?? 0));
try {
    $commerce->updatePendingOrderClaim((int) ($commerceOrder['id'] ?? 0), 'replacement');
    $assert(false, 'Paid Commerce order rejects claim changes.');
} catch (RuntimeException) {
    $assert(true, 'Paid Commerce order rejects claim changes.');
}
$ticketResult = $checkout->createKitchenTicketForOrder((int) ($commerceOrder['id'] ?? 0));
$assert(($ticketResult['created'] ?? false) === true, 'Paid Commerce order creates a Restaurant kitchen ticket.');
$repeatResult = $checkout->createKitchenTicketForOrder((int) ($commerceOrder['id'] ?? 0));
$assert(($repeatResult['created'] ?? true) === false && (int) $repeatResult['ticket_id'] === (int) $ticketResult['ticket_id'], 'Paid order ticket creation is idempotent.');
try {
    $repo->createTicket(['commerce_order_id' => (int) $commerceOrder['id'], 'service_mode' => 'pickup'], [['item_name' => 'Duplicate', 'quantity' => 1]]);
    $assert(false, 'Database rejects a duplicate Commerce kitchen ticket.');
} catch (PDOException) {
    $assert(true, 'Database rejects a duplicate Commerce kitchen ticket.');
}
$cartAfterTicket = $repo->cartByToken($cartToken);
$assert(($cartAfterTicket['status'] ?? '') === 'ticketed', 'Restaurant cart is marked ticketed after kitchen ticket creation.');

$deliverySettings = json_decode($originalSettings, true);
$deliverySettings['delivery_available'] = true;
$deliverySettings['delivery_fee_minor'] = 350;
$deliverySettings['delivery_postal_codes'] = ['10001'];
$deliverySettings['preorder_available'] = true;
$deliverySettings['hours'] = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], [['open' => '00:00', 'close' => '23:59']]);
$pdo->prepare('UPDATE restaurant_locations SET settings_json = :settings')->execute([':settings' => json_encode($deliverySettings, JSON_UNESCAPED_UNICODE)]);
$assert(($repo->storefrontPayload()['restaurant']['service_modes']['delivery']['available'] ?? false) === true, 'Delivery is available only after explicit area and fee configuration.');
$deliveryCartToken = 'cart_delivery_contract';
$repo->addCartItem($deliveryCartToken, $sushiId, 1);
$deliveryTime = (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->modify('+2 hours')->format('Y-m-d\TH:i');
try {
    $checkout->prepareCommerceOrder($deliveryCartToken, 'test.provider', ['service_mode' => 'delivery', 'delivery_address' => '100 Main Street', 'delivery_postal_code' => '99999', 'fulfillment_at' => $deliveryTime]);
    $assert(false, 'Delivery rejects an unserved postal code.');
} catch (RuntimeException) {
    $assert(true, 'Delivery rejects an unserved postal code.');
}
$deliveryBefore = $repo->cartSummary($deliveryCartToken);
$deliveryHandoff = $checkout->prepareCommerceOrder($deliveryCartToken, 'test.provider', ['service_mode' => 'delivery', 'delivery_address' => '100 Main Street', 'delivery_postal_code' => '10001', 'fulfillment_at' => $deliveryTime]);
$deliveryOrder = $deliveryHandoff['order'];
$assert((int) $deliveryOrder['amount_minor'] === (int) $deliveryBefore['subtotal_minor'] + 350, 'Delivery fee is included in the Commerce order amount.');
$deliveryCart = $repo->cartByToken($deliveryCartToken);
$assert(($deliveryCart['payload']['fulfillment_at'] ?? null) !== null && ($deliveryCart['payload']['delivery_postal_code'] ?? '') === '10001', 'Scheduled delivery and postal code persist on the cart.');
$commerce->markOrderPaid((int) $deliveryOrder['id']);
$deliveryTicket = $checkout->createKitchenTicketForOrder((int) $deliveryOrder['id']);
$ticketRow = $repo->ticket((int) $deliveryTicket['ticket_id']);
$assert(($ticketRow['service_mode'] ?? '') === 'delivery' && ($ticketRow['due_at'] ?? '') === $deliveryCart['payload']['fulfillment_at'], 'Scheduled delivery due time reaches the kitchen ticket.');
$assert(str_starts_with($repo->formatDueLocal((string) $ticketRow['due_at']), str_replace('T', ' ', $deliveryTime)), 'Kitchen due time is formatted in the restaurant timezone.');
$deliveryPrintJobs = array_values(array_filter($repo->printJobs(), static fn (array $job): bool => (int) $job['ticket_id'] === (int) $deliveryTicket['ticket_id']));
$assert($deliveryPrintJobs !== [] && !str_contains((string) $deliveryPrintJobs[0]['payload_text'], '100 Main Street') && str_contains((string) $deliveryPrintJobs[0]['payload_text'], '预约时间:'), 'Kitchen station ticket shows scheduled time without exposing the delivery address.');
$dashboard = $controller->dashboard(new \Cms\Core\Http\Request('GET', '/admin/restaurant'));
$assert(str_contains($dashboard->body(), '100 Main Street'), 'Order board view shows the delivery address.');

if ($failures > 0) {
    fwrite(STDERR, "official_restaurant_contract failed with {$failures} failure(s).\n");
    exit(1);
}

echo "official_restaurant_contract: PASS\n";
