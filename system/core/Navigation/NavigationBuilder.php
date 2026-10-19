<?php

declare(strict_types=1);

namespace Cms\Core\Navigation;

use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use PDO;
use InvalidArgumentException;
use Throwable;

final class NavigationBuilder
{
    /** @return list<array{label:string,url:string,type:string,enabled:bool,requires_plugin:string}> */
    public static function build(Settings $settings, ?PDO $pdo = null, string $rootPath = ''): array
    {
        return array_values(array_filter(self::items($settings, $pdo, $rootPath), static fn(array $item): bool => $item['type'] !== 'group'));
    }

    public static function buildTree(Settings $settings, ?PDO $pdo = null, string $rootPath = ''): array
    {
        return self::tree(self::items($settings, $pdo, $rootPath));
    }

    private static function items(Settings $settings, ?PDO $pdo, string $rootPath): array
    {
        $enabledPlugins = self::enabledPlugins($settings, $pdo);
        $configured = $settings->get('navigation.primary', null);
        if (is_array($configured)) {
            $items = self::sanitizeItems($configured, $enabledPlugins);
            return $items;
        }

        $items = [
            ['label' => '首页', 'url' => '/', 'type' => 'home', 'enabled' => true, 'requires_plugin' => ''],
            ['label' => '文章', 'url' => '/articles', 'type' => 'articles', 'enabled' => true, 'requires_plugin' => ''],
        ];
        return self::sanitizeItems(array_merge($items, self::pluginNavigationDefaults($rootPath, $enabledPlugins)), $enabledPlugins);
    }

    /** @return list<array{label:string,url:string,type:string,enabled:bool,requires_plugin:string,available:bool}> */
    public static function adminItems(Settings $settings, ?PDO $pdo = null, string $rootPath = ''): array
    {
        $enabledPlugins = self::enabledPlugins($settings, $pdo);
        $installedPlugins = self::installedPlugins($settings, $pdo);
        $configured = $settings->get('navigation.primary', null);
        $items = is_array($configured) ? self::sanitizeItems($configured, $enabledPlugins, false) : [];
        if (!is_array($configured)) {
            $items = [
                ['label' => '首页', 'url' => '/', 'type' => 'home', 'enabled' => true, 'requires_plugin' => ''],
                ['label' => '文章', 'url' => '/articles', 'type' => 'articles', 'enabled' => true, 'requires_plugin' => ''],
            ];
            $items = self::sanitizeItems(array_merge($items, self::pluginNavigationDefaults($rootPath, $installedPlugins)), $enabledPlugins, false);
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
        if (!is_array($input) || count($input) > 200) {
            throw new InvalidArgumentException('导航必须为数组，且不能超过 200 项。');
        }
        return self::sanitizeItems($input, [], false, true);
    }

    /** @return list<array{label:string,url:string,type:string,enabled:bool,requires_plugin:string}> */
    public static function pluginItems(Settings $settings, ?PDO $pdo = null, string $rootPath = '', bool $includeDisabledInstalled = true): array
    {
        $pluginIds = $includeDisabledInstalled ? self::installedPlugins($settings, $pdo) : self::enabledPlugins($settings, $pdo);

        return self::pluginNavigationDefaults($rootPath, $pluginIds);
    }

    /** Normalize the persisted flat list; hierarchy is additive metadata. */
    private static function sanitizeItems(array $items, array $enabledPlugins, bool $publicOnly = true, bool $strict = false): array
    {
        $clean = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                if ($strict) { throw new InvalidArgumentException('菜单项目格式无效。'); }
                continue;
            }
            $label = trim((string) ($item['label'] ?? ''));
            $type = preg_replace('/[^a-z0-9_.-]/', '', strtolower((string) ($item['type'] ?? 'custom'))) ?: 'custom';
            $url = $type === 'group' ? '' : self::safeUrl((string) ($item['url'] ?? ''));
            if ($label === '' || ($type !== 'group' && $url === '')) {
                if ($strict) { throw new InvalidArgumentException('请填写菜单名称和有效的链接地址。'); }
                continue;
            }
            $id = trim((string) ($item['id'] ?? ''));
            if ($id === '') { $id = 'nav-' . substr(hash('sha256', $index . "\0" . $label . "\0" . $url), 0, 16); }
            if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $id) || isset($clean[$id])) {
                if ($strict) { throw new InvalidArgumentException('菜单标识无效或重复，请刷新页面。'); }
                continue;
            }
            $clean[$id] = [
                'label' => function_exists('mb_substr') ? mb_substr($label, 0, 60, 'UTF-8') : substr($label, 0, 60),
                'url' => $url, 'type' => $type,
                'enabled' => in_array(($item['enabled'] ?? true), [true, 1, '1', 'on', 'true'], true),
                'requires_plugin' => preg_replace('/[^a-z0-9_.-]/', '', strtolower((string) ($item['requires_plugin'] ?? ''))) ?: '',
                'id' => $id, 'parent_id' => trim((string) ($item['parent_id'] ?? '')),
                'sort_order' => max(-100000, min(100000, (int) ($item['sort_order'] ?? $index))),
                'presentation' => ($item['presentation'] ?? '') === 'button' ? 'button' : 'link',
            ];
        }
        foreach ($clean as $id => &$item) {
            $parent = $item['parent_id'];
            if ($parent !== '' && (!isset($clean[$parent]) || $parent === $id || $clean[$parent]['parent_id'] !== '')) {
                if ($strict) { throw new InvalidArgumentException('上级菜单必须是另一个一级菜单；不允许循环或超过二级。'); }
                $item['parent_id'] = '';
            }
            if ($parent !== '' && $item['type'] === 'group') {
                if ($strict) { throw new InvalidArgumentException('分组只能放在一级菜单。'); }
                $item['parent_id'] = '';
            }
        }
        unset($item);
        uasort($clean, static fn(array $a, array $b): int => $a['sort_order'] <=> $b['sort_order']);
        if (!$publicOnly) { return array_values($clean); }
        $available = static fn(array $item): bool => $item['enabled'] && ($item['requires_plugin'] === '' || in_array($item['requires_plugin'], $enabledPlugins, true));
        $visible = [];
        foreach ($clean as $id => $item) {
            if ($available($item) && ($item['parent_id'] === '' || $available($clean[$item['parent_id']]))) { $visible[$id] = $item; }
        }
        foreach ($visible as $id => $item) {
            if ($item['type'] === 'group' && !array_filter($visible, static fn(array $child): bool => $child['parent_id'] === $id)) { unset($visible[$id]); }
        }
        return array_values($visible);
    }

    /** Two-level public tree. The original build() remains a flat list for old consumers. */
    public static function tree(array $items): array
    {
        $roots = [];
        foreach ($items as $item) {
            if (($item['parent_id'] ?? '') === '') { $roots[(string) $item['id']] = $item + ['children' => []]; }
        }
        foreach ($items as $item) {
            $parent = (string) ($item['parent_id'] ?? '');
            if ($parent !== '' && isset($roots[$parent])) { $roots[$parent]['children'][] = $item + ['children' => []]; }
        }
        return array_values($roots);
    }

    private static function safeUrl(string $url): string
    {
        $url = trim($url);
        if (preg_match('/[\\\\\x00-\x20\x7f]/', $url)) { return ''; }
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, '/')) {
            return '/' . ltrim(preg_replace('#/{2,}#', '/', $url) ?: '', '/');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || (!in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host']))) {
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
