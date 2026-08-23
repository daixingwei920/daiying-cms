<?php

declare(strict_types=1);

define('CMS_SOURCE_ROOT', dirname(__DIR__));

$failures = 0;

function health_stateless_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

$application = (string) file_get_contents(CMS_SOURCE_ROOT . '/system/core/Bootstrap/Application.php');

health_stateless_check(str_contains($application, 'isStatelessHealthRequest()'), 'Application has a dedicated stateless health request guard');
health_stateless_check(str_contains($application, 'if (!self::isStatelessHealthRequest())') && str_contains($application, 'SessionManager::start'), 'health guard wraps global session startup');
health_stateless_check(str_contains($application, "\$path === '/health'"), 'health guard only matches the exact /health path');

if ($failures > 0) {
    fwrite(STDERR, $failures . " production health stateless checks failed.\n");
    exit(1);
}

echo "Production health stateless tests passed.\n";
