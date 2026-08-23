<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

use Closure;

final class PluginRouteDefinition
{
    public function __construct(
        public readonly string $pluginId,
        public readonly string $method,
        public readonly string $path,
        callable $handler,
        public readonly ?string $capability,
        public readonly bool $admin,
        public readonly bool $csrf,
    ) {
        $this->handler = Closure::fromCallable($handler);
    }

    public readonly Closure $handler;
}
