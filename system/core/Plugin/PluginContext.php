<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

use Cms\Core\Events\EventDispatcher;
use Cms\Core\Extension\ExtensionAssetController;
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

    public function adminMenu(string $label, string $path, ?string $capability = null): void
    {
        $this->runtime()->adminMenu($this->manifest->id, $label, $path, $capability);
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

    private function runtime(): PluginRuntimeRegistry
    {
        if ($this->runtime === null) {
            throw new PluginException('Plugin runtime registry is not available.');
        }

        return $this->runtime;
    }
}
