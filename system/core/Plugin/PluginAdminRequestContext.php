<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

final class PluginAdminRequestContext
{
    /** @param list<string> $capabilities */
    public function __construct(
        public readonly string $pluginId,
        public readonly int $authenticatedAdminId,
        public readonly array $capabilities,
        public readonly string $correlationId,
        public readonly string $requestId,
        public readonly string $clientIp,
    ) {
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }
}
