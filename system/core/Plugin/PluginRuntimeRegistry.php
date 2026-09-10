<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

final class PluginRuntimeRegistry
{
    /** @var array<string,PluginRouteDefinition> */
    private array $routes = [];
    /** @var list<PluginMenuItem> */
    private array $menus = [];
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
