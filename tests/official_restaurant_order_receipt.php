<?php

declare(strict_types=1);

define('CMS_ROOT', getenv('RESTAURANT_TEST_ROOT') ?: __DIR__ . '/..');
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';
require CMS_ROOT . '/content/plugins/official.commerce/src/CommerceRepository.php';
foreach (['RestaurantRepository', 'RestaurantPrintService', 'RestaurantTicketService', 'RestaurantCheckoutService', 'RestaurantController'] as $class) {
    require CMS_ROOT . '/content/plugins/official.restaurant/src/' . $class . '.php';
}

use Cms\Core\Http\Request;
use Cms\Core\Payment\PaymentRepository;
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
    echo '[PASS] ' . $message . PHP_EOL;
};
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
foreach (glob(CMS_ROOT . '/system/migrations/*payment*.php') as $path) {
    (require $path)->up($pdo);
}
foreach (glob(CMS_ROOT . '/content/plugins/official.commerce/migrations/*.php') as $path) {
    (require $path)['up']($pdo);
}
foreach (glob(CMS_ROOT . '/content/plugins/official.restaurant/migrations/*.php') as $path) {
    (require $path)['up']($pdo);
}
$restaurant = new RestaurantRepository($pdo);
$printer = new RestaurantPrintService($restaurant);
$tickets = new RestaurantTicketService($restaurant, $printer);
$commerce = new CommerceRepository($pdo, 'order-receipt-isolated-test');
$payments = new PaymentRepository($pdo);
$checkout = new RestaurantCheckoutService($restaurant, $tickets, $commerce, $pdo, 'order-receipt-isolated-test');
$controller = new RestaurantController($restaurant, $tickets, $printer, $checkout);
$restaurant->saveStorefrontSettings(['name' => 'Daiying Sandbox Receipt', 'timezone' => 'America/New_York', 'settings' => $restaurant->withTaxSettings(['status' => 'open', 'pickup_available' => true, 'merchant_region' => 'US', 'transaction_region' => 'international'], '0', 'no')]);
$menuId = $restaurant->saveMenuItem(['name' => 'SANDBOX ONLY', 'currency' => 'USD', 'price' => '10.00']);
$cart = $restaurant->createCart(['currency' => 'USD']);
$otherCart = $restaurant->createCart(['currency' => 'USD']);
$restaurant->addCartItem($cart['token'], $menuId, 1);
$handoff = $checkout->prepareCommerceOrder($cart['token'], 'test.provider', ['service_mode' => 'pickup', 'customer_name' => 'Daiying Sandbox Alice <test>', 'customer_phone' => '+1 555 010 0200', 'customer_email' => 'receipt-test@example.com']);
$orderId = (int) $handoff['order']['id'];
$receipt = $checkout->orderReceiptForCart($cart['token']);
$assert($receipt['order_id'] === $orderId && $receipt['payment_status'] === 'unconfirmed' && !$receipt['pickup_eligible'], 'Pending receipt shows the original Commerce order number, not a paid pickup credential.');
$assert($checkout->orderReceiptForCart($otherCart['token']) === null && $checkout->orderReceiptForCart('wrong') === null, 'Unknown or unrelated cart tokens cannot obtain another order receipt.');
$assert($controller->apiCart(new Request('GET', '/', ['token' => $cart['token'], 'order_id' => $orderId]))->status() === 401, 'Query-string order numbers and cart tokens do not authorize receipt access.');
$foreignResponse = $controller->apiCart(new Request('GET', '/', ['order_id' => $orderId, 'customer_name' => 'Daiying Sandbox Alice <test>', 'customer_email' => 'receipt-test@example.com'], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $otherCart['token']]));
$assert(json_decode($foreignResponse->body(), true)['order_receipt'] === null, 'Guessing another customer name, email or order number does not disclose their receipt.');
$commerce->markOrderPaid($orderId);
$unverifiedTicket = $checkout->createKitchenTicketForOrder($orderId);
$assert($checkout->orderReceiptForCart($cart['token'])['payment_status'] === 'unconfirmed', 'Commerce paid flag alone is not trusted payment confirmation.');
$completionRequest = new Request('GET', '/restaurant/checkout/complete', ['order_id' => $orderId, 'claim' => $handoff['claim']]);
$assert($controller->completeCheckout($completionRequest)->status() === 400, 'Existing kitchen ticket and Commerce paid flag cannot fake a successful completion page.');
$assert($controller->updateTicketStatus(new Request('POST', '/', [], ['ticket_id' => $unverifiedTicket['ticket_id'], 'status' => 'completed']))->status() === 409, 'Unconfirmed payment cannot be marked collected through the restaurant controller.');
$paymentId = $payments->insertPayment(['subject_type' => 'commerce_order', 'subject_id' => 'order:' . $orderId, 'provider_id' => 'test.provider', 'remote_id' => 'receipt-test-payment', 'reference' => 'receipt-test-order', 'status' => 'paid', 'amount_minor' => 1000, 'currency' => 'USD', 'idempotency_key' => 'receipt-test-payment', 'request_hash' => hash('sha256', 'receipt-test-payment')]);
$commerce->attachPayment($orderId, $paymentId);
$checkout->reconcileOrder($orderId);
$ticketId = (int) $unverifiedTicket['ticket_id'];
$restaurant->updateTicketStatus($ticketId, 'ready');
$receipt = $checkout->orderReceiptForCart($cart['token']);
$assert($receipt['payment_status'] === 'paid' && $receipt['pickup_eligible'] && !$receipt['completed'], 'Trusted payment plus ready pickup produces an eligible pickup receipt.');
$assert($receipt['ticket_number'] === $restaurant->ticket($ticketId)['ticket_number'] && $receipt['total'] === '10.00', 'Receipt uses the actual kitchen ticket number and frozen order amount.');
$successfulCompletion = $controller->completeCheckout($completionRequest);
$assert($successfulCompletion->status() === 200 && str_contains($successfulCompletion->body(), '<h1>支付成功</h1>'), 'Current trusted full payment permits the existing-ticket completion page.');
$restaurant->updateTicketStatus($ticketId, 'cancelled');
$assert($controller->completeCheckout($completionRequest)->status() === 400, 'A cancelled ticket cannot become a successful pickup credential through the old payment URL.');
$restaurant->updateTicketStatus($ticketId, 'ready');
$response = $controller->apiCart(new Request('GET', '/', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $cart['token']]));
$publicReceipt = json_decode($response->body(), true)['order_receipt'];
$assert($response->status() === 200 && $response->headers()['Cache-Control'] === 'no-store' && $publicReceipt === $receipt, 'Existing Bearer cart API returns the authoritative receipt without caching.');
foreach (['customer_name', 'customer_phone', 'customer_email', 'completion_claim', 'idempotency_key', 'token', 'payment_id', 'delivery_address'] as $field) {
    $assert(!array_key_exists($field, $publicReceipt), 'Receipt excludes private field: ' . $field);
}
$dashboard = $controller->dashboard(new Request('GET', '/admin/restaurant'))->body();
$assert(str_contains($dashboard, '订单 #' . $orderId) && str_contains($dashboard, '付款已确认') && str_contains($dashboard, '+1 555 010 0200'), 'Authorized staff dashboard can match order, verified payment and customer phone.');
$assert(str_contains($dashboard, 'Alice &lt;test&gt;') && !str_contains($dashboard, 'Alice <test>'), 'Staff customer information is HTML-escaped.');
$jobCount = count($restaurant->printJobs());
$assert($controller->updateTicketStatus(new Request('POST', '/', [], ['ticket_id' => $ticketId, 'status' => 'completed']))->status() === 302, 'Staff can record fulfillment of a trusted paid order.');
$receipt = $checkout->orderReceiptForCart($cart['token']);
$assert($receipt['completed'] && !$receipt['pickup_eligible'], 'Refreshed receipt shows completed and cannot claim pickup eligibility again.');
$assert($controller->updateTicketStatus(new Request('POST', '/', [], ['ticket_id' => $ticketId, 'status' => 'ready']))->status() === 409, 'Completed paid order cannot be reopened for duplicate collection.');
$assert($controller->updateTicketStatus(new Request('POST', '/', [], ['ticket_id' => $ticketId, 'status' => 'completed']))->status() === 302 && count($restaurant->printJobs()) === $jobCount, 'Repeated completion is harmless and creates no duplicate print job.');
foreach ([100, 900] as $amountMinor) {
    $payments->insertRefund(['payment_id' => $paymentId, 'provider_id' => 'test.provider', 'remote_id' => 'receipt-test-refund-' . $amountMinor, 'status' => 'completed', 'amount_minor' => $amountMinor, 'currency' => 'USD', 'reason' => 'ISOLATED MANUAL REFUND LEDGER FIXTURE', 'idempotency_key' => 'receipt-test-refund-' . $amountMinor, 'request_hash' => hash('sha256', 'receipt-test-refund-' . $amountMinor)]);
    $receipt = $checkout->orderReceiptForCart($cart['token']);
    $assert($receipt['payment_status'] === ($amountMinor === 100 ? 'partially_refunded' : 'refunded') && !$receipt['pickup_eligible'], 'Trusted manual-refund ledger changes are reflected without a pickup authorization.');
    $refundedCompletion = $controller->completeCheckout($completionRequest);
    $assert($refundedCompletion->status() === 400 && !str_contains($refundedCompletion->body(), '<h1>支付成功</h1>'), 'Both partial and full refunds invalidate the old successful completion page without creating jobs.');
}
$assert(count($payments->refundsForPayment($paymentId)) === 2 && count($restaurant->printJobs()) === $jobCount, 'Receipt reads never issue refunds or print jobs.');
echo 'official_restaurant_order_receipt: PASS (' . $assertions . ' assertions; isolated software, no payment gateway or physical pickup)' . PHP_EOL;
