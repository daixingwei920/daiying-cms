<?php

declare(strict_types=1);

require __DIR__ . '/../system/core/Bootstrap/autoload.php';

use Cms\Core\Ai\AiRequest;
use Cms\Core\Ai\OpenAiCompatibleProvider;
use Cms\Core\Ai\OpenAiCompatibleProviderClient;

$assertions = 0;
$check = static function (bool $ok, string $message) use (&$assertions): void {
    if (!$ok) throw new RuntimeException($message);
    $assertions++;
    echo "[PASS] $message\n";
};
// Exercise real payload construction and parsing, without keys or paid API calls.
$captured = [];
$client = new OpenAiCompatibleProviderClient(static function (string $method, string $url, array $headers, string $json, int $timeout) use (&$captured): array {
    $captured[] = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    return ['headers' => ['HTTP/1.1 200 OK'], 'body' => '{"choices":[{"message":{"content":"OK"}}]}'];
});
foreach (['gpt-5', 'gpt-5-mini', 'gpt-5-nano-2025-08-07', 'gpt-5-2025-08-07', 'gpt-5.1', 'gpt-5.6-terra'] as $model) {
    foreach (['openai', 'openai_compatible'] as $id) {
        $provider = new OpenAiCompatibleProvider($id, 'Fixture', [], $client);
        $config = ['model' => $model, 'base_url' => 'https://isolated.example/v1', 'max_tokens' => 1024, 'temperature' => 0.3];
        $response = $provider->execute(AiRequest::chat([['role' => 'user', 'content' => 'Hello']]), $config);
        $payload = $captured[array_key_last($captured)];
        $check($response->content === 'OK' && ($payload['max_completion_tokens'] ?? 0) === 1024 && !isset($payload['max_tokens']), "$id $model normal chat uses the completion budget");
        $check(!isset($payload['temperature']) && !isset($payload['include_reasoning']), "$id $model omits unsupported optional fields");
        $test = $provider->testConnection($config);
        $payload = $captured[array_key_last($captured)];
        $check($test['status'] === 'success' && ($payload['max_completion_tokens'] ?? 0) === 2048 && !array_key_exists('max_tokens', $payload) && !array_key_exists('temperature', $payload), "$id $model connection test allows reasoning tokens");
    }
}
foreach ([['openai', 'gpt-4.1-mini'], ['deepseek', 'deepseek-chat'], ['groq', 'llama-3.3-70b-versatile'], ['local_model', 'gpt-50-custom']] as [$id, $model]) {
    $provider = new OpenAiCompatibleProvider($id, 'Fixture', [], $client);
    $config = ['model' => $model, 'base_url' => 'https://isolated.example/v1', 'max_tokens' => 777, 'temperature' => 0.3];
    $provider->execute(AiRequest::chat([['role' => 'user', 'content' => 'Hello']]), $config);
    $payload = $captured[array_key_last($captured)];
    $check(($payload['max_tokens'] ?? 0) === 777 && ($payload['temperature'] ?? null) === 0.3 && !isset($payload['max_completion_tokens']), "$id $model retains existing request semantics");
}
$client->chat([['role' => 'user', 'content' => 'Hello']], ['provider' => 'openai', 'model' => 'gpt-5-mini', 'max_tokens' => 900000]);
$check($captured[array_key_last($captured)]['max_completion_tokens'] === 200000, 'GPT-5 budgets retain the shared upper bound');
echo "GPT-5 parameter contract: $assertions assertions passed (isolated transport; no live provider acceptance).\n";
