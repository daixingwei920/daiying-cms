<?php
declare(strict_types=1);

define('CMS_ROOT', getenv('CMS_CARD_DELIVERY_TEST_ROOT') ?: dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\CardDelivery\CardDeliveryException;
use Cms\Core\CardDelivery\CardDeliveryRepository;

$dsn = getenv('CMS_CARD_DELIVERY_TEST_DSN') ?: 'sqlite::memory:';
if ($dsn !== 'sqlite::memory:' && !preg_match('/^mysql:host=127\.0\.0\.1;port=33307;dbname=cms_test_[a-z0-9_]+;charset=utf8mb4$/D', $dsn)) {
    throw new RuntimeException('Use only an explicit isolated cms_test_ database on the private test port.');
}
$db = new PDO($dsn, getenv('CMS_CARD_DELIVERY_TEST_USER') ?: null, getenv('CMS_CARD_DELIVERY_TEST_PASSWORD') ?: null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    ++$checks;
    echo 'PASS ' . $label . PHP_EOL;
};
$mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
$check(!$mysql || !$db->getAttribute(PDO::ATTR_EMULATE_PREPARES), 'MySQL native prepares remain enabled');
// Temporary table shadows any persistent name only for this isolated connection.
$db->exec('CREATE ' . ($mysql ? 'TEMPORARY ' : '') . 'TABLE cms_card_orders (id INTEGER PRIMARY KEY, payment_id INTEGER NULL, updated_at VARCHAR(64) NOT NULL)');
$db->exec("INSERT INTO cms_card_orders VALUES (1, NULL, 'before'), (2, 202, 'protected')");
$repo = new CardDeliveryRepository($db);
$payment = static fn(int $id): array => $db->query('SELECT payment_id, updated_at FROM cms_card_orders WHERE id=' . $id)->fetch();
$repo->attachPayment(1, 101);
$check((int) $payment(1)['payment_id'] === 101, 'Attach payment to previously unbound order');
$repo->attachPayment(1, 101);
$check((int) $payment(1)['payment_id'] === 101, 'Same-payment retry remains idempotent');
$before = $payment(2);
$repo->attachPayment(2, 303);
$check($payment(2) === $before, 'Different payment cannot replace existing order binding or timestamp');
$db->beginTransaction();
$repo->attachPayment(2, 202);
$check($db->inTransaction(), 'Repository does not commit the caller transaction');
$db->rollBack();
$check($payment(2) === $before, 'Caller rollback restores all original order fields');
foreach ([[0, 101], [1, 0], [-1, 101], [1, -1]] as [$orderId, $paymentId]) {
    try { $repo->attachPayment($orderId, $paymentId); throw new LogicException('Invalid IDs accepted'); }
    catch (CardDeliveryException) { $check(true, 'Reject invalid order/payment ID ' . $orderId . '/' . $paymentId); }
}
$check((int) $db->query('SELECT COUNT(*) FROM cms_card_orders')->fetchColumn() === 2, 'No additional order rows created');
echo 'TOTAL ' . $checks . ' PASS — ' . $db->getAttribute(PDO::ATTR_DRIVER_NAME) . PHP_EOL;
