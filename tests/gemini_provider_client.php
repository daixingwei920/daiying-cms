<?php

declare(strict_types=1);

use Cms\Core\Ai\AiException;
use Cms\Core\Ai\GeminiProviderClient;

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo '[PASS] ' . $message . PHP_EOL;
        return;
    }
    $failures++;
    echo '[FAIL] ' . $message . PHP_EOL;
};

$lastUrl = '';
$client = new GeminiProviderClient(static function (string $url, array $headers, string $json, int $timeout) use (&$lastUrl): array {
    $lastUrl = $url;

    return [
        'headers' => ['HTTP/1.1 200 OK'],
        'body' => json_encode([
            'candidates' => [[
                'content' => [
                    'parts' => [['text' => 'OK']],
                ],
            ]],
            'usageMetadata' => ['promptTokenCount' => 1],
        ], JSON_UNESCAPED_SLASHES),
    ];
});

$result = $client->chat([['role' => 'user', 'content' => 'hello']], [
    'provider' => 'gemini',
    'api_key' => 'test-key',
    'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
    'model' => 'models/gemini-3.6-flash',
    'timeout_seconds' => 30,
    'max_tokens' => 16,
    'temperature' => 0.0,
]);
$check($result['content'] === 'OK' && $result['model'] === 'gemini-3.6-flash', 'Gemini client accepts models/ prefixed model names and normalizes the returned model id');
$check(str_contains($lastUrl, '/v1beta/models/gemini-3.6-flash:generateContent?') && !str_contains($lastUrl, 'models%2F'), 'Gemini client builds a valid models/{model}:generateContent URL');

$notFoundClient = new GeminiProviderClient(static function (): array {
    return [
        'headers' => ['HTTP/1.1 404 Not Found'],
        'body' => json_encode([
            'error' => [
                'message' => 'This model models/gemini-2.5-flash is no longer available to new users. Use models/gemini-3.6-flash.',
            ],
        ], JSON_UNESCAPED_SLASHES),
    ];
});
try {
    $notFoundClient->chat([['role' => 'user', 'content' => 'hello']], [
        'provider' => 'gemini',
        'api_key' => 'test-key',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        'model' => 'gemini-2.5-flash',
        'timeout_seconds' => 30,
    ]);
    $check(false, 'Gemini 404 returns a safe actionable provider message');
} catch (AiException $exception) {
    $check($exception->reason() === 'model_not_found' && str_contains($exception->getMessage(), 'gemini-3.6-flash'), 'Gemini 404 returns a safe actionable provider message');
}

$emptyClient = new GeminiProviderClient(static function (): array {
    return [
        'headers' => ['HTTP/1.1 200 OK'],
        'body' => json_encode([
            'candidates' => [[
                'finishReason' => 'MAX_TOKENS',
                'content' => ['parts' => []],
            ]],
        ], JSON_UNESCAPED_SLASHES),
    ];
});
try {
    $emptyClient->chat([['role' => 'user', 'content' => 'hello']], [
        'provider' => 'gemini',
        'api_key' => 'test-key',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        'model' => 'gemini-3.6-flash',
        'timeout_seconds' => 30,
    ]);
    $check(false, 'Gemini empty responses expose a safe finish reason');
} catch (AiException $exception) {
    $check($exception->reason() === 'response_empty' && str_contains($exception->getMessage(), 'MAX_TOKENS'), 'Gemini empty responses expose a safe finish reason');
}

try {
    $client->chat([['role' => 'user', 'content' => 'hello']], [
        'provider' => 'gemini',
        'api_key' => 'test-key',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        'model' => 'tunedModels/custom',
        'timeout_seconds' => 30,
    ]);
    $check(false, 'Gemini client rejects unsupported slash-containing model paths');
} catch (AiException $exception) {
    $check($exception->reason() === 'model_invalid', 'Gemini client rejects unsupported slash-containing model paths');
}

if ($failures > 0) {
    echo 'Gemini provider client tests failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'Gemini provider client tests passed.' . PHP_EOL;
