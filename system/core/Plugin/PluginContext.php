<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

use Cms\Core\Ai\AiService;
use Cms\Core\Ai\AiAgentDefinition;
use Cms\Core\Ai\AiAgentRegistry;
use Cms\Core\Ai\AiPromptRegistry;
use Cms\Core\Ai\AiPromptTemplate;
use Cms\Core\Ai\AiProviderInterface;
use Cms\Core\Ai\AiProviderRegistry;
use Cms\Core\Ai\AiToolDefinition;
use Cms\Core\Ai\AiToolRegistry;
use Cms\Core\Cache\CacheInterface;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\Content\CustomFieldDefinition;
use Cms\Core\Content\CustomFieldRegistry;
use Cms\Core\Content\SearchResourceRegistry;
use Cms\Core\Events\EventDispatcher;
use Cms\Core\Extension\ExtensionAssetController;
use Cms\Core\Mail\MailEventRegistry;
use Cms\Core\Mail\MailProviderInterface;
use Cms\Core\Mail\MailProviderRegistry;
use Cms\Core\Mail\MailService;
use Cms\Core\Media\RemoteMediaProviderInterface;
use Cms\Core\Media\RemoteMediaProviderRegistry;
use Cms\Core\Notification\NotificationService;
use Cms\Core\Queue\QueueHandlerRegistry;
use Cms\Core\Queue\QueueService;
use Cms\Core\Scheduler\ScheduledTask;
use Cms\Core\Scheduler\SchedulerService;
use Cms\Core\Scheduler\SchedulerTaskRegistry;
use Cms\Core\Seo\SeoExtensionRegistry;
use Cms\Core\Webhook\WebhookEventRegistry;
use Cms\Core\Webhook\WebhookService;
use PDO;

final class PluginContext
{
    public function __construct(
        public readonly PluginManifest $manifest,
        private readonly EventDispatcher $events,
        private readonly BlockRegistry $blocks,
        private readonly PluginDataStore $data,
        private readonly ?PDO $pdo,
        private readonly ?PluginRuntimeRegistry $runtime = null,
        private readonly ?PluginSecretStore $secrets = null,
        private readonly bool $trustedDatabaseAccess = false,
        private readonly string $pluginRoot = '',
        private readonly ?AiService $ai = null,
        private readonly ?MailService $mail = null,
        private readonly ?QueueService $queue = null,
        private readonly ?CacheInterface $cache = null,
        private readonly ?WebhookService $webhooks = null,
        private readonly ?ContentTypeRegistry $contentTypes = null,
        private readonly ?CustomFieldRegistry $customFields = null,
        private readonly ?SchedulerService $scheduler = null,
        private readonly ?NotificationService $notifications = null,
    ) {
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->manifest->capabilities, true);
    }

    /** @param callable(object): void $listener */
    public function listen(string $eventName, callable $listener): void
    {
        $this->events->listen($eventName, $listener);
    }

    public function registerBlock(string $type, string $label): void
    {
        if (!$this->hasCapability('blocks.register')) {
            throw new PluginException('Plugin does not declare blocks.register capability.');
        }

        $this->blocks->register($this->manifest->id, $type, $label);
    }

    public function data(): PluginDataStore
    {
        return $this->data;
    }

    public function pdo(): PDO
    {
        if (!$this->trustedDatabaseAccess || $this->pdo === null) {
            throw new PluginException('Raw database access is only available to trusted bundled plugins.');
        }
        return $this->pdo;
    }

    /** @param callable $handler */
    public function frontRoute(string $method, string $path, callable $handler, ?string $capability = null, bool $csrf = false): void
    {
        $this->runtime()->route($this->manifest->id, $method, $path, $handler, $capability, false, $csrf);
    }

    /** @param callable $handler */
    public function adminRoute(string $method, string $path, callable $handler, ?string $capability = null, bool $csrf = true): void
    {
        $this->runtime()->route($this->manifest->id, $method, $path, $handler, $capability, true, $csrf);
    }

    public function adminMenu(string $label, string $path, ?string $capability = null, array $metadata = []): void
    {
        $this->runtime()->adminMenu($this->manifest->id, $label, $path, $capability, $metadata);
    }

    public function assetUrl(string $relativePath): string
    {
        $version = $this->manifest->version;
        if ($this->pluginRoot !== '') {
            try {
                $path = ExtensionAssetController::normalizeRelativePath($relativePath);
                $file = realpath(rtrim($this->pluginRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path);
                if (is_string($file) && is_file($file)) {
                    $version .= '-' . substr(hash_file('sha256', $file), 0, 12);
                }
            } catch (\Throwable) {
                $version = $this->manifest->version;
            }
        }

        return ExtensionAssetController::url('plugin', $this->manifest->id, $relativePath, $version);
    }

    public function adminStyle(string $relativePath): string
    {
        return '<link rel="stylesheet" href="' . htmlspecialchars($this->assetUrl($relativePath), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
    }

    public function adminScript(string $relativePath, bool $defer = true): string
    {
        return '<script src="' . htmlspecialchars($this->assetUrl($relativePath), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' . ($defer ? ' defer' : '') . '></script>';
    }

    public function secrets(): PluginSecretStore
    {
        if ($this->secrets === null) {
            throw new PluginException('Plugin secret store is not available.');
        }

        return $this->secrets;
    }

    public function ai(): AiService
    {
        if ($this->ai === null) {
            throw new PluginException('Site AI service is not available.');
        }

        return $this->ai;
    }

    public function mail(): MailService
    {
        if ($this->mail === null) {
            throw new PluginException('Site mail service is not available.');
        }

        return $this->mail;
    }

    public function queue(): QueueService
    {
        if ($this->queue === null) {
            throw new PluginException('Queue service is not available.');
        }

        return $this->queue;
    }

    public function scheduler(): SchedulerService
    {
        if ($this->scheduler === null) {
            throw new PluginException('Scheduler service is not available.');
        }

        return $this->scheduler;
    }

    public function cache(): CacheInterface
    {
        if ($this->cache === null) {
            throw new PluginException('Cache service is not available.');
        }

        return $this->cache;
    }

    public function webhooks(): WebhookService
    {
        if ($this->webhooks === null) {
            throw new PluginException('Webhook service is not available.');
        }

        return $this->webhooks;
    }

    public function notifications(): NotificationService
    {
        if ($this->notifications === null) {
            throw new PluginException('Notification service is not available.');
        }

        return $this->notifications->forPlugin($this->manifest->id);
    }

    public function registerMailProvider(MailProviderInterface $provider): void
    {
        if (!$this->hasCapability('mail.provider')) {
            throw new PluginException('Plugin does not declare mail.provider capability.');
        }

        MailProviderRegistry::register($provider);
    }

    public function registerAiProvider(AiProviderInterface $provider): void
    {
        if (!$this->hasCapability('ai.provider')) {
            throw new PluginException('Plugin does not declare ai.provider capability.');
        }

        AiProviderRegistry::register($provider);
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $handler */
    public function registerAiTool(AiToolDefinition $definition, callable $handler): void
    {
        if (!$this->hasCapability('ai.tool')) {
            throw new PluginException('Plugin does not declare ai.tool capability.');
        }

        AiToolRegistry::register($definition, $handler);
    }

    public function registerAiAgent(AiAgentDefinition $agent): void
    {
        if (!$this->hasCapability('ai.agent')) {
            throw new PluginException('Plugin does not declare ai.agent capability.');
        }

        AiAgentRegistry::register($agent);
    }

    public function registerAiPrompt(AiPromptTemplate $prompt): void
    {
        if (!$this->hasCapability('ai.prompt')) {
            throw new PluginException('Plugin does not declare ai.prompt capability.');
        }

        AiPromptRegistry::register($prompt);
    }

    public function registerRemoteMediaProvider(RemoteMediaProviderInterface $provider): void
    {
        if (!$this->hasCapability('storage.plugin')) {
            throw new PluginException('Plugin does not declare storage.plugin capability.');
        }

        RemoteMediaProviderRegistry::register($provider);
    }

    /** @param callable(array<string,mixed>): void $handler */
    public function registerQueueHandler(string $type, callable $handler): void
    {
        if (!$this->hasCapability('queue.register') && !$this->hasCapability('cron.register')) {
            throw new PluginException('Plugin does not declare queue.register capability.');
        }

        QueueHandlerRegistry::register($type, $handler);
    }

    /** @param array<string,mixed> $payload @param callable(array<string,mixed>): void $handler */
    public function registerScheduledTask(string $taskId, int $intervalSeconds, callable $handler, array $payload = []): void
    {
        if (!$this->hasCapability('scheduler.register') && !$this->hasCapability('cron.register')) {
            throw new PluginException('Plugin does not declare scheduler.register capability.');
        }
        if ($this->scheduler === null) {
            throw new PluginException('Scheduler service is not available.');
        }

        SchedulerTaskRegistry::register($taskId, $handler);
        $this->scheduler->register(new ScheduledTask($taskId, $this->manifest->id, $intervalSeconds, $payload));
    }

    public function registerWebhookEvent(string $eventId, string $label, string $version = '1.0'): void
    {
        if (!$this->hasCapability('webhook.register')) {
            throw new PluginException('Plugin does not declare webhook.register capability.');
        }

        WebhookEventRegistry::register($eventId, $label, $version, $this->manifest->id);
    }

    /** @param list<string> $fields @param array<string,mixed> $options */
    public function registerContentType(string $id, string $name, array $fields, array $options = []): void
    {
        if (!$this->hasCapability('content.type')) {
            throw new PluginException('Plugin does not declare content.type capability.');
        }
        if ($this->contentTypes === null) {
            throw new PluginException('Content type registry is not available.');
        }

        $this->contentTypes->register($id, $name, $fields, $options + ['owner' => $this->manifest->id]);
    }

    public function registerCustomField(string $contentType, CustomFieldDefinition $field): void
    {
        if (!$this->hasCapability('content.field')) {
            throw new PluginException('Plugin does not declare content.field capability.');
        }
        if ($this->customFields === null) {
            throw new PluginException('Custom field registry is not available.');
        }

        $this->customFields->register($contentType, $field);
    }

    public function registerSearchResource(string $id, string $label): void
    {
        if (!$this->hasCapability('search.register')) {
            throw new PluginException('Plugin does not declare search.register capability.');
        }

        SearchResourceRegistry::register($id, $label, $this->manifest->id);
    }

    /** @param callable(array<string,mixed>): array<string,mixed> $provider */
    public function registerSeoJsonLd(string $id, callable $provider): void
    {
        if (!$this->hasCapability('seo.extend')) {
            throw new PluginException('Plugin does not declare seo.extend capability.');
        }

        SeoExtensionRegistry::registerJsonLd($id, $provider);
    }

    /** @param callable(array<string,mixed>): array<string,string> $provider */
    public function registerSeoMeta(string $id, callable $provider): void
    {
        if (!$this->hasCapability('seo.extend')) {
            throw new PluginException('Plugin does not declare seo.extend capability.');
        }

        SeoExtensionRegistry::registerMeta($id, $provider);
    }

    /** @param list<string> $variables */
    public function registerMailEvent(string $eventId, string $label, array $variables = []): void
    {
        if (!$this->hasCapability('mail.event')) {
            throw new PluginException('Plugin does not declare mail.event capability.');
        }

        MailEventRegistry::register($eventId, $label, $variables, $this->manifest->id);
    }

    private function runtime(): PluginRuntimeRegistry
    {
        if ($this->runtime === null) {
            throw new PluginException('Plugin runtime registry is not available.');
        }

        return $this->runtime;
    }
}
