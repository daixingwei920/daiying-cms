<?php

declare(strict_types=1);

define('CMS_SOURCE_ROOT', dirname(__DIR__));

$failures = 0;

function p2_pkg_check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}

$command = PHP_BINARY . ' ' . escapeshellarg(CMS_SOURCE_ROOT . '/scripts/build_payment_fixture_release.php');
$output = [];
$exit = 0;
exec($command . ' 2>&1', $output, $exit);
$text = implode("\n", $output);

p2_pkg_check($exit === 2, 'retired Payment Fixture package builder refuses to create plugin artifacts');
p2_pkg_check(
    str_contains($text, 'Payment is a CMS Core foundation')
    && str_contains($text, 'core.fixture-payment')
    && str_contains($text, 'payment.fixture_provider_enabled'),
    'retired Payment Fixture package builder points operators to Core fixture configuration'
);

if ($failures > 0) {
    echo '[RESULT] payment_p2_provider_package failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo '[RESULT] payment_p2_provider_package passed.' . PHP_EOL;
