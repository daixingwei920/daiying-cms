<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

final class PluginMenuItem
{
    public function __construct(
        public readonly string $pluginId,
        public readonly string $label,
        public readonly string $path,
        public readonly ?string $capability,
        public readonly string $section = '',
        public readonly string $icon = 'plugin',
        public readonly int $sortOrder = 100,
        public readonly string $breadcrumbParent = '',
        public readonly string $badge = '',
    ) {
    }
}
