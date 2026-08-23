<?php

declare(strict_types=1);

namespace Cms\Core\Theme;

final class ThemeManifest
{
    /** @param list<string> $contentTypes @param list<string> $recommendedPlugins @param list<string> $requiredPlugins @param array<string, mixed> $settingsSchema */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $version,
        public readonly string $author,
        public readonly string $coreMin,
        public readonly string $coreMax,
        public readonly array $contentTypes,
        public readonly array $recommendedPlugins,
        public readonly array $requiredPlugins,
        public readonly array $settingsSchema,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['theme_id', 'name', 'version', 'author', 'core'] as $key) {
            if (!isset($data[$key])) {
                throw new ThemeException('Theme manifest missing key: ' . $key);
            }
        }

        $id = (string) $data['theme_id'];
        if (!preg_match('/^[a-z][a-z0-9_]{2,63}$/', $id)) {
            throw new ThemeException('Invalid theme id.');
        }

        $core = is_array($data['core']) ? $data['core'] : [];
        $contentTypes = $data['content_types'] ?? [];
        if (!is_array($contentTypes)) {
            $contentTypes = [];
        }

        $recommendedPlugins = $data['recommended_plugins'] ?? [];
        if (!is_array($recommendedPlugins)) {
            $recommendedPlugins = [];
        }

        $requiredPlugins = $data['required_plugins'] ?? [];
        if (!is_array($requiredPlugins)) {
            $requiredPlugins = [];
        }

        $settingsSchema = $data['settings_schema'] ?? [];
        if (!is_array($settingsSchema)) {
            $settingsSchema = [];
        }

        return new self(
            $id,
            (string) $data['name'],
            (string) $data['version'],
            (string) $data['author'],
            (string) ($core['min'] ?? '1.0.0'),
            (string) ($core['max'] ?? '1.x'),
            array_values(array_map('strval', $contentTypes)),
            self::cleanList($recommendedPlugins),
            self::cleanList($requiredPlugins),
            $settingsSchema,
        );
    }

    /** @return list<string> */
    private static function cleanList(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $items), static fn (string $item): bool => $item !== ''));
    }
}
