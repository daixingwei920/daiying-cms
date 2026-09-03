<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class ExtensionDependency
{
    public function __construct(
        public readonly string $extensionId,
        public readonly string $type,
        public readonly string $constraint,
        public readonly bool $optional = false,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $extensionId = (string) ($data['extension_id'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9]*(?:[_-][a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:[_-][a-z0-9]+)*){0,4}$/', $extensionId)) {
            throw new MarketException('Invalid dependency extension id.');
        }

        $type = (string) ($data['type'] ?? 'plugin');
        if (!in_array($type, ['plugin', 'theme'], true)) {
            throw new MarketException('Dependency type must be plugin or theme.');
        }

        return new self(
            $extensionId,
            $type,
            (string) ($data['version'] ?? '*'),
            (bool) ($data['optional'] ?? false),
        );
    }
}
