<?php

declare(strict_types=1);

use Cms\Core\Ai\AI;
use Cms\Core\Ai\AiException;
use Cms\Core\Ai\AiProviderClientInterface;
use Cms\Core\Ai\AiProviderPresets;
use Cms\Core\Ai\AiService;
use Cms\Core\Ai\SiteAiSettingsRepository;
use Cms\Core\Config\Settings;
use Cms\Core\Migration\MigrationRunner;
use Cms\Core\Plugin\BlockRegistry;
use Cms\Core\Plugin\PluginContext;
use Cms\Core\Plugin\PluginDataStore;
use Cms\Core\Plugin\PluginManifest;
use Cms\Core\Events\EventDispatcher;

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

final class CoreAiMockClient implements AiProviderClientInterface
{
    public bool $fail = false;
    /** @var array<string,mixed> */
    public array $lastConfig = [];

    public function chat(array $messages, array $config): array
    {
        $this->lastConfig = $config;
        if ($this->fail) {
            throw new AiException('AI API Key is invalid or unauthorized. HTTP 401.', 'auth_failed');
        }

        return [
            'provider' => (string) $config['provider'],
            'model' => (string) $config['model'],
            'content' => 'OK',
            'raw' => ['usage' => []],
        ];
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE cms_plugin_data (id INTEGER PRIMARY KEY AUTOINCREMENT, plugin_id TEXT, data_key TEXT, payload TEXT, created_at TEXT, updated_at TEXT)');

$migration = require CMS_ROOT . '/system/migrations/2026_09_07_000002_core_ai_settings.php';
$presetMigration = require CMS_ROOT . '/system/migrations/2026_09_08_000001_core_ai_provider_presets.php';
(new MigrationRunner($pdo, [$migration, $presetMigration]))->run();
$check((int) $pdo->query('SELECT COUNT(*) FROM cms_core_ai_settings WHERE id = 1')->fetchColumn() === 1, 'migration creates a default site AI settings row');
$check(in_array('adapter', array_map(static fn (array $row): string => (string) $row['name'], $pdo->query('PRAGMA table_info(cms_core_ai_settings)')->fetchAll()), true), 'preset migration adds adapter protocol column');

$presets = AiProviderPresets::all();
$check(($presets['deepseek']['adapter'] ?? '') === 'openai_compatible', 'DeepSeek preset uses the shared OpenAI-compatible adapter');
$check(($presets['openai']['adapter'] ?? '') === 'openai_compatible', 'OpenAI preset uses the shared OpenAI-compatible adapter');
$check(($presets['xai']['adapter'] ?? '') === 'openai_compatible', 'Grok / xAI preset uses the shared OpenAI-compatible adapter');
$check(($presets['tencent_hunyuan']['adapter'] ?? '') === 'openai_compatible', 'Tencent Hunyuan preset uses the shared OpenAI-compatible adapter');
$check(($presets['gemini']['adapter'] ?? '') === 'gemini', 'Gemini preset uses the native Gemini adapter');
$check(AiProviderPresets::normalize('grok') === 'xai' && AiProviderPresets::normalize('openai-compatible') === 'openai_compatible', 'legacy and alias provider names are normalized');

$settings = Settings::fromArray(['security' => ['encryption_key' => 'core-ai-test-key'], 'database' => ['dsn' => 'sqlite::memory:']]);
$repo = new SiteAiSettingsRepository($pdo, 'core-ai-test-key');
$initial = $repo->current();
$check($initial['enabled'] === false && $initial['api_key_configured'] === false, 'old sites without AI config get disabled safe defaults');

$repo->save([
    'enabled' => true,
    'provider' => 'deepseek',
    'base_url' => 'https://api.deepseek.com/v1',
    'model' => 'deepseek-chat',
    'timeout_seconds' => 20,
    'max_tokens' => 2048,
    'temperature' => 0.3,
], 'sk-test-site-ai-secret', false);
$saved = $repo->current();
$runtime = $repo->runtimeConfig();
$check($saved['api_key_configured'] === true && $saved['api_key_masked'] === '********', 'admin config masks the API Key');
$check($runtime['api_key'] === 'sk-test-site-ai-secret' && $runtime['adapter'] === 'openai_compatible', 'runtime config can decrypt API Key server-side only and derives the adapter');

$repo->save([
    'enabled' => true,
    'provider' => 'openai_compatible',
    'base_url' => 'https://ai.example.test/v1',
    'model' => 'compatible-model',
    'timeout_seconds' => 15,
    'max_tokens' => 1024,
    'temperature' => 0.1,
], '', false);
$runtime = $repo->runtimeConfig();
$check($runtime['api_key'] === 'sk-test-site-ai-secret' && $runtime['provider'] === 'openai_compatible', 'saving non-secret fields preserves the existing API Key');

$mock = new CoreAiMockClient();
$service = new AiService($pdo, $settings, $mock);
$check($service->isEnabled() === true, 'AI service reports enabled only when switch and API Key are both present');
$check($service->capabilities()['adapters'] === ['openai_compatible', 'gemini'], 'AI service exposes stable adapter capabilities');
$result = $service->testConnection();
$check($result['status'] === 'success' && $result['provider'] === 'openai_compatible' && $result['model'] === 'compatible-model', 'testConnection succeeds through the configured provider and model');
$check(($mock->lastConfig['api_key'] ?? '') === 'sk-test-site-ai-secret', 'provider calls receive the decrypted API Key internally');
$check(($mock->lastConfig['adapter'] ?? '') === 'openai_compatible', 'plugin and Core callers receive adapter routing metadata through the unified service');

$repo->save([
    'enabled' => false,
    'provider' => 'gemini',
    'base_url' => '',
    'model' => '',
    'timeout_seconds' => 15,
    'max_tokens' => 1024,
    'temperature' => 0.1,
], '', false);
$geminiRuntime = $repo->runtimeConfig();
$check($geminiRuntime['adapter'] === 'gemini' && $geminiRuntime['base_url'] === 'https://generativelanguage.googleapis.com/v1beta' && $geminiRuntime['model'] === 'gemini-3.6-flash', 'Gemini preset fills native adapter defaults while remaining editable');
$disabledService = new AiService($pdo, $settings, $mock);
$check($disabledService->isEnabled() === false, 'AI service reports disabled when the global switch is off');
try {
    $disabledService->chat([['role' => 'user', 'content' => 'Hello']]);
    $check(false, 'disabled AI calls fail without crashing');
} catch (AiException $exception) {
    $check($exception->reason() === 'disabled', 'disabled AI calls return a clear disabled error');
}

$repo->save([
    'enabled' => true,
    'provider' => 'openai_compatible',
    'base_url' => 'https://ai.example.test/v1',
    'model' => 'compatible-model',
    'timeout_seconds' => 15,
    'max_tokens' => 1024,
    'temperature' => 0.1,
], '', true);
$missingKeyService = new AiService($pdo, $settings, $mock);
$check($missingKeyService->isEnabled() === false, 'AI service reports unavailable when enabled without an API Key');
try {
    $missingKeyService->chat([['role' => 'user', 'content' => 'Hello']]);
    $check(false, 'missing API Key calls fail without crashing');
} catch (AiException $exception) {
    $check($exception->reason() === 'api_key_missing', 'missing API Key returns a clear error');
}

$repo->save([
    'enabled' => true,
    'provider' => 'openai_compatible',
    'base_url' => 'https://ai.example.test/v1',
    'model' => 'compatible-model',
    'timeout_seconds' => 15,
    'max_tokens' => 1024,
    'temperature' => 0.1,
], 'sk-test-site-ai-secret', false);
$mock->fail = true;
try {
    (new AiService($pdo, $settings, $mock))->testConnection();
    $check(false, 'provider auth failures are isolated');
} catch (AiException $exception) {
    $check($exception->reason() === 'auth_failed' && !str_contains($exception->getMessage(), 'sk-test-site-ai-secret'), 'provider auth failures return a safe error without leaking the API Key');
}

$pluginContext = new PluginContext(
    new PluginManifest('faq_block', 'FAQ', '1.0.0', 'Daiying', '1.0.0', '8.3.0', 'plugin.php', 'api', [], [], [], 'plugin', false, [], [], ''),
    new EventDispatcher(),
    new BlockRegistry(),
    new PluginDataStore($pdo, 'faq_block'),
    null,
    null,
    null,
    false,
    '',
    new AiService($pdo, $settings, new CoreAiMockClient()),
);
$check($pluginContext->ai()->getConfig()['provider'] === 'openai_compatible', 'plugins can access site AI through the stable PluginContext API');

$pdo->exec('ALTER TABLE cms_core_ai_settings ADD COLUMN response_format VARCHAR(64) NOT NULL DEFAULT ""');
$nextRepo = new SiteAiSettingsRepository($pdo, 'core-ai-test-key');
$nextRuntime = $nextRepo->runtimeConfig();
$check($nextRuntime['api_key'] === 'sk-test-site-ai-secret', 'next-version schema additions preserve existing AI credentials');

$rollbackDb = sys_get_temp_dir() . '/daiying-core-ai-rollback-' . bin2hex(random_bytes(4)) . '.sqlite';
$rollbackCopy = $rollbackDb . '.before';
$rollbackPdo = new PDO('sqlite:' . $rollbackDb);
$rollbackPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$rollbackPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
(new SiteAiSettingsRepository($rollbackPdo, 'core-ai-test-key'))->save([
    'enabled' => true,
    'provider' => 'deepseek',
    'base_url' => 'https://api.deepseek.com/v1',
    'model' => 'deepseek-chat',
    'timeout_seconds' => 20,
    'max_tokens' => 1024,
    'temperature' => 0.2,
], 'sk-rollback-secret', false);
copy($rollbackDb, $rollbackCopy);
$rollbackPdo->exec('ALTER TABLE cms_core_ai_settings ADD COLUMN simulated_next_field VARCHAR(64) NOT NULL DEFAULT ""');
$rollbackPdo = null;
copy($rollbackCopy, $rollbackDb);
$restoredPdo = new PDO('sqlite:' . $rollbackDb);
$restoredPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$restoredPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$restoredRuntime = (new SiteAiSettingsRepository($restoredPdo, 'core-ai-test-key'))->runtimeConfig();
$check($restoredRuntime['api_key'] === 'sk-rollback-secret' && $restoredRuntime['provider'] === 'deepseek', 'upgrade rollback does not corrupt existing AI configuration');
@unlink($rollbackDb);
@unlink($rollbackCopy);

$legacyPdo = new PDO('sqlite::memory:');
$legacyPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$legacyPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$legacyRepo = new SiteAiSettingsRepository($legacyPdo, 'core-ai-test-key');
$legacyRepo->save([
    'enabled' => true,
    'provider' => 'deepseek',
    'base_url' => 'https://api.deepseek.com/v1',
    'model' => 'deepseek-chat',
    'timeout_seconds' => 20,
    'max_tokens' => 1024,
    'temperature' => 0.2,
], 'sk-legacy-deepseek-secret', false);
$legacyCiphertext = (string) $legacyPdo->query('SELECT api_key_ciphertext FROM cms_core_ai_settings WHERE id = 1')->fetchColumn();
$legacyPdo->exec('CREATE TABLE legacy_ai_settings AS SELECT id, enabled, provider, base_url, model, timeout_seconds, max_tokens, temperature, api_key_ciphertext, created_at, updated_at FROM cms_core_ai_settings');
$legacyPdo->exec('DROP TABLE cms_core_ai_settings');
$legacyPdo->exec('ALTER TABLE legacy_ai_settings RENAME TO cms_core_ai_settings');
$presetMigration->up($legacyPdo);
$legacyRuntime = (new SiteAiSettingsRepository($legacyPdo, 'core-ai-test-key'))->runtimeConfig();
$check($legacyRuntime['api_key'] === 'sk-legacy-deepseek-secret' && $legacyRuntime['provider'] === 'deepseek' && $legacyRuntime['adapter'] === 'openai_compatible', 'old DeepSeek settings migrate to preset adapters without losing API Key');
$check((string) $legacyPdo->query('SELECT api_key_ciphertext FROM cms_core_ai_settings WHERE id = 1')->fetchColumn() === $legacyCiphertext, 'provider preset migration does not rewrite existing encrypted API Key');

$skippedVersionPdo = new PDO('sqlite::memory:');
$skippedVersionPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$skippedVersionPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$presetMigration->up($skippedVersionPdo);
$skippedVersionCurrent = (new SiteAiSettingsRepository($skippedVersionPdo, 'core-ai-test-key'))->current();
$check($skippedVersionCurrent['enabled'] === false && $skippedVersionCurrent['adapter'] === 'openai_compatible', 'preset migration is safe when an older site has not run the first AI migration yet');

$geminiRefreshPdo = new PDO('sqlite::memory:');
$geminiRefreshPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$geminiRefreshPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
(new MigrationRunner($geminiRefreshPdo, [$migration, $presetMigration]))->run();
$geminiRefreshRepo = new SiteAiSettingsRepository($geminiRefreshPdo, 'core-ai-test-key');
$geminiRefreshRepo->save([
    'enabled' => true,
    'provider' => 'gemini',
    'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
    'model' => 'gemini-2.5-flash',
    'timeout_seconds' => 30,
    'max_tokens' => 1024,
    'temperature' => 0.7,
], 'sk-gemini-refresh-secret', false);
$geminiRefreshMigration = require CMS_ROOT . '/system/migrations/2026_09_09_000001_core_ai_gemini_model_refresh.php';
$geminiRefreshMigration->up($geminiRefreshPdo);
$geminiRefreshRuntime = (new SiteAiSettingsRepository($geminiRefreshPdo, 'core-ai-test-key'))->runtimeConfig();
$check($geminiRefreshRuntime['model'] === 'gemini-3.6-flash' && $geminiRefreshRuntime['api_key'] === 'sk-gemini-refresh-secret', 'Gemini model refresh migrates the obsolete default without losing the API Key');

$root = sys_get_temp_dir() . '/daiying-core-ai-' . bin2hex(random_bytes(4));
mkdir($root . '/config', 0755, true);
file_put_contents($root . '/config/app.php', "<?php\nreturn ['database'=>['dsn'=>'sqlite::memory:'], 'security'=>['encryption_key'=>'core-ai-test-key']];\n");
$facadeService = AI::forSite($root, $pdo, new CoreAiMockClient());
$check($facadeService->getConfig()['provider'] === 'openai_compatible', 'AI facade can create a site AI service for plugin and Core callers');

if ($failures > 0) {
    echo 'Core AI settings tests failed: ' . $failures . PHP_EOL;
    exit(1);
}

echo 'Core AI settings tests passed.' . PHP_EOL;
