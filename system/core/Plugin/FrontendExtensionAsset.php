<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

final class FrontendExtensionAsset
{
    /** @param array<string,mixed> $attributes */
    public function __construct(
        public readonly string $pluginId,
        public readonly string $type,
        public readonly string $url,
        public readonly string $key,
        public readonly array $attributes = [],
    ) {
    }
}
