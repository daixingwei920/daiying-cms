<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

final class BlockRegistry
{
    /** @var array<string, array<string, string>> */
    private array $blocks = [];

    public function register(string $pluginId, string $type, string $label): void
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{2,63}$/', $type)) {
            throw new PluginException('Invalid block type.');
        }

        $this->blocks[$type] = [
            'plugin_id' => $pluginId,
            'type' => $type,
            'label' => $label,
        ];
    }

    /** @return array<string, array<string, string>> */
    public function all(): array
    {
        ksort($this->blocks);

        return $this->blocks;
    }
}
