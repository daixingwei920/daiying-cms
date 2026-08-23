<?php

declare(strict_types=1);

define('CMS_SOURCE_ROOT', dirname(__DIR__));

$failures = 0;

function runtime_header_check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $message . PHP_EOL;
        return;
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

$responseSource = (string) file_get_contents(CMS_SOURCE_ROOT . '/system/core/Http/Response.php');

runtime_header_check(str_contains($responseSource, "header_remove('X-Powered-By')"), 'response sender removes PHP runtime disclosure header');
runtime_header_check(strpos($responseSource, "header_remove('X-Powered-By')") < strpos($responseSource, 'http_response_code($this->status)'), 'runtime disclosure header is removed before response headers are sent');
runtime_header_check(str_contains($responseSource, "function_exists('header_remove')"), 'runtime disclosure removal is guarded for portability');

if ($failures > 0) {
    fwrite(STDERR, $failures . " production runtime header privacy checks failed.\n");
    exit(1);
}

echo "Production runtime header privacy tests passed.\n";
