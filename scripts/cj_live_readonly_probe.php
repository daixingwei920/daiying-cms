<?php

declare(strict_types=1);

use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Plugin\PluginSecretStore;
use Official\CjDropshipping\Api\CjCircuitBreaker;
use Official\CjDropshipping\Api\CjEndpointRegistry;
use Official\CjDropshipping\Api\CjErrorMapper;
use Official\CjDropshipping\Api\CjHttpClient;
use Official\CjDropshipping\Api\CjLiveTestGate;
use Official\CjDropshipping\Api\CjPointsBudget;
use Official\CjDropshipping\Api\CjPointsParser;
use Official\CjDropshipping\Api\CjProductionGate;
use Official\CjDropshipping\Api\CjRateLimiter;
use Official\CjDropshipping\Api\CjRealHttpTransport;
use Official\CjDropshipping\Api\CjRetryPolicy;
use Official\CjDropshipping\Auth\CjTokenManager;
use Official\CjDropshipping\Production\CjProductionReadOnlyProbe;
use Official\CjDropshipping\Repository\CjRepository;
use Official\CjDropshipping\Support\CjRedactor;

$options = getopt('', ['root::', 'query::', 'pid::', 'vid::', 'country::', 'state::', 'postal::', 'quantity::']);
$root = realpath((string) ($options['root'] ?? dirname(__DIR__)));
if ($root === false || !is_file($root . '/config/app.php')) {
    fwrite(STDERR, "CMS 根目录无效。\n");
    exit(2);
}

require $root . '/system/core/Bootstrap/autoload.php';
require $root . '/content/plugins/official.cj-dropshipping/plugin.php';

try {
    CjLiveTestGate::assertReadOnlyAllowed();
    CjProductionGate::assertRealTransportEnabled();

    $settings = Settings::load($root);
    $pdo = ConnectionFactory::make($settings);
    $repository = new CjRepository($pdo);
    $redactor = new CjRedactor();
    $tokens = new CjTokenManager(new PluginSecretStore($pdo, (string) $settings->get('security.encryption_key', '')), $repository, $redactor);
    $registry = new CjEndpointRegistry();
    $client = new CjHttpClient(
        $registry,
        new CjRealHttpTransport($registry->allowedHosts()),
        new CjErrorMapper(),
        new CjRetryPolicy(),
        new CjPointsParser(),
        $repository,
        $redactor,
        10,
        1048576,
        new CjRateLimiter($repository),
        new CjPointsBudget($repository),
        new CjCircuitBreaker($repository),
        static fn (string $operation): ?string => str_starts_with($operation, 'auth.') ? null : $tokens->accessToken()
    );
    $result = (new CjProductionReadOnlyProbe($client, $tokens))->run([
        'query' => (string) ($options['query'] ?? 'shoes'),
        'pid' => (string) ($options['pid'] ?? ''),
        'vid' => (string) ($options['vid'] ?? ''),
        'country' => (string) ($options['country'] ?? 'US'),
        'state' => (string) ($options['state'] ?? 'CA'),
        'postal' => (string) ($options['postal'] ?? '90001'),
        'quantity' => (int) ($options['quantity'] ?? 1),
    ]);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    $message = isset($redactor) && $redactor instanceof CjRedactor
        ? $redactor->redact($exception->getMessage())
        : preg_replace('/(token|secret|password|api[-_ ]?key)\s*[:=]\s*\S+/i', '$1=***', $exception->getMessage());
    fwrite(STDERR, 'CJ 真实只读探测失败：' . $message . PHP_EOL);
    exit(1);
}
