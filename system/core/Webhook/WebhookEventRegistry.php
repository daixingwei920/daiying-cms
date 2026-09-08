<?php

declare(strict_types=1);

namespace Cms\Core\Webhook;

final class WebhookEventRegistry
{
    /** @var array<string,array{id:string,version:string,label:string,owner:string}> */
    private static array $events = [
        'content.created' => ['id' => 'content.created', 'version' => '1.0', 'label' => 'Content created', 'owner' => 'core'],
        'content.updated' => ['id' => 'content.updated', 'version' => '1.0', 'label' => 'Content updated', 'owner' => 'core'],
        'content.published' => ['id' => 'content.published', 'version' => '1.0', 'label' => 'Content published', 'owner' => 'core'],
        'content.deleted' => ['id' => 'content.deleted', 'version' => '1.0', 'label' => 'Content deleted', 'owner' => 'core'],
        'media.created' => ['id' => 'media.created', 'version' => '1.0', 'label' => 'Media created', 'owner' => 'core'],
        'user.created' => ['id' => 'user.created', 'version' => '1.0', 'label' => 'User created', 'owner' => 'core'],
        'plugin.activated' => ['id' => 'plugin.activated', 'version' => '1.0', 'label' => 'Plugin activated', 'owner' => 'core'],
        'plugin.deactivated' => ['id' => 'plugin.deactivated', 'version' => '1.0', 'label' => 'Plugin deactivated', 'owner' => 'core'],
    ];

    public static function register(string $eventId, string $label, string $version = '1.0', string $owner = 'core'): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $eventId)) {
            throw new WebhookException('Webhook event id is invalid.');
        }
        self::$events[$eventId] = [
            'id' => $eventId,
            'version' => $version !== '' ? $version : '1.0',
            'label' => $label !== '' ? $label : $eventId,
            'owner' => $owner !== '' ? $owner : 'core',
        ];
    }

    /** @return array<string,array{id:string,version:string,label:string,owner:string}> */
    public static function all(): array
    {
        ksort(self::$events);
        return self::$events;
    }
}
