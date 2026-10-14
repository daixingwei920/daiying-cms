<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

use Cms\Core\Routing\BasePath;

final class PluginRuntimeRegistry
{
    /** @var array<string,PluginRouteDefinition> */
    private array $routes = [];
    private array $homepages = [];
    /** @var list<PluginMenuItem> */
    private array $menus = [];
    /** @var list<FrontendExtensionAsset> */
    private array $frontendAssets = [];
    /** @var list<array{plugin_id:string,key:string,callback:callable():string}> */
    private array $frontendBodyEndCallbacks = [];
    /** @var list<string> */
    private array $reservedPrefixes = ['/admin/login', '/recovery', '/diagnostics', '/health', '/install', '/admin/update'];

    /** @param callable $handler */
    public function route(string $pluginId, string $method, string $path, callable $handler, ?string $capability = null, bool $admin = false, bool $csrf = false): void
    {
        $method = strtoupper($method);
        $path = $this->normalizePath($path);
        $this->assertRouteAllowed($path);
        $key = $method . ' ' . $path;
        if (isset($this->routes[$key])) {
            throw new PluginException('Plugin route conflicts with an existing plugin route.');
        }
        $this->routes[$key] = new PluginRouteDefinition($pluginId, $method, $path, $handler, $capability, $admin, $csrf);
    }

    public function adminMenu(string $pluginId, string $label, string $path, ?string $capability = null, array $metadata = []): void
    {
        $path = $this->normalizePath($path);
        $this->assertRouteAllowed($path);
        $section = trim((string) ($metadata['section'] ?? ''));
        $icon = trim((string) ($metadata['icon'] ?? 'plugin'));
        $sortOrder = (int) ($metadata['sort_order'] ?? $metadata['sortOrder'] ?? 100);
        $breadcrumbParent = trim((string) ($metadata['breadcrumb_parent'] ?? $metadata['breadcrumbParent'] ?? ''));
        $badge = trim((string) ($metadata['badge'] ?? ''));
        $this->menus[] = new PluginMenuItem(
            $pluginId,
            $label,
            $path,
            $capability,
            $section,
            $icon !== '' ? $icon : 'plugin',
            $sortOrder,
            $breadcrumbParent,
            $badge,
        );
    }

    /** @param array<string,mixed> $attributes */
    public function frontendAsset(string $pluginId, string $type, string $url, string $key = '', array $attributes = []): void
    {
        if (!in_array($type, ['script', 'style'], true)) {
            throw new PluginException('Invalid frontend asset type.');
        }
        $policyPath = BasePath::stripCurrent($url);
        $query = parse_url($url, PHP_URL_QUERY);
        $policyUrl = $policyPath . ($query !== null && $query !== false && $query !== '' ? '?' . $query : '');
        if (!str_starts_with($policyUrl, '/extension-assets/plugin/' . rawurlencode($pluginId) . '?')) {
            throw new PluginException('Plugin frontend assets must use the Core extension asset endpoint.');
        }
        $key = $key !== '' ? $pluginId . ':' . $key : $type . ':' . $url;
        $this->frontendAssets[] = new FrontendExtensionAsset($pluginId, $type, $url, $key, $attributes);
    }

    /** @param callable():string $callback */
    public function frontendBodyEnd(string $pluginId, callable $callback, string $key = ''): void
    {
        $this->frontendBodyEndCallbacks[] = [
            'plugin_id' => $pluginId,
            'key' => $key !== '' ? $pluginId . ':' . $key : $pluginId . ':body_end:' . count($this->frontendBodyEndCallbacks),
            'callback' => $callback,
        ];
    }

    /** @return list<PluginRouteDefinition> */
    public function routes(): array
    {
        return array_values($this->routes);
    }

    /** @return list<PluginMenuItem> */
    public function menus(): array
    {
        return $this->menus;
    }

    /** @return list<FrontendExtensionAsset> */
    public function frontendAssets(): array
    {
        return $this->frontendAssets;
    }

    /** @return list<array{plugin_id:string,key:string,callback:callable():string}> */
    public function frontendBodyEndCallbacks(): array
    {
        return $this->frontendBodyEndCallbacks;
    }

    public function homepage(string $pluginId, string $key, string $label, callable $renderer): string
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $key)) throw new PluginException('Invalid homepage provider key.');
        $id = $pluginId . ':' . $key;
        if (isset($this->homepages[$id])) throw new PluginException('Duplicate homepage provider.');
        $this->homepages[$id] = ['owner' => $pluginId, 'label' => $label, 'renderer' => $renderer];
        return $id;
    }

    public function homepages(): array { return $this->homepages; }

    public function removeHomepageProviders(string $pluginId): void
    {
        foreach ($this->homepages as $id => $p) if ($p['owner'] === $pluginId) unset($this->homepages[$id]);
    }

    private function assertRouteAllowed(string $path): void
    {
        foreach ($this->reservedPrefixes as $reserved) {
            if ($path === $reserved || str_starts_with($path, $reserved . '/')) {
                throw new PluginException('Plugin route attempts to override a reserved Core route.');
            }
        }
        if ($path === '/' || $path === '/{slug}' || str_starts_with($path, '/api/market') || str_starts_with($path, '/admin/market')) {
            throw new PluginException('Plugin route conflicts with reserved CMS routes.');
        }
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        return preg_replace('#/+#', '/', $path) ?: '/';
    }
}
