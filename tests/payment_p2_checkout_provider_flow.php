<?php

declare(strict_types=1);

use Cms\Core\Http\Request;
use Cms\Core\Plugin\PluginRouteDefinition;
use Official\Commerce\Admin\CheckoutController;

require __DIR__ . '/payment_p1_test_helpers.php';

set_time_limit(180);
$failures = 0;
function p2_checkout_check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}

$env = p1_sqlite_root('p2-checkout');
$pdo = $env['pdo'];
p1_install($pdo, true, true);
$runtime = p1_boot($pdo);
$services = p1_services($pdo);
$order = p1_pending_order($pdo, 'P2FLOW');
$controller = new CheckoutController($services['checkout'], $services['orders'], $services['fulfillments'], $services['payment']);
$token = $order['public_token'];

$routeMap = [];
foreach ($runtime->routes() as $route) {
    $routeMap[$route->method . ' ' . $route->path] = $route;
}
p2_checkout_check(isset($routeMap['GET /order/{token}/pay']), 'Commerce registers public payment selection route through plugin runtime');
p2_checkout_check(isset($routeMap['POST /order/{token}/pay']) && $routeMap['POST /order/{token}/pay'] instanceof PluginRouteDefinition && $routeMap['POST /order/{token}/pay']->csrf === true, 'Commerce payment submit route is POST and CSRF protected');

$publicOrder = $controller->publicOrder(new Request('GET', '/order/' . $token));
p2_checkout_check($publicOrder->status() === 200 && str_contains($publicOrder->body(), '/order/' . $token . '/pay'), 'pending public order page links to payment page');

$paymentPage = $controller->payment(new Request('GET', '/order/' . $token . '/pay'));
p2_checkout_check($paymentPage->status() === 200 && str_contains($paymentPage->body(), '模拟支付') && str_contains($paymentPage->body(), '确认支付'), 'payment page lists enabled payment provider with Chinese labels');

$failed = $controller->pay(new Request('POST', '/order/' . $token . '/pay', [], [
    'provider_id' => 'official.payment-fixture',
    'amount_minor' => (string) $order['total'],
    'currency' => 'USD',
    'scenario' => 'failure',
    'idempotency_key' => 'p2-public-failed',
]));
p2_checkout_check($failed->status() === 303 && (string) $services['orders']->order((int) $order['order']['id'])['status'] === 'pending_payment', 'failed fixture public payment redirects safely and keeps order pending');

$paid = $controller->pay(new Request('POST', '/order/' . $token . '/pay', [], [
    'provider_id' => 'official.payment-fixture',
    'amount_minor' => (string) $order['total'],
    'currency' => 'USD',
    'scenario' => 'success',
    'idempotency_key' => 'p2-public-paid',
]));
$paidOrder = $services['orders']->order((int) $order['order']['id']);
p2_checkout_check($paid->status() === 303 && (string) $paidOrder['status'] === 'paid' && (string) $paidOrder['payment_status'] === 'paid', 'successful fixture public payment marks order paid');
p2_checkout_check((int) $pdo->query("SELECT COUNT(*) FROM cms_commerce_payments WHERE provider_id = 'official.payment-fixture'")->fetchColumn() === 2, 'public fixture payments are recorded with provider id');
p2_checkout_check((int) $pdo->query("SELECT COUNT(*) FROM cms_audit_logs WHERE action LIKE 'commerce.payment.provider_%'")->fetchColumn() === 2, 'public fixture payments write audit events without admin impersonation');

$paidAgain = $controller->payment(new Request('GET', '/order/' . $token . '/pay'));
p2_checkout_check($paidAgain->status() === 200 && str_contains($paidAgain->body(), '订单无需支付'), 'paid order payment page no longer offers payment form');

p1_cleanup_env($env);
if ($failures > 0) {
    echo '[RESULT] payment_p2_checkout_provider_flow failed: ' . $failures . PHP_EOL;
    exit(1);
}
echo '[RESULT] payment_p2_checkout_provider_flow passed.' . PHP_EOL;
