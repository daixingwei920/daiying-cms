<?php

declare(strict_types=1);

namespace Cms\Core\Support;

use Cms\Core\Foundation\FoundationVersions;

final class PublicApiRegistry
{
    public const CONTRACT_VERSION = FoundationVersions::CORE_API;

    /** @return list<array{id:string,version:string,class:string,stability:string,summary:string,capabilities:list<string>}> */
    public static function contracts(): array
    {
        return [
            [
                'id' => 'content.repository',
                'version' => self::CONTRACT_VERSION,
                'class' => 'Cms\\Core\\Content\\ContentRepository',
                'stability' => 'stable',
                'summary' => 'Create, update, list and read CMS content through the Core content model.',
                'capabilities' => ['content.read', 'content.write'],
            ],
            [
                'id' => 'media.library',
                'version' => self::CONTRACT_VERSION,
                'class' => 'Cms\\Core\\Media\\MediaLibrary',
                'stability' => 'stable',
                'summary' => 'Register, deduplicate, read and localize managed media records.',
                'capabilities' => ['media.read', 'media.write'],
            ],
            [
                'id' => 'content.revisions',
                'version' => self::CONTRACT_VERSION,
                'class' => 'Cms\\Core\\Content\\ContentRevisionRepository',
                'stability' => 'stable',
                'summary' => 'Store and restore revision snapshots for Core content.',
                'capabilities' => ['content.read', 'content.write'],
            ],
            [
                'id' => 'content.autosave',
                'version' => self::CONTRACT_VERSION,
                'class' => 'Cms\\Core\\Content\\ContentAutosaveRepository',
                'stability' => 'stable',
                'summary' => 'Store recoverable editor autosaves without publishing drafts.',
                'capabilities' => ['content.write'],
            ],
            [
                'id' => 'content.fields',
                'version' => self::CONTRACT_VERSION,
                'class' => 'Cms\\Core\\Content\\CustomFieldRegistry',
                'stability' => 'stable',
                'summary' => 'Register typed custom fields for content types.',
                'capabilities' => ['content.field'],
            ],
            [
                'id' => 'content.search',
                'version' => self::CONTRACT_VERSION,
                'class' => 'Cms\\Core\\Content\\SearchResourceRegistry',
                'stability' => 'stable',
                'summary' => 'Register searchable resources owned by Core or plugins.',
                'capabilities' => ['search.register'],
            ],
            [
                'id' => 'seo.extensions',
                'version' => self::CONTRACT_VERSION,
                'class' => 'Cms\\Core\\Seo\\SeoExtensionRegistry',
                'stability' => 'stable',
                'summary' => 'Register JSON-LD and meta extension providers.',
                'capabilities' => ['seo.extend'],
            ],
            [
                'id' => 'storage.provider',
                'version' => FoundationVersions::STORAGE_API,
                'class' => 'Cms\\Core\\Media\\MediaStorageProviderV1Interface',
                'stability' => 'stable',
                'summary' => 'Provide versioned media storage capabilities for upload, read, delete, metadata, URL and stream operations.',
                'capabilities' => ['storage.plugin', 'media.read', 'media.write'],
            ],
            [
                'id' => 'remote.media.provider',
                'version' => FoundationVersions::STORAGE_API,
                'class' => 'Cms\\Core\\Media\\RemoteMediaProviderV1Interface',
                'stability' => 'stable',
                'summary' => 'Expose external media providers through a versioned provider contract with health and capability metadata.',
                'capabilities' => ['storage.plugin', 'network.external'],
            ],
            [
                'id' => 'mail.service',
                'version' => self::CONTRACT_VERSION,
                'class' => 'Cms\\Core\\Mail\\MailService',
                'stability' => 'stable',
                'summary' => 'Send, template, queue and test site mail through registered mail providers.',
                'capabilities' => ['mail.provider', 'mail.event'],
            ],
            [
                'id' => 'queue.service',
                'version' => self::CONTRACT_VERSION,
                'class' => 'Cms\\Core\\Queue\\QueueService',
                'stability' => 'stable',
                'summary' => 'Enqueue and process retryable background jobs for Core and plugins.',
                'capabilities' => ['queue.register', 'cron.register'],
            ],
            [
                'id' => 'scheduler.service',
                'version' => self::CONTRACT_VERSION,
                'class' => 'Cms\\Core\\Scheduler\\SchedulerService',
                'stability' => 'stable',
                'summary' => 'Register and run locked retry-aware scheduled tasks for Core and plugins.',
                'capabilities' => ['scheduler.register', 'cron.register'],
            ],
            [
                'id' => 'cache.service',
                'version' => self::CONTRACT_VERSION,
                'class' => 'Cms\\Core\\Cache\\CacheInterface',
                'stability' => 'stable',
                'summary' => 'Provide namespaced cache get/set/delete/clear operations with TTL.',
                'capabilities' => ['cache.use'],
            ],
            [
                'id' => 'webhook.service',
                'version' => self::CONTRACT_VERSION,
                'class' => 'Cms\\Core\\Webhook\\WebhookService',
                'stability' => 'stable',
                'summary' => 'Register versioned events and queue outbound webhook deliveries.',
                'capabilities' => ['webhook.register'],
            ],
            [
                'id' => 'role.capabilities',
                'version' => self::CONTRACT_VERSION,
                'class' => 'Cms\\Core\\Auth\\RoleCapabilityService',
                'stability' => 'stable',
                'summary' => 'Manage role-to-capability grants without hard-coding role names in plugins.',
                'capabilities' => ['users.manage'],
            ],
            [
                'id' => 'payment.service',
                'version' => self::CONTRACT_VERSION,
                'class' => 'Cms\\Core\\Payment\\PaymentService',
                'stability' => 'stable',
                'summary' => 'Create provider payments and handle trusted paid state transitions.',
                'capabilities' => ['payment.create', 'payment.capture'],
            ],
            [
                'id' => 'plugin.context',
                'version' => self::CONTRACT_VERSION,
                'class' => 'Cms\\Core\\Plugin\\PluginContext',
                'stability' => 'stable',
                'summary' => 'Expose the bounded plugin runtime registration surface.',
                'capabilities' => ['blocks.register', 'admin.menu'],
            ],
            [
                'id' => 'theme.runtime',
                'version' => self::CONTRACT_VERSION,
                'class' => 'Cms\\Core\\Theme\\ThemeRuntime',
                'stability' => 'stable',
                'summary' => 'Render templates through the Core theme runtime and fallback chain.',
                'capabilities' => ['theme.render'],
            ],
            [
                'id' => 'theme.template_context',
                'version' => FoundationVersions::THEME_API,
                'class' => 'Cms\\Core\\Theme\\TemplateContext',
                'stability' => 'stable',
                'summary' => 'Expose stable theme settings, assets, menus, media view models, SEO data, pagination and breadcrumbs to templates.',
                'capabilities' => ['theme.render'],
            ],
            [
                'id' => 'theme.view_model',
                'version' => FoundationVersions::THEME_API,
                'class' => 'Cms\\Core\\Theme\\ThemeViewModel',
                'stability' => 'stable',
                'summary' => 'Normalize Core content, media, menu and pagination data for themes without exposing private table shapes.',
                'capabilities' => ['theme.render'],
            ],
            [
                'id' => 'market.client',
                'version' => self::CONTRACT_VERSION,
                'class' => 'Cms\\Core\\Market\\MarketPackageInstaller',
                'stability' => 'stable',
                'summary' => 'Verify authorized Market packages and install reviewed extensions.',
                'capabilities' => ['market.install'],
            ],
        ];
    }

    /** @return array{id:string,version:string,class:string,stability:string,summary:string,capabilities:list<string>}|null */
    public static function contract(string $id): ?array
    {
        foreach (self::contracts() as $contract) {
            if ($contract['id'] === $id) {
                return $contract;
            }
        }

        return null;
    }

    public static function isPublicClass(string $class): bool
    {
        foreach (self::contracts() as $contract) {
            if ($contract['class'] === $class) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(static fn (array $contract): string => $contract['id'], self::contracts());
    }

    /** @return array<string,string> */
    public static function versions(): array
    {
        return FoundationVersions::all();
    }
}
