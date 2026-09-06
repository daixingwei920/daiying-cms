<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class MarketPackageManifest
{
    /** @param array<string, string> $files @param list<ExtensionDependency> $dependencies */
    public function __construct(
        public readonly string $extensionId,
        public readonly string $type,
        public readonly string $version,
        public readonly string $source,
        public readonly string $reviewStatus,
        public readonly array $files,
        public readonly string $coreConstraint = '*',
        public readonly string $phpConstraint = '',
        public readonly array $dependencies = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $type = (string) ($data['type'] ?? '');
        if (!in_array($type, ['plugin', 'theme', 'payment_provider'], true)) {
            throw new MarketException('Market package type must be plugin, theme, or payment_provider.');
        }

        $extensionId = (string) ($data['extension_id'] ?? '');
        $validId = in_array($type, ['plugin', 'payment_provider'], true)
            ? preg_match('/^[a-z][a-z0-9]*(?:[_-][a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:[_-][a-z0-9]+)*){0,4}$/', $extensionId) === 1
            : preg_match('/^[a-z][a-z0-9]*(?:[_-][a-z0-9]+)*$/', $extensionId) === 1 && strlen($extensionId) <= 64;
        if (!$validId) {
            throw new MarketException('Invalid market package extension id.');
        }

        $files = $data['files'] ?? [];
        if (!is_array($files)) {
            throw new MarketException('Market package files must be an object.');
        }

        $prefix = in_array($type, ['plugin', 'payment_provider'], true) ? 'content/plugins/' . $extensionId . '/' : 'content/themes/' . $extensionId . '/';
        $cleanFiles = [];
        foreach ($files as $path => $hash) {
            $path = (string) $path;
            if (str_starts_with($path, '/') || str_contains($path, '..') || !str_starts_with($path, $prefix)) {
                throw new MarketException('Market package contains unsafe path: ' . $path);
            }
            $cleanFiles[$path] = (string) $hash;
        }

        $dependencies = [];
        foreach (($data['dependencies'] ?? []) as $dependency) {
            if (is_array($dependency)) {
                $dependencies[] = ExtensionDependency::fromArray($dependency);
            }
        }

        return new self(
            $extensionId,
            $type,
            (string) ($data['version'] ?? ''),
            (string) ($data['source'] ?? ExtensionSource::UNKNOWN),
            (string) ($data['review_status'] ?? 'unknown'),
            $cleanFiles,
            (string) ($data['core'] ?? '*'),
            (string) ($data['php'] ?? ''),
            $dependencies,
        );
    }
}
