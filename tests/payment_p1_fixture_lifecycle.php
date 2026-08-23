<?php

declare(strict_types=1);

use Cms\Core\Plugin\LocalPluginPackageInstaller;
use Cms\Core\Plugin\PluginLifecycle;
use Official\Commerce\Infrastructure\ProviderRegistry;

require __DIR__ . '/payment_p1_test_helpers.php';

set_time_limit(180);
$failures = 0;
function p1_lifecycle_check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}
function p1_lifecycle_throws(callable $callback, string $message): void
{
    try {
        $callback();
        p1_lifecycle_check(false, $message);
    } catch (Throwable) {
        p1_lifecycle_check(true, $message);
    }
}

$env = p1_sqlite_root('lifecycle');
$pdo = $env['pdo'];
$installer = new LocalPluginPackageInstaller($env['root'], $pdo);
p1_lifecycle_throws(static fn () => $installer->installBundled('official.payment-fixture', 1, true), 'Payment Fixture cannot install/enable when Commerce is missing');
$installer->installBundled('official.commerce', 1, true);
$installer->installBundled('official.payment-fixture', 1, true);
p1_boot($pdo);
p1_lifecycle_check(ProviderRegistry::payment('official.payment-fixture') !== null, 'enabled Payment Fixture registers provider');
$installer->disableWithDependents('official.payment-fixture', 1, true);
p1_boot($pdo);
p1_lifecycle_check(ProviderRegistry::payment('official.payment-fixture') === null, 'disabled Payment Fixture no longer registers provider');
$services = p1_services($pdo);
$order = p1_pending_order($pdo, 'LIFE');
p1_lifecycle_throws(static fn () => $services['payment']->createProviderPayment((int) $order['order']['id'], 'official.payment-fixture', (int) $order['total'], 'USD', 'success', 'p1-life-pay-disabled', 1), 'Commerce refuses provider payment while Payment Fixture is disabled');
$installer->enable('official.payment-fixture', 1);
p1_boot($pdo);
$payment = $services['payment']->createProviderPayment((int) $order['order']['id'], 'official.payment-fixture', (int) $order['total'], 'USD', 'success', 'p1-life-pay', 1);
p1_lifecycle_check((int) $payment['id'] > 0, 're-enabled Payment Fixture restores provider processing');
$installer->uninstallCode('official.payment-fixture', 1);
p1_lifecycle_check((int) $pdo->query('SELECT COUNT(*) FROM cms_commerce_payments')->fetchColumn() === 1 && (string) $pdo->query("SELECT status FROM cms_plugins WHERE plugin_id = 'official.payment-fixture'")->fetchColumn() === PluginLifecycle::DORMANT, 'code uninstall retains Commerce payment data and records plugin as dormant');
p1_copy_dir(CMS_SOURCE_ROOT . '/content/plugins/official.payment-fixture', $env['root'] . '/content/plugins/official.payment-fixture');
$installer->installBundled('official.payment-fixture', 1, true);
p1_boot($pdo);
p1_lifecycle_check(ProviderRegistry::payment('official.payment-fixture') !== null && (int) $pdo->query('SELECT COUNT(*) FROM cms_commerce_payments')->fetchColumn() === 1, 'reinstall recognizes retained payment data');

p1_remove($env['root']);
if ($failures > 0) {
    echo '[RESULT] payment_p1_fixture_lifecycle failed: ' . $failures . PHP_EOL;
    exit(1);
}
echo '[RESULT] payment_p1_fixture_lifecycle passed.' . PHP_EOL;
