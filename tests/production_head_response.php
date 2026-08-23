<?php

declare(strict_types=1);

use Cms\Core\Http\Response;

define('CMS_SOURCE_ROOT', dirname(__DIR__));

require CMS_SOURCE_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;
$results = [];

function head_response_check(bool $condition, string $message): void
{
    global $failures, $results;
    if (!$condition) {
        $failures++;
        $results[] = '[FAIL] ' . $message;
        return;
    }
    $results[] = '[PASS] ' . $message;
}

function head_response_send(Response $response, string $method): string
{
    $previous = $_SERVER['REQUEST_METHOD'] ?? null;
    $_SERVER['REQUEST_METHOD'] = $method;
    ob_start();
    $response->send();
    $output = (string) ob_get_clean();
    if ($previous === null) {
        unset($_SERVER['REQUEST_METHOD']);
    } else {
        $_SERVER['REQUEST_METHOD'] = $previous;
    }

    return $output;
}

head_response_check(head_response_send(Response::html('<h1>CMS</h1>'), 'GET') === '<h1>CMS</h1>', 'GET responses send response body');
head_response_check(head_response_send(Response::html('<h1>CMS</h1>'), 'HEAD') === '', 'HEAD responses suppress response body at send layer');
head_response_check(head_response_send(Response::json(['status' => 'ok']), 'HEAD') === '', 'HEAD JSON responses suppress response body at send layer');
$redirect = Response::redirect('/admin/login');
head_response_check(head_response_send($redirect, 'HEAD') === '' && ($redirect->headers()['Location'] ?? '') === '/admin/login', 'HEAD redirects keep Location header and suppress body');

foreach ($results as $result) {
    echo $result . PHP_EOL;
}

if ($failures > 0) {
    fwrite(STDERR, $failures . " production HEAD response checks failed.\n");
    exit(1);
}

echo "Production HEAD response tests passed.\n";
