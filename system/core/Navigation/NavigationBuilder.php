<?php

declare(strict_types=1);

namespace Cms\Core\Navigation;

use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use PDO;
use Throwable;

final class NavigationBuilder
{
    /** @return list<array{label:string,url:string,type:string,enabled:bool,requires_plugin:string}> */
    public static function build(Settings $settings, ?PDO $pdo = null, string $rootPath = ''): array
    {
        $enabledPlugins = self::enabledPlugins($settings, $pdo);
        $configured = $settings->get('navigation.primary', null);
        if (is_array($configured)) {
            $items = self::sanitizeItems($configured, $enabledPlugins);
            if ($items !== []) {
                return $items;
            }
        }

        $items = [
            ['label' => '首页', 'url' => '/', 'type' => 'home', 'enabled' => true, 'requires_plugin' => ''],
            ['label' => '文章', 'url' => '/articles', 'type' => 'articles', 'enabled' => true, 'requires_plugin' => ''],
        ];
        return array_merge($items, self::pluginNavigationDefaults($rootPath, $enabledPlugins));
    }

    /** @return list<array{label:string,url:string,type:string,enabled:bool,requires_plugin:string,available:bool}> */
    public static function adminItems(Settings $settings, ?PDO $pdo = null, string $rootPath = ''): array
    {
        $enabledPlugins = self::enabledPlugins($settings, $pdo);
        $installedPlugins = self::installedPlugins($settings, $pdo);
        $configured = $settings->get('navigation.primary', []);
        $items = is_array($configured) ? self::sanitizeItems($configured, $enabledPlugins, false) : [];
        if ($items === []) {
            $items = [
                ['label' => '首页', 'url' => '/', 'type' => 'home', 'enabled' => true, 'requires_plugin' => ''],
                ['label' => '文章', 'url' => '/articles', 'type' => 'articles', 'enabled' => true, 'requires_plugin' => ''],
            ];
            $items = array_merge($items, self::pluginNavigationDefaults($rootPath, $installedPlugins));
        }

        return array_map(static function (array $item) use ($enabledPlugins): array {
            $plugin = (string) ($item['requires_plugin'] ?? '');
            $item['available'] = $plugin === '' || in_array($plugin, $enabledPlugins, true);
            return $item;
        }, $items);
    }

    /** @return list<array{label:string,url:string,type:string,enabled:bool,requires_plugin:string}> */
    public static function sanitizeForSave(mixed $input): array
    {
        return self::sanitizeItems(is_array($input) ? $input : [], [], false);
    }

    /** @return list<array{label:string,url:string,type:string,enabled:bool,requires_plugin:string}> */
    public static function pluginItems(Settings $settings, ?PDO $pdo = null, string $rootPath = '', bool $includeDisabledInstalled = true): array
    {
        $pluginIds = $includeDisabledInstalled ? self::installedPlugins($settings, $pdo) : self::enabledPlugins($settings, $pdo);

        return self::pluginNavigationDefaults($rootPath, $pluginIds);
    }

    /** @param list<string> $enabledPlugins @return list<array{label:string,url:string,type:string,enabled:bool,requires_plugin:string}> */
    private static function sanitizeItems(array $items, array $enabledPlugins, bool $publicOnly = true): array
    {
        $clean = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['label'] ?? ''));
            $url = self::safeUrl((string) ($item['url'] ?? ''));
            $type = preg_replace('/[^a-z0-9_.-]/', '', strtolower((string) ($item['type'] ?? 'custom'))) ?: 'custom';
            $enabled = in_array(($item['enabled'] ?? true), [true, 1, '1', 'on', 'true'], true);
            $requires = preg_replace('/[^a-z0-9_.-]/', '', strtolower((string) ($item['requires_plugin'] ?? ''))) ?: '';
            if ($label === '' || $url === '') {
                continue;
            }
            if ($publicOnly && (!$enabled || ($requires !== '' && !in_array($requires, $enabledPlugins, true)))) {
                continue;
            }
            $clean[] = [
                'label' => function_exists('mb_substr') ? mb_substr($label, 0, 60, 'UTF-8') : substr($label, 0, 60),
                'url' => $url,
                'type' => $type,
                'enabled' => $enabled,
                'requires_plugin' => $requires,
            ];
        }

        return $clean;
    }

    private static function safeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, '/')) {
            return '/' . ltrim(preg_replace('#/{2,}#', '/', $url) ?: '', '/');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return '';
        }

        return $url;
    }

    /** @param list<string> $pluginIds @return list<array{label:string,url:string,type:string,enabled:bool,requires_plugin:string}> */
    private static function pluginNavigationDefaults(string $rootPath, array $pluginIds): array
    {
        if ($rootPath === '' || $pluginIds === []) {
            return [];
        }
        $items = [];
        foreach ($pluginIds as $pluginId) {
            $manifestFile = rtrim($rootPath, '/') . '/content/plugins/' . $pluginId . '/plugin.json';
            if (!is_file($manifestFile)) {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($manifestFile), true);
            $declared = is_array($decoded) ? ($decoded['front_navigation'] ?? []) : [];
            if (!is_array($declared)) {
                continue;
            }
            foreach ($declared as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $item['requires_plugin'] = $pluginId;
                $item['enabled'] = true;
                $items[] = $item;
            }
        }

        return self::sanitizeItems($items, $pluginIds, false);
    }

    /** @return list<string> */
    private static function enabledPlugins(Settings $settings, ?PDO $pdo): array
    {
        try {
            $pdo ??= ConnectionFactory::make($settings);
            $stmt = $pdo->query("SELECT plugin_id FROM cms_plugins WHERE status = 'Enabled'");
            return array_map(static fn (array $row): string => (string) $row['plugin_id'], $stmt->fetchAll());
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<string> */
    private static function installedPlugins(Settings $settings, ?PDO $pdo): array
    {
        try {
            $pdo ??= ConnectionFactory::make($settings);
            $stmt = $pdo->query('SELECT plugin_id FROM cms_plugins');
            return array_map(static fn (array $row): string => (string) $row['plugin_id'], $stmt->fetchAll());
        } catch (Throwable) {
            return [];
        }
    }
}
