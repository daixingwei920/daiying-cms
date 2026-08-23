<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

use Cms\Core\Events\EventDispatcher;
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
