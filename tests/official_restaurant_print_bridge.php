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
$_SESSION['admin_user'] = ['id' => 900];
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
(require CMS_ROOT . '/content/plugins/official.restaurant/migrations/004_print_bridge.php')['up']($pdo);
$repo = new RestaurantRepository($pdo);
$print = new RestaurantPrintService($repo);
$tickets = new RestaurantTicketService($repo, $print);
$commerce = new CommerceRepository($pdo, 'print-contract-only');
$checkout = new RestaurantCheckoutService($repo, $tickets, $commerce, $pdo, 'print-contract-only');
$controller = new RestaurantController($repo, $tickets, $print, $checkout);
$payments = new PaymentRepository($pdo);
$credentialA = str_repeat('a', 64);
$credentialB = str_repeat('b', 64);
$deviceA = $repo->savePrinter(['name' => 'LOCAL MODE A', 'provider' => 'agent', 'station_codes' => ['wok'], 'agent_id' => 'bridge-a', 'agent_token' => $credentialA, 'connection_mode' => 'existing', 'adapter' => 'windows_queue']);
$deviceB = $repo->savePrinter(['name' => 'LOCAL MODE B', 'provider' => 'agent', 'station_codes' => ['sushi'], 'agent_id' => 'bridge-b', 'agent_token' => $credentialB, 'connection_mode' => 'independent', 'adapter' => 'escpos_tcp']);
$assert($repo->printer($deviceA)['config']['adapter'] === 'windows_queue' && $repo->printer($deviceB)['config']['connection_mode'] === 'independent', 'Both modes use the same scoped queue and binding records.');
$macDevice = $repo->savePrinter(['name' => 'LOCAL MAC TEST', 'provider' => 'agent', 'station_codes' => ['mac-test'], 'agent_id' => 'bridge-mac', 'agent_token' => $credentialA, 'connection_mode' => 'existing', 'adapter' => 'mac_queue']);
$assert($repo->printer($macDevice)['config']['adapter'] === 'mac_queue', 'Authorized Mac queue uses the existing device binding contract.');
$reject(fn () => $repo->savePrinter(['provider' => 'agent', 'agent_id' => 'bridge-x', 'agent_token' => $credentialA, 'connection_mode' => 'existing', 'adapter' => 'unknown_queue']), 'Unknown adapters remain rejected.');
$reject(fn () => $repo->savePrinter(['provider' => 'agent', 'agent_id' => 'bridge-x', 'agent_token' => $credentialA, 'connection_mode' => 'existing', 'adapter' => 'escpos_tcp']), 'Existing POS device cannot be connected through raw network adapter.');
$reject(fn () => $repo->savePrinter(['provider' => 'agent', 'agent_id' => 'bridge-x', 'agent_token' => 'short', 'adapter' => 'windows_queue']), 'New binding rejects weak credentials.');
$hash = $repo->printer($deviceA)['config']['agent_token_hash'];
$repo->savePrinter(['id' => $deviceA, 'name' => 'LOCAL MODE A EDITED', 'provider' => 'agent', 'station_codes' => ['wok'], 'agent_id' => 'bridge-a', 'adapter' => 'windows_queue']);
$assert($repo->printer($deviceA)['config']['agent_token_hash'] === $hash && $hash !== $credentialA, 'Editing preserves hashed credentials without storing plaintext.');
$request = static fn (array $body = [], array $server = []): Request => new Request('POST', '/restaurant/bridge/jobs/claim', [], ['agent_id' => 'bridge-a'] + $body, $server + ['HTTPS' => 'on', 'HTTP_AUTHORIZATION' => 'Bearer ' . $credentialA]);
$bindings = $controller->bridgeBindings($request());
$assert($bindings->status() === 200 && count(json_decode($bindings->body(), true)['devices']) === 1, 'Binding API returns only the authorized device.');
$assert(!str_contains($bindings->body(), 'token_hash') && !str_contains($bindings->body(), $credentialA), 'Binding API does not disclose credential material.');
$assert($controller->bridgeBindings($request([], ['HTTPS' => 'off', 'HTTP_X_FORWARDED_PROTO' => 'https']))->status() === 403, 'Untrusted proxy header cannot bypass HTTPS.');
$assert($controller->bridgeBindings($request([], ['HTTPS' => 'off', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1']))->status() === 403, 'Loopback exemption is disabled unless site configuration explicitly permits local testing.');
$assert($controller->bridgeBindings($request([], ['HTTP_AUTHORIZATION' => 'Bearer ' . str_repeat('c', 64)]))->status() === 403, 'Invalid device credential is rejected.');
$testA = $tickets->createPrinterTestTicket($deviceA, 'admin:900');
$testB = $tickets->createPrinterTestTicket($deviceB, 'admin:900');
$assert($repo->printJob($testA)['job_kind'] === 'test' && str_contains($repo->printJob($testA)['payload_text'], '测试票，不出餐'), 'Device test is explicitly labeled and never creates a commerce sale.');
$response = $controller->bridgeClaim($request());
$job = json_decode($response->body(), true)['jobs'][0];
$assert($response->status() === 200 && $job['id'] === $testA && $job['printer_id'] === $deviceA, 'Authenticated atomic claim is scoped to the bound device.');
$assert(str_contains($job['payload_text'], '测试炒饭') && str_contains($job['payload_text'], 'Fried rice') && str_contains($job['payload_text'], 'Arroz frito') && str_contains($job['payload_text'], 'Sin cebolla'), 'UTF-8 job carries Chinese, English, Spanish, modifiers and per-dish notes.');
$assert(hash('sha256', $job['payload_text']) === $job['payload_sha256'], 'Payload has an integrity checksum.');
$assert(!str_contains($response->body(), 'claim_token_hash'), 'Public claim response excludes stored authentication hashes.');
$assert(json_decode($controller->bridgeClaim($request())->body(), true)['jobs'] === [], 'Second worker cannot claim a job already leased.');
$assert(!$repo->reportAgentJob('bridge-a', $credentialA, $testB, $job['claim_token'], 'printed'), 'A bridge cannot report another device job.');
$assert($controller->bridgeStart($request(['job_id' => $testA, 'claim_token' => 'wrong']))->status() === 403, 'Wrong job claim cannot start dispatch.');
$assert($controller->bridgeStart($request(['job_id' => $testA, 'claim_token' => $job['claim_token']]))->status() === 200, 'Authorized test dispatch requires a final server start gate.');
$assert($controller->bridgeStart($request(['job_id' => $testA, 'claim_token' => $job['claim_token']]))->status() === 409, 'Start gate is not replayable.');
$assert($controller->bridgeReport($request(['job_id' => $testA, 'claim_token' => $job['claim_token'], 'status' => 'printed']))->status() === 400, 'Spool or socket success cannot falsely claim physical print success.');
$report = $request(['job_id' => $testA, 'claim_token' => $job['claim_token'], 'status' => 'needs_review', 'error' => 'BYTES_SENT_UNCONFIRMED']);
$assert($controller->bridgeReport($report)->status() === 200 && $controller->bridgeReport($report)->status() === 200, 'Unknown result is held, and repeated acknowledgement is idempotent.');
$assert($repo->queuedAgentJobs('bridge-a', $credentialA) === [], 'Unknown print result never auto requeues.');
$reprintKey = str_repeat('d', 32);
$reprint = $repo->reprintJob($testA, 'Owner checked no paper', 'admin:900', $reprintKey);
$assert($reprint !== $testA && $repo->printJob($reprint)['source_job_id'] === $testA && str_contains($repo->printJob($reprint)['payload_text'], 'REIMPRESIÓN'), 'Manual reprint creates a linked and clearly labeled new job.');
$assert($repo->reprintJob($testA, 'Owner checked no paper', 'admin:900', $reprintKey) === $reprint, 'Double submission of the same manual approval does not create a second print job.');
$lease = $repo->queuedAgentJobs('bridge-a', $credentialA)[0];
$pdo->prepare("UPDATE restaurant_print_jobs SET claim_expires_at = '2000-01-01 00:00:00' WHERE id = :id")->execute([':id' => $reprint]);
$assert($repo->queuedAgentJobs('bridge-a', $credentialA) === [] && $repo->printJob($reprint)['status'] === 'needs_review', 'Expired lease after interruption is held rather than blindly reprinted.');
$assert(!$repo->beginAgentDispatch('bridge-a', $credentialA, $reprint, $lease['claim_token']), 'Expired lease cannot authorize output.');
$reject(fn () => $repo->reprintJob($testA, '', 'admin:900', $reprintKey), 'Reprint needs an explicit review reason.');
$repo->confirmManualPrint($testA, 'Owner saw physical test ticket', 'admin:900');
$assert($repo->printJob($testA)['status'] === 'printed', 'Only recorded owner confirmation resolves an uncertain physical result.');
$cancelTicket = (int) $repo->printJob($testB)['ticket_id'];
$repo->updateTicketStatus($cancelTicket, 'cancelled');
$assert($repo->queuedAgentJobs('bridge-b', $credentialB) === [] && $repo->printJob($testB)['status'] === 'cancelled', 'Cancellation before dispatch prevents device output.');
$repo->saveStorefrontSettings(['name' => 'LOCAL PAYMENT PRINT TEST', 'timezone' => 'America/New_York', 'settings' => ['status' => 'open', 'pickup_available' => true, 'merchant_region' => 'US', 'transaction_region' => 'international', 'tax_rate_basis_points' => 0, 'delivery_taxable' => false]]);
$itemId = $repo->saveMenuItem(['name' => 'LOCAL PAID DISH', 'category' => 'Wok', 'price' => '10.00', 'currency' => 'USD', 'station_code' => 'wok']);
$prepare = static function () use ($repo, $itemId, $checkout, $commerce): array {
    $cart = $repo->createCart(['currency' => 'USD']);
    $repo->addCartItem($cart['token'], $itemId, 1, [], 'No onion / 不要葱 / Sin cebolla');
    if (getenv('RESTAURANT_PRINT_FIXTURES_ONLY') === '1') {
        $productId = $commerce->saveProduct(['sku' => 'PRINT-FIXTURE-' . $cart['id'], 'slug' => 'print-fixture-' . $cart['id'], 'name' => 'PRINT SOFTWARE FIXTURE, NOT A SALE', 'price' => '10.00', 'currency' => 'USD', 'region' => 'US', 'transaction_region' => 'international', 'status' => 'active', 'stock_quantity' => 1, 'purchase_mode' => 'local', 'requires_shipping' => 0]);
        $actionId = $commerce->saveAction(['product_id' => $productId, 'action_type' => 'site_checkout', 'label' => 'Fixture only', 'fulfillment_mode' => 'none', 'status' => 'active']);
        $order = $commerce->createPendingOrder($productId, null, $actionId, 1, 'test.provider', 'print-fixture-' . $cart['id'], 'fixture-claim');
        $repo->updateCartCheckout($cart['token'], ['status' => 'checkout_started', 'service_mode' => 'pickup', 'commerce_order_id' => $order['id'], 'commerce_product_id' => $productId, 'commerce_action_id' => $actionId]);
        return ['order' => $order, 'cart' => $repo->cartByToken($cart['token'])];
    }
    return $checkout->prepareCommerceOrder($cart['token'], 'test.provider');
};
$paymentFor = static function (array $order, int $amount = 1000, string $currency = 'USD') use ($payments, $commerce): int {
    $id = (int) $order['order']['id'];
    $paymentId = $payments->insertPayment(['subject_type' => 'commerce_order', 'subject_id' => 'order:' . $id, 'provider_id' => 'test.provider', 'remote_id' => 'fixture-' . $id . '-' . $currency, 'reference' => 'fixture-order-' . $id, 'status' => 'paid', 'amount_minor' => $amount, 'currency' => $currency, 'idempotency_key' => 'fixture-' . $id . '-' . $currency, 'request_hash' => hash('sha256', 'fixture-' . $id . '-' . $currency)]);
    $commerce->attachPayment($id, $paymentId);
    return $paymentId;
};
$paidOrder = $prepare();
$assert($checkout->reconcileOrder((int) $paidOrder['order']['id'])['print_allowed'] === false && $repo->ticketIdForCommerceOrder((int) $paidOrder['order']['id']) === null, 'Unpaid commerce order produces no kitchen ticket.');
$paymentId = $paymentFor($paidOrder);
$created = $checkout->reconcileOrder((int) $paidOrder['order']['id']);
$assert($created['created'] && $created['print_allowed'], 'Authoritative Core payment public API reconciles paid order without browser return.');
$assert($repo->cartByCommerceOrder((int) $paidOrder['order']['id'])['status'] === 'ticketed' && $commerce->order((int) $paidOrder['order']['id'])['status'] === 'paid', 'Public Commerce and Restaurant APIs advance the real fixture state coherently.');
$jobCount = count($repo->printJobs());
$repeated = $checkout->reconcileOrder((int) $paidOrder['order']['id']);
$assert(!$repeated['created'] && count($repo->printJobs()) === $jobCount, 'Duplicate authoritative payment reconciliation creates no duplicate ticket or job.');
$storedTicket = $repo->ticket((int) $created['ticket_id']);
$print->enqueueStationJobs($storedTicket, $repo->ticketItems((int) $created['ticket_id']));
$assert(count($repo->printJobs()) === $jobCount, 'Queue fan-out is independently idempotent.');
$wrongCurrency = $prepare();
$paymentFor($wrongCurrency, 1000, 'EUR');
$assert(!$checkout->reconcileOrder((int) $wrongCurrency['order']['id'])['print_allowed'], 'Different-currency payment cannot authorize food output.');
$shortPayment = $prepare();
$paymentFor($shortPayment, 999);
$assert(!$checkout->reconcileOrder((int) $shortPayment['order']['id'])['print_allowed'], 'Underpayment cannot authorize food output.');
$cancelOrder = $prepare();
$commerce->cancelPendingOrder((int) $cancelOrder['order']['id']);
$assert($checkout->reconcileOrder((int) $cancelOrder['order']['id'])['held'] && $repo->ticketIdForCommerceOrder((int) $cancelOrder['order']['id']) === null, 'Cancelled order never creates a kitchen ticket.');
$paidJob = array_values(array_filter($repo->printJobs(), static fn (array $job): bool => (int) $job['ticket_id'] === (int) $created['ticket_id']))[0];
$payments->insertRefund(['payment_id' => $paymentId, 'provider_id' => 'test.provider', 'remote_id' => 'fixture-refund', 'status' => 'completed', 'amount_minor' => 1000, 'currency' => 'USD', 'reason' => 'LOCAL FULL REFUND TEST', 'idempotency_key' => 'fixture-refund', 'request_hash' => hash('sha256', 'fixture-refund')]);
$held = $checkout->reconcileOrder((int) $paidOrder['order']['id']);
$assert($held['held'] && $repo->printJob((int) $paidJob['id'])['status'] === 'cancelled', 'Confirmed full refund before output cancels queued kitchen printing.');
$partialOrder = $prepare();
$partialPaymentId = $paymentFor($partialOrder);
$partialTicket = $checkout->reconcileOrder((int) $partialOrder['order']['id']);
$partialLease = $repo->queuedAgentJobs('bridge-a', $credentialA)[0];
$assert($controller->bridgeStart($request(['job_id' => $partialLease['id'], 'claim_token' => $partialLease['claim_token']]))->status() === 200, 'Paid job passes final payment gate before dispatch.');
$payments->insertRefund(['payment_id' => $partialPaymentId, 'provider_id' => 'test.provider', 'remote_id' => 'fixture-partial-refund', 'status' => 'completed', 'amount_minor' => 100, 'currency' => 'USD', 'reason' => 'LOCAL PARTIAL REFUND TEST', 'idempotency_key' => 'fixture-partial-refund', 'request_hash' => hash('sha256', 'fixture-partial-refund')]);
$checkout->reconcileOrder((int) $partialOrder['order']['id']);
$assert($repo->printJob((int) $partialLease['id'])['status'] === 'needs_review', 'Refund during dispatch is uncertain; hold and notify owner, never blind retry.');
$assert($repo->queuedAgentJobs('bridge-a', $credentialA) === [], 'Refund-held job is not automatically reprinted.');
$beforeAtomic = count($repo->tickets());
$pdo->exec("CREATE TRIGGER local_print_failure BEFORE INSERT ON restaurant_print_jobs BEGIN SELECT RAISE(ABORT, 'LOCAL INTERRUPTED QUEUE TEST'); END");
$reject(fn () => $tickets->createTicketWithPrintJobs(['customer_name' => 'ATOMIC TEST', 'service_mode' => 'pickup'], [['item_name' => 'ATOMIC TEST', 'quantity' => 1, 'station_code' => 'wok']]), 'Interrupted enqueue aborts ticket creation.');
$assert(count($repo->tickets()) === $beforeAtomic, 'Kitchen ticket, items and queue are atomic; no orphan ticket after database fault.');
$pdo->exec('DROP TRIGGER local_print_failure');
$assert(count($repo->printAudits()) > 0 && str_contains($controller->printJobs()->body(), '操作审计') && str_contains($controller->printJobs()->body(), '人工接单'), 'Visible fallback and actor-aware operation audit are available.');
$assert(str_contains($controller->printers($request())->body(), '模式 A') && str_contains($controller->printers($request())->body(), '模式 B'), 'Native admin page exposes both modes and disabled future POS integration.');
$assert($controller->retryPrintJob(new Request('POST', '/', [], ['job_id' => $testA]))->status() === 400, 'HTTP manual reprint requires explicit owner acknowledgement.');
$page = $checkout->syncPaidOrders(1);
$assert($page['checked'] === 1 && $page['next_cursor'] > 0 && $checkout->syncPaidOrders(1, $page['next_cursor'])['next_cursor'] > $page['next_cursor'], 'Bounded reconciliation cursor prevents early unpaid orders starving later paid orders.');
echo "official_restaurant_print_bridge: PASS ($assertions assertions; isolated software fixtures only; no physical printer, gateway or POS access; fixture_only=" . (getenv('RESTAURANT_PRINT_FIXTURES_ONLY') === '1' ? 'yes, checkout not tested' : 'no') . ")\n";
$submittedTest = $tickets->createPrinterTestTicket($deviceA, 'local-test');
$submittedLease = $repo->queuedAgentJobs('bridge-a', $credentialA)[0];
$assert((int) $submittedLease['id'] === $submittedTest && $controller->bridgeStart($request(['job_id' => $submittedTest, 'claim_token' => $submittedLease['claim_token']]))->status() === 200, 'TEST ticket obtains a single dispatch lease.');
$receipt = ['job_id' => $submittedTest, 'claim_token' => $submittedLease['claim_token'], 'status' => 'submitted', 'error' => 'PRINT_QUEUE_ACCEPTED_OUTPUT_NOT_VERIFIED'];
$assert($controller->bridgeReport($request($receipt))->status() === 200 && $controller->bridgeReport($request($receipt))->status() === 200, 'Accepted spool receipt is idempotent submitted, without mandatory owner review.');
$submittedJob = $repo->printJob($submittedTest);
$assert($submittedJob['status'] === 'submitted' && $submittedJob['printed_at'] === null && $repo->queuedAgentJobs('bridge-a', $credentialA) === [], 'Submitted is not physical printed and is never automatically replayed.');
$repo->holdTicketPrintJobs((int) $submittedJob['ticket_id'], 'LOCAL CANCELLATION TEST');
$assert($repo->printJob($submittedTest)['status'] === 'needs_review', 'Cancellation after spool acceptance requires kitchen notification.');
echo "submitted_receipt_contract: PASS ($assertions total assertions; software only)\n";
