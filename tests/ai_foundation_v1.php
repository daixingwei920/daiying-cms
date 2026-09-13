<?php

declare(strict_types=1);

require __DIR__ . '/../system/core/Bootstrap/autoload.php';

use Cms\Core\Ai\AiAgentDefinition;
use Cms\Core\Ai\AiAgentRegistry;
use Cms\Core\Ai\AiAuditLogger;
use Cms\Core\Ai\AiException;
use Cms\Core\Ai\AiGateway;
use Cms\Core\Ai\AiJobService;
use Cms\Core\Ai\AiModel;
use Cms\Core\Ai\AiPromptRepository;
use Cms\Core\Ai\AiPromptRegistry;
use Cms\Core\Ai\AiPromptTemplate;
use Cms\Core\Ai\AiProviderClientInterface;
use Cms\Core\Ai\AiProviderInterface;
use Cms\Core\Ai\AiProviderPresets;
use Cms\Core\Ai\AiProviderRegistry;
use Cms\Core\Ai\AiQuotaService;
use Cms\Core\Ai\AiRequest;
use Cms\Core\Ai\AiResponse;
use Cms\Core\Ai\AiService;
use Cms\Core\Ai\AiToolDefinition;
use Cms\Core\Ai\AiToolRegistry;
use Cms\Core\Ai\AiToolRisk;
use Cms\Core\Ai\AiToolService;
use Cms\Core\Ai\AiUsageLedger;
use Cms\Core\Config\Settings;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Plugin\BlockRegistry;
use Cms\Core\Plugin\PluginContext;
use Cms\Core\Plugin\PluginDataStore;
use Cms\Core\Plugin\PluginException;
use Cms\Core\Plugin\PluginManifest;
use Cms\Core\Queue\QueueService;
use Cms\Core\Support\PublicApiRegistry;
use Cms\Core\Events\EventDispatcher;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo '[PASS] ' . $message . PHP_EOL;
        return;
    }
    $failures++;
    echo '[FAIL] ' . $message . PHP_EOL;
};

final class AiFoundationMockClient implements AiProviderClientInterface
{
    public bool $throwRuntime = false;

    public function chat(array $messages, array $config): array
    {
        if ($this->throwRuntime) {
            throw new RuntimeException('provider failed with api_key=sk-secret-value');
        }

        return [
            'provider' => (string) $config['provider'],
            'model' => (string) $config['model'],
            'content' => 'OK',
            'raw' => ['usage' => ['prompt_tokens' => 7, 'completion_tokens' => 3, 'total_tokens' => 10]],
        ];
    }
}

final class AiFoundationPluginProvider implements AiProviderInterface
{
    public function getId(): string
    {
        return 'vendor.demo';
    }

    public function getLabel(): string
    {
        return 'Vendor Demo';
    }

    public function getModels(): array
    {
        return [new AiModel('demo-text', 'Demo Text', ['text_generation'])];
    }

    public function getCapabilities(): array
    {
        return ['text_generation'];
    }

    public function execute(AiRequest $request, array $config): AiResponse
    {
        return new AiResponse('vendor.demo', (string) $config['model'], 'plugin-ok', [], ['input_tokens' => 1, 'output_tokens' => 1], $request->requestId);
    }

    public function testConnection(array $config): array
    {
        return ['provider' => 'vendor.demo', 'model' => 'demo-text', 'status' => 'success', 'message' => '连接成功'];
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$migrations = [
    require __DIR__ . '/../system/migrations/2026_08_12_000001_core_schema.php',
    require __DIR__ . '/../system/migrations/2026_09_07_000002_core_ai_settings.php',
    require __DIR__ . '/../system/migrations/2026_09_08_000001_core_ai_provider_presets.php',
    require __DIR__ . '/../system/migrations/2026_09_08_000003_foundation_system_services.php',
    require __DIR__ . '/../system/migrations/2026_09_08_000006_ai_foundation_v1.php',
];
(new MigrationRunner($pdo, $migrations))->run();
(new MigrationRunner($pdo, [$migrations[4]]))->run();
$pdo->exec('CREATE TABLE cms_plugin_data (id INTEGER PRIMARY KEY AUTOINCREMENT, plugin_id TEXT, data_key TEXT, payload TEXT, created_at TEXT, updated_at TEXT)');

$settings = Settings::fromArray(['security' => ['encryption_key' => 'ai-foundation-key'], 'database' => ['dsn' => 'sqlite::memory:']]);
$repo = new Cms\Core\Ai\SiteAiSettingsRepository($pdo, 'ai-foundation-key');
$repo->save([
    'enabled' => true,
    'provider' => 'deepseek',
    'base_url' => 'https://api.deepseek.com/v1',
    'model' => 'deepseek-chat',
    'timeout_seconds' => 20,
    'max_tokens' => 1024,
    'temperature' => 0.2,
], 'sk-ai-foundation-secret', false);

$presets = AiProviderPresets::all();
$check($presets['deepseek']['adapter'] === 'openai_compatible' && $presets['local_model']['adapter'] === 'openai_compatible' && $presets['gemini']['adapter'] === 'gemini' && $presets['openclaw']['adapter'] === 'openclaw', 'provider presets keep shared OpenAI-compatible adapter plus Gemini and OpenClaw adapters');
$providers = AiProviderRegistry::describe();
$check(isset($providers['deepseek'], $providers['openai'], $providers['xai'], $providers['tencent_hunyuan'], $providers['qwen'], $providers['gemini'], $providers['local_model'], $providers['openai_compatible'], $providers['openclaw']), 'default provider registry exposes all friendly presets');
$check(in_array('structured_output', $providers['deepseek']['capabilities'], true), 'provider models declare capabilities');

$usage = new AiUsageLedger($pdo);
$gateway = new AiGateway(
    new AiService($pdo, $settings, new AiFoundationMockClient()),
    $usage,
    new AiQuotaService($pdo, $usage),
    null,
    new AiAuditLogger($pdo),
);
$response = $gateway->request(AiRequest::chat([['role' => 'user', 'content' => 'hello']], ['plugin_id' => 'official.commerce']));
$check($response->content === 'OK' && $response->usage['total_tokens'] === 10, 'AI Gateway returns normalized AI responses and usage');
$check((int) $pdo->query('SELECT COUNT(*) FROM cms_ai_usage_ledger WHERE plugin_id = "official.commerce" AND input_tokens = 7')->fetchColumn() === 1, 'AI usage ledger records plugin scoped token usage');
$check((int) $pdo->query('SELECT COUNT(*) FROM cms_ai_audit_events WHERE result = "success"')->fetchColumn() === 1, 'AI audit table records AI request outcomes');

$now = gmdate('c');
$pdo->prepare(
    'INSERT INTO cms_ai_quota_policies
     (scope_type, scope_id, provider, model, operation, daily_request_limit, monthly_request_limit, daily_token_limit, monthly_token_limit, status, created_at, updated_at)
     VALUES ("plugin", "official.commerce", "", "", "", 1, 0, 0, 0, "enabled", :created_at, :updated_at)'
)->execute([':created_at' => $now, ':updated_at' => $now]);
try {
    $gateway->request(AiRequest::chat([['role' => 'user', 'content' => 'again']], ['plugin_id' => 'official.commerce']));
    $check(false, 'AI quota blocks excess plugin requests');
} catch (AiException $exception) {
    $check($exception->reason() === 'quota_exceeded', 'AI quota blocks excess plugin requests');
}

$badClient = new AiFoundationMockClient();
$badClient->throwRuntime = true;
try {
    (new AiGateway(new AiService($pdo, $settings, $badClient)))->request(AiRequest::chat([['role' => 'user', 'content' => 'fail']]));
    $check(false, 'provider runtime failures are isolated');
} catch (AiException $exception) {
    $check($exception->reason() === 'provider_error' && !str_contains($exception->getMessage(), 'sk-secret-value'), 'provider runtime failures are redacted and isolated');
}

AiToolRegistry::clear();
AiAgentRegistry::clear();
AiPromptRegistry::clear();
AiToolRegistry::register(new AiToolDefinition('content.read', 'Read Content', AiToolRisk::READ, ['content.read']), static fn (array $payload): array => ['id' => (int) ($payload['id'] ?? 0)]);
AiToolRegistry::register(new AiToolDefinition('content.delete', 'Delete Content', AiToolRisk::DESTRUCTIVE, ['content.delete']), static fn (array $payload): array => ['deleted' => true]);
$toolService = new AiToolService();
$check($toolService->execute('content.read', ['id' => 9], ['content.read'])['id'] === 9, 'AI tool service executes READ tools with capability checks');
try {
    $toolService->execute('content.delete', ['id' => 9], ['content.delete']);
    $check(false, 'destructive AI tools require explicit confirmation');
} catch (AiException $exception) {
    $check($exception->reason() === 'confirmation_required', 'destructive AI tools require explicit confirmation');
}

$agent = new AiAgentDefinition('official.content.agent', 'Content Agent', 'Reads content safely.', ['content.read'], ['content.read'], 'official.content');
AiAgentRegistry::register($agent);
$check(AiAgentRegistry::get('official.content.agent')?->ownerPlugin === 'official.content', 'AI agent registry stores plugin-owned agent definitions');
$prompt = new AiPromptTemplate('official.content/summary', '1.0', 'Summarize {{title}} for {{site_name}}.', ['title', 'site_name'], 'official.content');
AiPromptRegistry::register($prompt);
$check(AiPromptRegistry::get('official.content/summary')?->render(['title' => 'Post', 'site_name' => 'Daiying']) === 'Summarize Post for Daiying.', 'AI prompt registry renders versioned templates');
$promptRepository = new AiPromptRepository($pdo);
$promptRepository->save($prompt);
$check($promptRepository->get('official.content/summary')?->render(['title' => 'Post', 'site_name' => 'CMS']) === 'Summarize Post for CMS.', 'AI prompt repository persists prompt templates idempotently');

$queue = new QueueService($pdo);
$jobs = new AiJobService($pdo);
$jobGateway = new AiGateway(new AiService($pdo, $settings, new AiFoundationMockClient()), null, null, null, null, $queue, $jobs);
$jobRowId = $jobGateway->queueChat([['role' => 'user', 'content' => 'queued']], ['plugin_id' => 'official.content']);
$job = $pdo->query('SELECT * FROM cms_ai_jobs WHERE id = ' . (int) $jobRowId)->fetch();
$check(is_array($job) && (int) $job['queue_job_id'] > 0 && $job['owner_plugin'] === 'official.content', 'AI jobs reuse the Core queue and store AI progress metadata');

$allowedManifest = new PluginManifest('vendor.ai', 'AI Plugin', '1.0.0', 'Unit', '1.0.0', '8.3.0', 'plugin.php', 'api', ['ai.provider', 'ai.tool', 'ai.agent', 'ai.prompt'], [], [], 'plugin', false, [], [], '');
$allowedContext = new PluginContext($allowedManifest, new EventDispatcher(), new BlockRegistry(), new PluginDataStore($pdo, 'vendor.ai'), null);
$allowedContext->registerAiProvider(new AiFoundationPluginProvider());
$allowedContext->registerAiTool(new AiToolDefinition('vendor.demo.search', 'Search', AiToolRisk::READ, [], 'vendor.ai'), static fn (array $payload): array => ['ok' => true]);
$allowedContext->registerAiAgent(new AiAgentDefinition('vendor.demo.agent', 'Demo Agent', '', [], ['vendor.demo.search'], 'vendor.ai'));
$allowedContext->registerAiPrompt(new AiPromptTemplate('vendor.demo/prompt', '1.0', 'Hello {{name}}', ['name'], 'vendor.ai'));
$check(AiProviderRegistry::get('vendor.demo') !== null && AiToolRegistry::get('vendor.demo.search') !== null, 'PluginContext exposes stable AI Provider and Tool registration APIs');
$blockedContext = new PluginContext(new PluginManifest('vendor.blocked', 'Blocked', '1.0.0', 'Unit', '1.0.0', '8.3.0', 'plugin.php', 'api', [], [], [], 'plugin', false, [], [], ''), new EventDispatcher(), new BlockRegistry(), new PluginDataStore($pdo, 'vendor.blocked'), null);
try {
    $blockedContext->registerAiProvider(new AiFoundationPluginProvider());
    $check(false, 'AI provider registration requires declared capability');
} catch (PluginException) {
    $check(true, 'AI provider registration requires declared capability');
}

$publicIds = PublicApiRegistry::ids();
$check(in_array('ai.gateway', $publicIds, true) && in_array('ai.provider', $publicIds, true) && in_array('ai.model_discovery', $publicIds, true) && in_array('ai.tools', $publicIds, true), 'AI Foundation contracts are published through Public API v1');
$check(!class_exists('Cms\\Core\\Market\\AiReviewProvider') || !str_contains((string) file_get_contents(__FILE__), 'updates.daiyingcms.com'), 'official update server AI review remains outside site AI Foundation runtime');

if ($failures > 0) {
    echo 'AI Foundation V1 tests failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'AI Foundation V1 tests passed.' . PHP_EOL;
