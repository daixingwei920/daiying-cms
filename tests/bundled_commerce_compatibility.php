<?php
declare(strict_types=1);
$fixture = sys_get_temp_dir() . '/cms-bundled-compat-' . bin2hex(random_bytes(5));
mkdir($fixture . '/system', 0700, true);
copy(dirname(__DIR__) . '/system/official-plugins.php', $fixture . '/system/official-plugins.php');
define('CMS_ROOT', $fixture);
register_shutdown_function(static function () use ($fixture): void {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($fixture);
});
require dirname(__DIR__) . '/system/core/Bootstrap/autoload.php';
require dirname(__DIR__) . '/content/plugins/official.commerce/src/CommerceContracts.php';
require dirname(__DIR__) . '/content/plugins/official.commerce/src/CommerceRepository.php';
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Payment\PaymentRepository;
use Daiying\Commerce\CommerceRepository;

$source = dirname(__DIR__);
$dsn = 'sqlite::memory:'; $user = $password = null;
if ($file = getenv('CMS_MYSQL_TEST_CONFIG')) {
    $c = require $file;
    if (($c['host'] ?? '') !== '127.0.0.1' || !preg_match('/^cms_mysql_[a-z0-9_]+$/D', $c['database'] ?? '')) { throw new RuntimeException('Disposable loopback MySQL required'); }
    $dsn = 'mysql:host=127.0.0.1;port=' . (int) ($c['port'] ?? 3306) . ';dbname=' . $c['database'] . ';charset=utf8mb4';
    $user = $c['user']; $password = $c['password'];
}
$p = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$files = glob($source . '/system/migrations/*.php'); sort($files, SORT_STRING);
(new MigrationRunner($p, array_map(static fn ($f) => require $f, $files)))->run();
foreach (glob($source . '/content/plugins/official.commerce/migrations/*.php') as $file) { (require $file)['up']($p); }
$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException($label); } $checks++; echo 'PASS ' . $label . PHP_EOL; }
function rejects(callable $call): bool { try { $call(); return false; } catch (RuntimeException|InvalidArgumentException) { return true; } }
$repo = new CommerceRepository($p, 'synthetic-compatibility-only');
$product = $repo->saveProduct(['name' => 'Synthetic bundled compatibility', 'sku' => 'BUNDLED-COMPAT', 'status' => 'active', 'price_minor' => 1200, 'currency' => 'CNY', 'stock_quantity' => 10]);
$action = $repo->saveAction(['product_id' => $product, 'action_type' => 'site_checkout', 'label' => 'Synthetic', 'status' => 'active']);
$order = $repo->createPendingOrder($product, null, $action, 1, 'fixture.no-network', 'bundled-compatibility', 'initial');
$id = (int) $order['id']; $claim = hash('sha256', 'synthetic claim');
$repo->updatePendingOrderClaim($id, $claim);
check($repo->order($id)['completion_claim'] === $claim, 'Pending order accepts its completion claim');
$repo->updatePendingOrderClaim($id, $claim); $repo->updatePendingOrderClaim($id, $claim);
check($repo->order($id)['completion_claim'] === $claim, 'Identical same-second retries are idempotent with native prepared statements');
foreach (['', str_repeat('x', 129)] as $bad) { check(rejects(static fn () => $repo->updatePendingOrderClaim($id, $bad)), 'Invalid or oversized claim is rejected before persistence'); }
check(rejects(static fn () => $repo->updatePendingOrderClaim($id + 999, $claim)), 'Missing order cannot acquire a claim');
foreach (['paid', 'fulfilled', 'cancelled', 'payment_failed'] as $status) {
    $p->prepare('UPDATE commerce_orders SET status=? WHERE id=?')->execute([$status, $id]);
    check(rejects(static fn () => $repo->updatePendingOrderClaim($id, 'replacement')) && $repo->order($id)['completion_claim'] === $claim, 'Terminal order claim remains immutable: ' . $status);
}
$p->prepare("UPDATE commerce_orders SET status='paid' WHERE id=?")->execute([$id]);
check($repo->orderPaymentState($id, 'CNY')['status'] === 'unpaid', 'Commerce paid lifecycle alone is not trusted payment evidence');
$payments = new PaymentRepository($p);
$payments->insertPayment(['subject_type' => 'commerce_order', 'subject_id' => 'order:' . $id, 'provider_id' => 'fixture.no-network', 'remote_id' => 'synthetic-payment-only', 'reference' => 'synthetic-compatibility', 'status' => 'paid', 'amount_minor' => 1200, 'currency' => 'CNY', 'idempotency_key' => 'synthetic-compatibility-paid', 'request_hash' => hash('sha256', 'synthetic')]);
$paid = $repo->orderPaymentState($id, 'CNY');
check($paid['status'] === 'paid' && $paid['net_paid_minor'] === 1200, 'Payment state comes from the matching Core ledger subject and currency');
check($repo->orderPaymentState($id, 'USD')['net_paid_minor'] === 0 && $repo->orderPaymentState($id + 1, 'CNY')['net_paid_minor'] === 0, 'Wrong currency or order cannot reuse another payment');
echo 'Bundled Commerce compatibility PASS: ' . $checks . ' checks; ' . $p->getAttribute(PDO::ATTR_DRIVER_NAME) . '; no provider/network calls' . PHP_EOL;
