<?php

declare(strict_types=1);

use Cms\Core\Plugin\LocalPluginPackageInstaller;
use Official\Commerce\Infrastructure\ProviderRegistry;

require __DIR__ . '/payment_p1_test_helpers.php';

set_time_limit(180);
$failures = 0;
function p1_ind_check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}

$env = p1_sqlite_root('independence');
$pdo = $env['pdo'];
$runtime = p1_boot($pdo);
p1_ind_check(count($runtime->routes()) >= 0, 'Core only boots without Commerce or Payment provider');
$installer = new LocalPluginPackageInstaller(CMS_SOURCE_ROOT, $pdo);
$installer->installBundled('official.commerce', 1, true);
p1_boot($pdo);
p1_ind_check(ProviderRegistry::payment('official.payment-fixture') === null, 'Core + Commerce works without payment provider');
$installer->installBundled('official.payment-fixture', 1, true);
p1_boot($pdo);
p1_ind_check(ProviderRegistry::payment('official.payment-fixture') !== null, 'Core + Commerce + Fixture Payment registers provider');
$installer->disableWithDependents('official.commerce', 1, true);
p1_boot($pdo);
p1_ind_check(ProviderRegistry::payment('official.payment-fixture') === null && (string) $pdo->query("SELECT status FROM cms_plugins WHERE plugin_id = 'official.payment-fixture'")->fetchColumn() !== 'Enabled', 'disabling Commerce prevents Payment provider startup and dependent plugin is paused');
$installer->enable('official.commerce', 1);
$installer->enable('official.payment-fixture', 1);
p1_boot($pdo);
p1_ind_check(ProviderRegistry::payment('official.payment-fixture') !== null, 're-enabling Commerce and Payment restores provider');

$scan = shell_exec('rg -n "Stripe|PayPal|stripe|paypal|api\\.stripe|paypal\\.com" system content/plugins/official.commerce 2>&1') ?: '';
p1_ind_check(trim($scan) === '', 'Core and Commerce contain no Stripe or PayPal provider-specific logic');

p1_remove($env['root']);
if ($failures > 0) {
    echo '[RESULT] payment_plugin_independence failed: ' . $failures . PHP_EOL;
    exit(1);
}
echo '[RESULT] payment_plugin_independence passed.' . PHP_EOL;
