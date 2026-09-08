<?php

declare(strict_types=1);

namespace Cms\Core\Foundation;

final class FoundationVersions
{
    public const CORE_API = '1.0';
    public const PLUGIN_API = '1.0';
    public const THEME_API = '1.0';
    public const STORAGE_API = '1.0';
    public const AI_API = '1.0';
    public const REST_API = '1.0';
    public const UPDATE_PROTOCOL = '1.0';

    /** @return array<string,string> */
    public static function all(): array
    {
        return [
            'core_api_version' => self::CORE_API,
            'plugin_api_version' => self::PLUGIN_API,
            'theme_api_version' => self::THEME_API,
            'storage_api_version' => self::STORAGE_API,
            'ai_api_version' => self::AI_API,
            'rest_api_version' => self::REST_API,
            'update_protocol_version' => self::UPDATE_PROTOCOL,
        ];
    }

    /** @return array<string,string> */
    public static function compatibilityPolicy(): array
    {
        return [
            'minor' => 'May add public capabilities without removing or changing published signatures.',
            'deprecation' => 'Deprecated APIs keep a compatibility wrapper until the next major release.',
            'breaking' => 'Breaking changes require a major API version.',
            'plugins' => 'Plugins must depend on public API classes and PluginContext, not private Core tables or services.',
            'storage' => 'Storage providers should implement Storage Provider API v1 or be adapted through the legacy bridge.',
        ];
    }
}
