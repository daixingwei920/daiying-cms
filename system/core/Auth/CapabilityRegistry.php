<?php

declare(strict_types=1);

namespace Cms\Core\Auth;

final class CapabilityRegistry
{
    /** @var array<string,string> */
    private static array $capabilities = [
        'content.create' => 'Create content',
        'content.edit' => 'Edit content',
        'content.publish' => 'Publish content',
        'content.delete' => 'Delete content',
        'media.manage' => 'Manage media',
        'plugins.manage' => 'Manage plugins',
        'themes.manage' => 'Manage themes',
        'settings.manage' => 'Manage settings',
        'users.manage' => 'Manage users',
    ];

    public static function register(string $capability, string $label): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $capability)) {
            throw new \InvalidArgumentException('Capability name is invalid.');
        }
        self::$capabilities[$capability] = $label !== '' ? $label : $capability;
    }

    /** @return array<string,string> */
    public static function all(): array
    {
        ksort(self::$capabilities);
        return self::$capabilities;
    }
}
