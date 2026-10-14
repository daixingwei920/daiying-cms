<?php

declare(strict_types=1);

require dirname(__DIR__) . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Advertising\AdRepository;
use Cms\Core\Payment\FixturePaymentProvider;
use Cms\Core\Payment\PaymentRepository;
use Cms\Core\Payment\PaymentService;
use Cms\Core\Payment\PaymentProviderRegistry;
use Cms\Core\Payment\PaymentProviderInterface;
use Cms\Core\Payment\PaymentResult;
use Cms\Core\Payment\PaymentProviderSettingsRepository;
use Cms\Core\Plugin\PluginAdvertisingService;
use Cms\Core\Plugin\PluginContext;
use Cms\Core\Plugin\PluginDataStore;
use Cms\Core\Plugin\PluginRuntimeRegistry;
use Cms\Core\Plugin\BlockRegistry;
use Cms\Core\Events\EventDispatcher;
use Cms\Core\Plugin\PluginException;
use Cms\Core\Plugin\PluginLifecycle;
use Cms\Core\Plugin\PluginManifest;
use Cms\Core\Plugin\PluginPaymentRequestScope;
use Cms\Core\Plugin\PluginPaymentService;
use Cms\Core\Plugin\PluginPermissionDecisionService;

$checks = 0;
function verify(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
    echo '[PASS] ' . $message . PHP_EOL;
}

function denied(callable $call, string $code): void
{
    try {
        $call();
    } catch (PluginException $exception) {
        verify($exception->getMessage() === $code, 'Denied with ' . $code);
        return;
    }
    throw new RuntimeException('Expected ' . $code);
}

function scoped(string $plugin, string $method, bool $csrf, ?int $buyer, callable $call): mixed
{
    $property = new ReflectionProperty(PluginPaymentRequestScope::class, 'current');
    $previous = $property->getValue();
    $property->setValue(null, ['plugin_id' => $plugin, 'method' => $method, 'csrf' => $csrf, 'actor_id' => $buyer]);
    try {
        return $call();
    } finally {
        $property->setValue(null, $previous);
    }
}

function testManifest(string $root, PluginManifest $manifest): void
{
    $dir = $root . '/' . $manifest->id;
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    file_put_contents($dir . '/plugin.json', json_encode([
        'plugin_id' => $manifest->id, 'name' => $manifest->name,
        'version' => $manifest->version, 'author' => $manifest->author,
        'core' => ['min' => $manifest->coreMin], 'php' => $manifest->phpMin,
        'entry' => $manifest->entry, 'trust_level' => $manifest->trustLevel,
        'capabilities' => $manifest->capabilities, 'permissions' => $manifest->permissions,
    ], JSON_THROW_ON_ERROR));
}

$fixtureRoot = sys_get_temp_dir() . '/daiying-plugin-payment-fixture-' . getmypid();
mkdir($fixtureRoot, 0700, true);
register_shutdown_function(static function () use ($fixtureRoot): void {
    foreach (glob($fixtureRoot . '/*/plugin.json') ?: [] as $file) {
        unlink($file);
        rmdir(dirname($file));
    }
    rmdir($fixtureRoot);
});

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
foreach ([
    '2026_08_12_000003_plugin_schema.php',
    '2026_08_28_000001_advertising_schema.php',
    '2026_08_20_000001_core_payment_schema.php',
    '2026_08_29_000001_members_comments_schema.php',
    '2026_08_29_000004_differentiation_platform_schema.php',
    '2026_10_14_000001_plugin_payment_bindings.php',
    '2026_10_14_000002_plugin_permission_scope.php',
] as $file) {
    (require dirname(__DIR__) . '/system/migrations/' . $file)->up($pdo);
}
(require dirname(__DIR__) . '/system/migrations/2026_10_14_000001_plugin_payment_bindings.php')->up($pdo);
verify((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'cms_plugin_payment_bindings'")->fetchColumn() === 1, 'P14 binding migration is repeatable');
$pdo->exec('CREATE TABLE cms_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_type TEXT, actor_id INTEGER, action TEXT, context_json TEXT, created_at TEXT)');
$pdo->exec("INSERT INTO cms_front_users (id,email,password_hash,display_name,status,created_at,updated_at) VALUES (1,'buyer@example.invalid','x','Buyer','active','2026-10-14','2026-10-14')");
$pdo->exec("INSERT INTO cms_front_users (id,email,password_hash,display_name,status,created_at,updated_at) VALUES (2,'other@example.invalid','x','Other','active','2026-10-14','2026-10-14')");

$manifest = new PluginManifest('local.sample', 'Sample', '1.0.0', 'Unit', '1.2.84', '8.3.0', 'plugin.php', 'api',
    ['ads.read', 'payment.subject.register', 'payment.checkout', 'payment.entitlement.read'], ['payment.access'], [], 'plugin', false, [], [], '');
$pdo->prepare('INSERT INTO cms_plugins (plugin_id,name,version,author,status,trust_level,capabilities_json,installed_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
    ->execute([$manifest->id, 'Sample', '1.0.0', 'Unit', PluginLifecycle::ENABLED, 'api', json_encode($manifest->capabilities), '2026-10-14', '2026-10-14']);
testManifest($fixtureRoot, $manifest);
(new PluginPermissionDecisionService($pdo, $fixtureRoot))->grant($manifest->id, '1.0.0', 1);
$pdo->prepare('INSERT INTO cms_ad_slots (slot_key,label,placement,html,enabled,created_at,updated_at) VALUES (?,?,?,?,?,?,?)')
    ->execute(['header', 'Header', 'home', '<a href="https://example.org">Go</a><script>alert(1)</script>', 1, '2026-10-14', '2026-10-14']);
$ads = new PluginAdvertisingService($manifest, new AdRepository($pdo));
$runtime = new PluginRuntimeRegistry();
$pluginContext = new PluginContext($manifest, new EventDispatcher(), new BlockRegistry(), new PluginDataStore($pdo, $manifest->id), null,
    $runtime, advertising: $ads);
$pluginContext->frontRoute('GET', '/test/ads', static fn (): array => $pluginContext->advertising()->renderSlots(['header']));
verify(count($runtime->routes()) === 1 && isset(($runtime->routes()[0]->handler)()['header']), 'A01 ordinary plugin route reads advertisements without PDO');
try {
    $pluginContext->pdo();
    throw new RuntimeException('API plugin unexpectedly received PDO');
} catch (PluginException $exception) {
    verify(str_contains($exception->getMessage(), 'Raw database access'), 'A01 ordinary plugin has no PDO');
}
$rendered = $ads->renderSlots([' HEADER ', 'header', 'unknown']);
verify(count($rendered) === 1 && isset($rendered['header']), 'A02 normalize and deduplicate requested enabled slots');
verify(substr_count($rendered['header']['html'], '/impression') === 1 && substr_count($rendered['header']['html'], '/click?') === 1, 'A05 single impression and click wrapper');
verify(!str_contains($rendered['header']['html'], '<script'), 'A04 existing sanitizer removes script');
verify($ads->renderSlots([]) === [], 'A02 empty input reads no slots');
denied(static fn () => $ads->renderSlots(['../secret']), 'invalid_request');
denied(static fn () => (new PluginAdvertisingService(new PluginManifest('local.no-ads', 'No Ads', '1.0.0', 'Unit', '1.2.84', '8.3.0', 'plugin.php', 'api', [], [], [], 'plugin', false, [], [], ''), new AdRepository($pdo)))->renderSlots(['header']), 'capability_denied');
denied(static fn () => (new PluginAdvertisingService($manifest, null))->renderSlots(['header']), 'service_unavailable');
$pdo->exec("UPDATE cms_ad_slots SET label = 'New Header' WHERE slot_key = 'header'");
verify($ads->renderSlots(['header'])['header']['label'] === 'New Header', 'A06 slot updates appear on next request');
$pdo->exec("UPDATE cms_ad_slots SET enabled = 0 WHERE slot_key = 'header'");
verify($ads->renderSlots(['header']) === [], 'A06 disabled slot disappears on next request');
$pdo->exec('DROP TABLE cms_ad_slots');
denied(static fn () => $ads->renderSlots(['header']), 'service_unavailable');

PaymentProviderRegistry::register(FixturePaymentProvider::PROVIDER_ID, new FixturePaymentProvider());
(new PaymentProviderSettingsRepository($pdo, ''))->save(FixturePaymentProvider::PROVIDER_ID, 'Fixture', 'enabled');
$facade = new PluginPaymentService($manifest, $pdo);
$ungranted = new PluginManifest('local.ungranted', 'Ungranted', '1.0.0', 'Unit', '1.2.84', '8.3.0', 'plugin.php', 'api',
    ['payment.checkout'], ['payment.access'], [], 'plugin', false, [], [], '');
$pdo->prepare('INSERT INTO cms_plugins (plugin_id,name,version,author,status,trust_level,capabilities_json,installed_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
    ->execute([$ungranted->id, 'Ungranted', '1.0.0', 'Unit', PluginLifecycle::ENABLED, 'api', json_encode($ungranted->capabilities), '2026-10-14', '2026-10-14']);
denied(static fn () => (new PluginPaymentService($ungranted, $pdo))->enabledProviders('USD'), 'capability_denied');
denied(static fn () => scoped('local.sample', 'POST', true, null,
    static fn () => $facade->create('recommendation', 'order-1', FixturePaymentProvider::PROVIDER_ID, 'key-1')), 'capability_denied');
$quoteVersion = 'v1';
$beneficiaryId = 'merchant-1';
$durationSeconds = 3600;
$facade->registerSubject('recommendation', static fn (string $order, array $context): array => [
    'authorized' => $context['operation'] === 'entitlement_read' || $context['actor_id'] === 1,
    'quote_version' => $GLOBALS['quoteVersion'], 'amount_minor' => 100, 'currency' => 'USD',
    'beneficiary' => ['type' => 'merchant', 'id' => $GLOBALS['beneficiaryId']], 'duration_seconds' => $GLOBALS['durationSeconds'],
]);
$GLOBALS['quoteVersion'] = &$quoteVersion;
$GLOBALS['beneficiaryId'] = &$beneficiaryId;
$GLOBALS['durationSeconds'] = &$durationSeconds;
$purchase = static fn (string $order, string $key): array => scoped('local.sample', 'POST', true, 1,
    static fn (): array => $facade->create('recommendation', $order, FixturePaymentProvider::PROVIDER_ID, $key));
$first = $purchase('order-1', 'key-1');
verify($first['status'] === 'paid', 'P01 fixture Provider creates trusted payment through PaymentService');
verify($purchase('order-1', 'key-1')['payment_ref'] === $first['payment_ref'], 'P04 same request returns same reference');
denied(static fn () => scoped('local.sample', 'POST', true, 2,
    static fn () => $facade->status($first['payment_ref'])), 'not_found');
denied(static fn () => scoped('local.sample', 'POST', false, 1,
    static fn () => $facade->fulfill($first['payment_ref'])), 'capability_denied');
$otherManifest = new PluginManifest('local.second', 'Second', '1.0.0', 'Unit', '1.2.84', '8.3.0', 'plugin.php', 'api',
    ['payment.subject.register', 'payment.checkout', 'payment.entitlement.read'], ['payment.access'], [], 'plugin', false, [], [], '');
$pdo->prepare('INSERT INTO cms_plugins (plugin_id,name,version,author,status,trust_level,capabilities_json,installed_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
    ->execute([$otherManifest->id, 'Second', '1.0.0', 'Unit', PluginLifecycle::ENABLED, 'api', json_encode($otherManifest->capabilities), '2026-10-14', '2026-10-14']);
testManifest($fixtureRoot, $otherManifest);
(new PluginPermissionDecisionService($pdo, $fixtureRoot))->grant($otherManifest->id, '1.0.0', 1);
$second = new PluginPaymentService($otherManifest, $pdo);
$second->registerSubject('recommendation', static fn (string $order, array $context): array => [
    'authorized' => $context['operation'] === 'entitlement_read' || $context['actor_id'] === 1,
    'quote_version' => 'v1', 'amount_minor' => 100, 'currency' => 'USD',
    'beneficiary' => ['type' => 'merchant', 'id' => 'merchant-1'], 'duration_seconds' => 3600,
]);
$secondPurchase = scoped('local.second', 'POST', true, 1,
    static fn () => $second->create('recommendation', 'order-1', FixturePaymentProvider::PROVIDER_ID, 'key-1'));
verify($secondPurchase['payment_ref'] !== $first['payment_ref'], 'P04 two plugins reuse external key without sharing payment');
denied(static fn () => scoped('local.second', 'GET', false, 1,
    static fn () => $second->status($first['payment_ref'])), 'not_found');
denied(static fn () => $purchase('order-1', 'other-key'), 'idempotency_conflict');
$quoteVersion = 'v2';
denied(static fn () => $purchase('order-1', 'key-1'), 'idempotency_conflict');
$quoteVersion = 'v1';
$beneficiaryId = 'merchant-2';
denied(static fn () => $purchase('order-1', 'key-1'), 'idempotency_conflict');
$beneficiaryId = 'merchant-1';
$durationSeconds = 7200;
denied(static fn () => $purchase('order-1', 'key-1'), 'idempotency_conflict');
$durationSeconds = 3600;
$fulfill = static fn (string $ref): array => scoped('local.sample', 'POST', true, 1,
    static fn (): array => $facade->fulfill($ref));
$entitlement = $fulfill($first['payment_ref']);
verify($entitlement['status'] === 'active' && $facade->isEntitled('recommendation', 'order-1'), 'P01 facade grants and reads active entitlement');
verify($fulfill($first['payment_ref']) === $entitlement, 'P08 repeated fulfill retains single entitlement');
verify((int) $pdo->query('SELECT COUNT(*) FROM cms_payment_entitlements')->fetchColumn() === 1, 'P08 binding has one entitlement row');
$binding = $pdo->query("SELECT * FROM cms_plugin_payment_bindings WHERE local_subject_id = 'order-1'")->fetch(PDO::FETCH_ASSOC);
$originalPaidAt = $binding['trusted_paid_at'];
$originalExpiry = $binding['expires_at'];
$repo = new PaymentRepository($pdo);
$repo->updatePaymentStatus((int) $binding['payment_id'], 'paid');
verify($pdo->query("SELECT trusted_paid_at FROM cms_plugin_payment_bindings WHERE local_subject_id = 'order-1'")->fetchColumn() === $originalPaidAt, 'P11 repeated paid status does not reset trusted first paid time');
$paymentService = new PaymentService($pdo, $repo);
$paymentService->refundProviderPayment((int) $binding['payment_id'], 40, 'test partial', 'refund-partial-1');
verify($facade->isEntitled('recommendation', 'order-1'), 'P09 partial refund after grant retains entitlement');
verify($fulfill($first['payment_ref'])['expires_at'] === $originalExpiry, 'P11 partial refund does not change expiry');
$paymentService->refundProviderPayment((int) $binding['payment_id'], 60, 'test full', 'refund-full-1');
verify(!$facade->isEntitled('recommendation', 'order-1'), 'P09 full refund revokes entitlement on next query');
$replayed = $fulfill($first['payment_ref']);
verify($replayed['status'] === 'inactive' && $replayed['entitlement_ref'] === $entitlement['entitlement_ref'] && $replayed['expires_at'] === $originalExpiry,
    'P10 replay after full refund retains original inactive reference and term');

$other = $purchase('order-2', 'key-2');
$otherBinding = $pdo->query("SELECT * FROM cms_plugin_payment_bindings WHERE local_subject_id = 'order-2'")->fetch(PDO::FETCH_ASSOC);
$pdo->prepare('UPDATE cms_plugin_payment_bindings SET payment_id = NULL, trusted_paid_at = NULL, state = :state WHERE id = :id')
    ->execute([':state' => 'creating', ':id' => $otherBinding['id']]);
$repo->updatePaymentStatus((int) $otherBinding['payment_id'], 'paid');
verify(is_string($pdo->query("SELECT trusted_paid_at FROM cms_plugin_payment_bindings WHERE local_subject_id = 'order-2'")->fetchColumn()),
    'P11 trusted paid timestamp survives callback before binding payment id');
verify($purchase('order-2', 'key-2')['payment_ref'] === $other['payment_ref']
    && (int) $pdo->query("SELECT COUNT(*) FROM cms_payments WHERE subject_type = 'cms_plugin_order'")->fetchColumn() === 3,
    'P06 recover local payment with same key after response/link failure without new provider charge');
$acceptedCalls = 0;
$acceptedKeys = [];
$lostResponseProvider = new class($acceptedCalls, $acceptedKeys) implements PaymentProviderInterface {
    public function __construct(private int &$calls, private array &$keys) {}
    public function providerId(): string { return FixturePaymentProvider::PROVIDER_ID; }
    public function displayName(): string { return 'Lost response fixture'; }
    public function capabilities(): array { return ['payment.create']; }
    public function createPayment(object $command): PaymentResult
    {
        $this->calls++;
        $this->keys[] = (string) ($command->idempotency_key ?? '');
        throw new RuntimeException('Simulated lost response after Provider acceptance');
    }
    public function capturePayment(object $command): PaymentResult { throw new LogicException('Not used'); }
    public function cancelPayment(object $command): PaymentResult { throw new LogicException('Not used'); }
    public function refundPayment(object $command): PaymentResult { throw new LogicException('Not used'); }
    public function getPaymentStatus(object $command): PaymentResult { throw new LogicException('Not used'); }
};
PaymentProviderRegistry::register(FixturePaymentProvider::PROVIDER_ID, $lostResponseProvider);
denied(static fn () => $purchase('order-uncertain', 'key-uncertain'), 'payment_reconciliation_required');
$uncertain = $pdo->query("SELECT state, internal_key, payment_id FROM cms_plugin_payment_bindings WHERE local_subject_id = 'order-uncertain'")->fetch(PDO::FETCH_ASSOC);
verify($acceptedCalls === 1 && is_array($uncertain) && $uncertain['state'] === 'reconciliation_required'
    && $uncertain['payment_id'] === null && $acceptedKeys === [$uncertain['internal_key']],
    'P06 lost Provider response retains one durable key and requires reconciliation');
denied(static fn () => $purchase('order-uncertain', 'key-uncertain'), 'payment_reconciliation_required');
verify($acceptedCalls === 1 && $pdo->query("SELECT internal_key FROM cms_plugin_payment_bindings WHERE local_subject_id = 'order-uncertain'")->fetchColumn() === $uncertain['internal_key'],
    'P06 retry after ambiguous acceptance does not issue a second Provider create');
PaymentProviderRegistry::register(FixturePaymentProvider::PROVIDER_ID, new FixturePaymentProvider());
$pdo->exec("CREATE TRIGGER reject_plugin_entitlement BEFORE INSERT ON cms_payment_entitlements BEGIN SELECT RAISE(ABORT, 'injected failure'); END");
denied(static fn () => $fulfill($other['payment_ref']), 'service_unavailable');
verify((int) $pdo->query('SELECT COUNT(*) FROM cms_payment_entitlements')->fetchColumn() === 1
    && $pdo->query("SELECT entitlement_id FROM cms_plugin_payment_bindings WHERE local_subject_id = 'order-2'")->fetchColumn() === null,
    'P08 failed grant rolls back without half entitlement');
$pdo->exec('DROP TRIGGER reject_plugin_entitlement');
verify($fulfill($other['payment_ref'])['status'] === 'active', 'P08 retry after storage failure grants once');

$expired = $purchase('order-3', 'key-3');
$pdo->exec("UPDATE cms_plugin_payment_bindings SET trusted_paid_at = '2020-01-01T00:00:00+00:00' WHERE local_subject_id = 'order-3'");
denied(static fn () => $fulfill($expired['payment_ref']), 'payment_not_fulfillable');
verify($pdo->query("SELECT entitlement_id FROM cms_plugin_payment_bindings WHERE local_subject_id = 'order-3'")->fetchColumn() === null,
    'P12 expired first fulfillment grants no entitlement');

$bindingCountBeforeDisable = (int) $pdo->query('SELECT COUNT(*) FROM cms_plugin_payment_bindings')->fetchColumn();
$pdo->exec("UPDATE cms_plugins SET status = 'Disabled' WHERE plugin_id = 'local.sample'");
denied(static fn () => $facade->isEntitled('recommendation', 'order-2'), 'capability_denied');
verify((int) $pdo->query('SELECT COUNT(*) FROM cms_plugin_payment_bindings')->fetchColumn() === $bindingCountBeforeDisable, 'P13 disable does not delete payment bindings');
$pdo->exec("UPDATE cms_plugins SET status = 'Enabled' WHERE plugin_id = 'local.sample'");
verify($facade->isEntitled('recommendation', 'order-2'), 'P13 re-enable reads existing entitlement');
denied(static fn () => $facade->create('recommendation', 'order-2', FixturePaymentProvider::PROVIDER_ID, 'key-2'), 'capability_denied');
denied(static fn () => scoped('local.sample', 'POST', false, 1,
    static fn () => $facade->create('recommendation', 'order-2', FixturePaymentProvider::PROVIDER_ID, 'key-2')), 'capability_denied');
denied(static fn () => PluginPaymentRequestScope::run('local.sample', 'POST', true, 1, static fn () => null), 'capability_denied');
verify($facade->isEntitled('recommendation', 'missing') === false, 'P03 absent order has no entitlement');
echo "Plugin advertising/payment contract: {$checks} checks passed.\n";
