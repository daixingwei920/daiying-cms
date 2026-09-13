<?php

declare(strict_types=1);

require __DIR__ . '/../system/core/Bootstrap/autoload.php';

use Cms\Core\Ai\AiException;
use Cms\Core\Ai\AiProviderClientInterface;
use Cms\Core\Ai\AiProviderPresets;
use Cms\Core\Ai\AiProviderRegistry;
use Cms\Core\Ai\AiService;
use Cms\Core\Ai\OpenAiCompatibleProviderClient;
use Cms\Core\Ai\OpenClawProviderClient;
use Cms\Core\Ai\SiteAiSettingsRepository;
use Cms\Core\Config\Settings;
use Cms\Core\Migration\MigrationRunner;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo '[PASS] ' . $message . PHP_EOL;
        return;
    }
    $failures++;
    echo '[FAIL] ' . $message . PHP_EOL;
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$migrations = [
    require __DIR__ . '/../system/migrations/2026_09_07_000002_core_ai_settings.php',
    require __DIR__ . '/../system/migrations/2026_09_08_000001_core_ai_provider_presets.php',
    require __DIR__ . '/../system/migrations/2026_09_13_000001_core_ai_local_openclaw.php',
];
(new MigrationRunner($pdo, $migrations))->run();
(new MigrationRunner($pdo, [$migrations[2]]))->run();

$columns = array_map(static fn (array $row): string => (string) $row['name'], $pdo->query('PRAGMA table_info(cms_core_ai_settings)')->fetchAll());
$check(in_array('context_window', $columns, true) && in_array('openclaw_agent', $columns, true) && in_array('allow_cloud_fallback', $columns, true), 'Local/OpenClaw migration adds routing metadata columns idempotently');

$settings = Settings::fromArray(['security' => ['encryption_key' => 'local-openclaw-key'], 'database' => ['dsn' => 'sqlite::memory:']]);
$repo = new SiteAiSettingsRepository($pdo, 'local-openclaw-key');
$repo->save([
    'enabled' => true,
    'provider' => 'local_model',
    'provider_name' => 'Desk GPU',
    'adapter' => 'openai_compatible',
    'base_url' => 'http://127.0.0.1:11434',
    'model' => 'llama3.1',
    'timeout_seconds' => 21,
    'max_tokens' => 512,
    'temperature' => 0.2,
    'context_window' => 8192,
    'local_api_type' => 'openai_compatible',
    'allow_cloud_fallback' => false,
], '', true);
$runtime = $repo->runtimeConfig();
$check($runtime['provider'] === 'local_model' && $runtime['api_key_required'] === false && $runtime['context_window'] === 8192, 'Local Model runtime config keeps optional secret and context window metadata');
$check((new AiService($pdo, $settings))->isEnabled() === true, 'Local Model can be globally enabled without API Key');

$captured = [];
$localClient = new OpenAiCompatibleProviderClient(static function (string $method, string $url, array $headers, string $json, int $timeout) use (&$captured): array {
    $captured[] = compact('method', 'url', 'headers', 'json', 'timeout');
    if ($method === 'GET') {
        return ['headers' => ['HTTP/1.1 200'], 'body' => '{"data":[{"id":"llama3.1"},{"id":"qwen2.5:7b"}]}'];
    }

    return ['headers' => ['HTTP/1.1 200'], 'body' => '{"id":"chatcmpl-local","choices":[{"message":{"content":"local ok"}}],"usage":{"total_tokens":4}}'];
});
$localChat = $localClient->chat([['role' => 'user', 'content' => 'hello']], $runtime);
$check($localChat['content'] === 'local ok' && $captured[0]['url'] === 'http://127.0.0.1:11434/v1/chat/completions', 'Local Model chat uses OpenAI-compatible /v1 endpoint for bare Ollama URLs');
$check(!in_array('Authorization: Bearer ', $captured[0]['headers'], true), 'Local Model does not send an empty Authorization header');
$models = $localClient->models($runtime);
$check(array_column($models, 'id') === ['llama3.1', 'qwen2.5:7b'], 'Local Model discovery reads OpenAI-compatible model lists');

$groqCaptured = [];
$groqClient = new OpenAiCompatibleProviderClient(static function (string $method, string $url, array $headers, string $json, int $timeout) use (&$groqCaptured): array {
    $groqCaptured[] = compact('method', 'url', 'headers', 'json', 'timeout');

    return [
        'headers' => ['HTTP/2 200'],
        'body' => '{"id":"chatcmpl-groq","choices":[{"message":{"role":"assistant","content":"groq ok","reasoning":"hidden reasoning"},"finish_reason":"stop"}],"usage":{"total_tokens":8}}',
    ];
});
$groqRuntime = AiProviderPresets::applyDefaults([
    'enabled' => true,
    'provider' => 'groq',
    'api_key' => 'gsk-test-secret',
    'timeout_seconds' => 30,
    'max_tokens' => 32,
    'temperature' => 0.0,
]);
$groqChat = $groqClient->chat([['role' => 'user', 'content' => 'hello']], $groqRuntime);
$groqPayload = json_decode((string) ($groqCaptured[0]['json'] ?? '{}'), true);
$check($groqChat['content'] === 'groq ok' && $groqCaptured[0]['url'] === 'https://api.groq.com/openai/v1/chat/completions', 'Groq preset uses the shared OpenAI-compatible chat completions endpoint');
$check(($groqPayload['model'] ?? '') === 'openai/gpt-oss-20b' && ($groqPayload['include_reasoning'] ?? null) === false, 'Groq GPT-OSS requests send the selected model and hide reasoning by default');
$check(($groqChat['raw']['http_status'] ?? 0) === 200 && str_contains((string) ($groqChat['raw']['response_structure'] ?? ''), 'choices[0].message'), 'OpenAI-compatible responses include safe status and response structure metadata');

$contentPartsClient = new OpenAiCompatibleProviderClient(static fn (): array => [
    'headers' => ['HTTP/1.1 200'],
    'body' => '{"choices":[{"message":{"content":[{"type":"text","text":"part one"},{"type":"text","text":"part two"}]}}]}',
]);
$partsChat = $contentPartsClient->chat([['role' => 'user', 'content' => 'hello']], [
    'provider' => 'openai_compatible',
    'api_key' => 'sk-test-secret',
    'api_key_required' => true,
    'base_url' => 'https://ai.example.test/v1',
    'model' => 'compatible-model',
]);
$check($partsChat['content'] === "part one\npart two", 'OpenAI-compatible parser accepts array content parts');

try {
    (new OpenAiCompatibleProviderClient(static fn (): array => [
        'headers' => ['HTTP/1.1 200'],
        'body' => '{"choices":[{"message":{"role":"assistant","reasoning":"thinking only"},"finish_reason":"length"}]}',
    ]))->chat([['role' => 'user', 'content' => 'hello']], $groqRuntime);
    $check(false, 'Empty OpenAI-compatible responses include diagnostics');
} catch (AiException $exception) {
    $check($exception->reason() === 'response_empty' && str_contains($exception->getMessage(), 'HTTP 200') && str_contains($exception->getMessage(), 'response_structure='), 'Empty OpenAI-compatible responses include safe HTTP/status diagnostics');
}

$repo->save([
    'enabled' => true,
    'provider' => 'openclaw',
    'provider_name' => 'OpenClaw Lab',
    'adapter' => 'openclaw',
    'base_url' => 'http://127.0.0.1:18080',
    'model' => 'agent-model',
    'timeout_seconds' => 22,
    'max_tokens' => 256,
    'temperature' => 0.1,
    'openclaw_agent' => 'developer-assistant',
    'allow_cloud_fallback' => false,
], 'oc-test-token', false);
$openclawRuntime = $repo->runtimeConfig();
$openclawCaptured = [];
$openclawClient = new OpenClawProviderClient(static function (string $method, string $url, array $headers, string $json, int $timeout) use (&$openclawCaptured): array {
    $openclawCaptured[] = compact('method', 'url', 'headers', 'json', 'timeout');
    if ($method === 'GET') {
        return ['headers' => ['HTTP/1.1 200'], 'body' => '{"models":[{"id":"agent-model","label":"Agent Model"}]}'];
    }

    return ['headers' => ['HTTP/1.1 200'], 'body' => '{"content":"openclaw ok","usage":{"total_tokens":5}}'];
});
$openclawChat = $openclawClient->chat([['role' => 'user', 'content' => 'hello']], $openclawRuntime);
$check($openclawChat['content'] === 'openclaw ok' && str_contains($openclawCaptured[0]['url'], '/api/v1/agents/developer-assistant/chat'), 'OpenClaw chat routes through the configured agent endpoint');
$check(in_array('Authorization: Bearer oc-test-token', $openclawCaptured[0]['headers'], true), 'OpenClaw sends token only server-side');
$openclawModels = $openclawClient->models($openclawRuntime);
$check(($openclawModels[0]['id'] ?? '') === 'agent-model', 'OpenClaw model discovery parses gateway model lists');

$repo->save([
    'enabled' => true,
    'provider' => 'deepseek',
    'adapter' => 'openai_compatible',
    'base_url' => 'https://api.deepseek.com/v1',
    'model' => 'deepseek-chat',
    'timeout_seconds' => 30,
    'max_tokens' => 1024,
    'temperature' => 0.7,
    'allow_cloud_fallback' => true,
    'fallback_provider' => 'openai',
], '', false);
$cloudRuntime = $repo->runtimeConfig();
$check($cloudRuntime['api_key'] === 'oc-test-token' && $cloudRuntime['fallback_provider'] === 'openai', 'Provider switching preserves existing encrypted token unless explicitly cleared');
$check(AiProviderPresets::isCloudProvider('openai') === true && AiProviderPresets::isCloudProvider('local_model') === false, 'Fallback can distinguish cloud providers from local providers');

$repo->save([
    'enabled' => true,
    'provider' => 'local_model',
    'adapter' => 'openai_compatible',
    'base_url' => 'http://127.0.0.1:11434/v1',
    'model' => 'llama3.1',
    'timeout_seconds' => 10,
    'max_tokens' => 64,
    'temperature' => 0.0,
    'allow_cloud_fallback' => true,
    'fallback_provider' => 'openai',
], 'sk-fallback-cloud', false);
$fallbackClient = new class implements AiProviderClientInterface {
    /** @var list<string> */
    public array $providers = [];

    public function chat(array $messages, array $config): array
    {
        $provider = (string) $config['provider'];
        $this->providers[] = $provider;
        if ($provider === 'local_model') {
            throw new AiException('Local model endpoint timed out.', 'timeout');
        }

        return [
            'provider' => $provider,
            'model' => (string) $config['model'],
            'content' => 'fallback ok',
            'raw' => ['usage' => []],
        ];
    }
};
$fallbackResult = (new AiService($pdo, $settings, $fallbackClient))->chat([['role' => 'user', 'content' => 'hello']]);
$check($fallbackResult['provider'] === 'openai' && $fallbackClient->providers === ['local_model', 'openai'], 'Cloud fallback runs only after the configured local provider fails');

try {
    (new OpenClawProviderClient(static fn (): array => ['headers' => ['HTTP/1.1 404'], 'body' => '{"message":"missing agent"}']))
        ->chat([['role' => 'user', 'content' => 'hello']], $openclawRuntime);
    $check(false, 'OpenClaw 404 returns a readable failure');
} catch (AiException $exception) {
    $check($exception->reason() === 'model_not_found' && !str_contains($exception->getMessage(), 'oc-test-token'), 'OpenClaw failures are readable and redacted');
}

$providers = AiProviderRegistry::describe();
$check(isset($providers['local_model'], $providers['openclaw']) && in_array('agent', $providers['openclaw']['capabilities'], true), 'Core provider registry exposes Local Model and OpenClaw capabilities');

if ($failures > 0) {
    echo 'Core AI Local/OpenClaw tests failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'Core AI Local/OpenClaw tests passed.' . PHP_EOL;
