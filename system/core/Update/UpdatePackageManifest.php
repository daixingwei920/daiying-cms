<?php

declare(strict_types=1);

namespace Cms\Core\Update;

final class UpdatePackageManifest
{
    /**
     * @param array<string, string> $files
     * @param list<array<string,mixed>> $migrations
     * @param list<string> $requiredExtensions
     * @param list<string> $databaseTypes
     * @param list<string> $requiredMigrations
     * @param array<string,mixed> $rollbackMetadata
     * @param list<string> $features
     * @param list<string> $acceptanceGates
     */
    public function __construct(
        public readonly string $packageId,
        public readonly string $fromVersion,
        public readonly string $toVersion,
        public readonly string $createdAt,
        public readonly array $files,
        public readonly string $packageType = 'core',
        public readonly string $releaseId = '',
        public readonly string $build = '',
        public readonly string $fromVersionMin = '',
        public readonly string $fromVersionMax = '',
        public readonly string $minUpgradeFrom = '',
        public readonly string $hardMinVersion = '',
        public readonly string $migrationFloor = '',
        public readonly string $phpMin = '8.0.0',
        public readonly string $phpMax = '',
        public readonly array $requiredExtensions = [],
        public readonly array $databaseTypes = [],
        public readonly string $coreSchemaVersion = '',
        public readonly array $migrations = [],
        public readonly array $requiredMigrations = [],
        public readonly array $rollbackMetadata = [],
        public readonly string $packageSha256 = '',
        public readonly string $signatureAlgorithm = 'rsa-sha256',
        public readonly string $keyId = '',
        public readonly bool $securityUpdate = false,
        public readonly string $notes = '',
        public readonly array $features = [],
        public readonly array $acceptanceGates = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['created_at', 'files'] as $key) {
            if (!isset($data[$key])) {
                throw new UpdateException('Update package manifest missing key: ' . $key);
            }
        }
        $packageType = (string) ($data['package_type'] ?? 'core');
        if ($packageType !== 'core') {
            throw new UpdateException('Update package type must be core.');
        }
        $releaseId = (string) ($data['release_id'] ?? $data['package_id'] ?? '');
        $version = (string) ($data['version'] ?? $data['to_version'] ?? '');
        $fromVersion = (string) ($data['from_version'] ?? '');
        $sourceRange = is_array($data['source_versions'] ?? null) ? $data['source_versions'] : [];
        $fromMin = (string) ($sourceRange['min'] ?? $fromVersion);
        $fromMax = (string) ($sourceRange['max'] ?? ($data['to_version'] ?? $data['version'] ?? $fromVersion));
        $minUpgradeFrom = (string) ($data['min_upgrade_from'] ?? $fromMin);
        $hardMinVersion = (string) ($data['hard_min_version'] ?? $data['migration_floor'] ?? $minUpgradeFrom);
        $migrationFloor = (string) ($data['migration_floor'] ?? $hardMinVersion);
        if ($releaseId === '' || $version === '') {
            throw new UpdateException('Update manifest must define release_id and version.');
        }

        $files = $data['files'];
        if (!is_array($files)) {
            throw new UpdateException('Update manifest files must be an object.');
        }

        $cleanFiles = [];
        foreach ($files as $path => $hash) {
            $path = (string) $path;
            if (
                $path === ''
                || str_starts_with($path, '/')
                || str_contains(str_replace('\\', '/', $path), '..')
                || str_contains($path, "\0")
                || !self::isAllowedUpdatePath($path)
            ) {
                throw new UpdateException('Update package may only target Core-owned paths.');
            }
            $cleanFiles[$path] = (string) $hash;
        }

        return new self(
            (string) ($data['package_id'] ?? $releaseId),
            $fromVersion !== '' ? $fromVersion : $fromMin,
            $version,
            (string) $data['created_at'],
            $cleanFiles,
            $packageType,
            $releaseId,
            (string) ($data['build'] ?? ''),
            $fromMin,
            $fromMax,
            $minUpgradeFrom,
            $hardMinVersion,
            $migrationFloor,
            (string) ($data['php']['min'] ?? $data['php_min'] ?? '8.0.0'),
            (string) ($data['php']['max'] ?? $data['php_max'] ?? ''),
            array_values(array_map('strval', is_array($data['required_extensions'] ?? null) ? $data['required_extensions'] : [])),
            array_values(array_map('strval', is_array($data['database_types'] ?? null) ? $data['database_types'] : [])),
            (string) ($data['core_schema_version'] ?? ''),
            array_values(is_array($data['migrations'] ?? null) ? $data['migrations'] : []),
            self::stringList($data['required_migrations'] ?? []),
            is_array($data['rollback'] ?? null) ? $data['rollback'] : [],
            (string) ($data['package_sha256'] ?? ''),
            (string) ($data['signature_algorithm'] ?? 'rsa-sha256'),
            (string) ($data['key_id'] ?? ''),
            (bool) ($data['security_update'] ?? false),
            (string) ($data['notes'] ?? ''),
            self::stringList($data['features'] ?? []),
            self::stringList($data['acceptance_gates'] ?? []),
        );
    }

    public function hardFloor(): string
    {
        return $this->hardMinVersion !== '' ? $this->hardMinVersion : ($this->migrationFloor !== '' ? $this->migrationFloor : $this->fromVersionMin);
    }

    public function supportsSourceVersion(string $currentVersion): bool
    {
        $floor = $this->hardFloor();
        if ($floor !== '' && version_compare($currentVersion, $floor, '<')) {
            return false;
        }
        if ($this->fromVersionMin !== '' && version_compare($currentVersion, $this->fromVersionMin, '<')) {
            return false;
        }
        if ($this->fromVersionMax !== '' && version_compare($currentVersion, $this->fromVersionMax, '>')) {
            return false;
        }

        return true;
    }

    /** @return list<array<string,mixed>> */
    public function migrationsFor(string $currentVersion): array
    {
        $pending = [];
        foreach ($this->migrations as $migration) {
            if (!is_array($migration)) {
                $pending[] = $migration;
                continue;
            }
            $target = (string) ($migration['target_version'] ?? $migration['version'] ?? $this->toVersion);
            if ($target === '' || version_compare($target, $currentVersion, '>')) {
                $pending[] = $migration;
            }
        }

        return $pending;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item !== '' && preg_match('/^[a-z0-9_.-]+$/', $item) === 1) {
                $items[] = $item;
            }
        }

        return array_values(array_unique($items));
    }

    public static function isCorePath(string $path): bool
    {
        $path = str_replace('\\', '/', $path);
        return str_starts_with($path, 'system/core/')
            || str_starts_with($path, 'system/migrations/')
            || $path === 'system/core-manifest.json';
    }

    public static function isAllowedUpdatePath(string $path): bool
    {
        $path = str_replace('\\', '/', $path);
        if (self::isCorePath($path)) {
            return true;
        }

        return in_array($path, self::operationalSupportPaths(), true);
    }

    /** @return list<string> */
    public static function operationalSupportPaths(): array
    {
        return [
            'README.md',
            'CMS_RELEASE_ENVIRONMENT_DEPLOYMENT_CHECKLIST.md',
            'scripts/diagnose_payment_providers.php',
            'scripts/publish_scheduled_content.php',
            'scripts/validate_production_readiness.php',
            'scripts/verify_release_audit_counts.php',
        ];
    }
}
