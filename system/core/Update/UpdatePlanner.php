<?php

declare(strict_types=1);

namespace Cms\Core\Update;

use Cms\Core\Recovery\RestorePointService;

final class UpdatePlanner
{
    public function __construct(private readonly string $rootPath)
    {
    }

    /** @return array<string, mixed> */
    public function plan(UpdatePackageManifest $manifest, string $currentVersion): array
    {
        if (!$manifest->supportsSourceVersion($currentVersion)) {
            $floor = $manifest->hardFloor();
            if ($floor !== '' && version_compare($currentVersion, $floor, '<')) {
                throw new UpdateException('Current Core is below the hard minimum version for this migration chain.');
            }
            throw new UpdateException('Update package source version is outside the supported migration range.');
        }

        $pendingMigrations = $manifest->migrationsFor($currentVersion);
        return [
            'package_id' => $manifest->packageId,
            'release_id' => $manifest->releaseId,
            'from_version' => $currentVersion,
            'supported_from' => ['min' => $manifest->fromVersionMin, 'max' => $manifest->fromVersionMax],
            'min_upgrade_from' => $manifest->minUpgradeFrom,
            'hard_min_version' => $manifest->hardFloor(),
            'migration_floor' => $manifest->migrationFloor,
            'to_version' => $manifest->toVersion,
            'file_count' => count($manifest->files),
            'migration_count' => count($pendingMigrations),
            'required_migrations' => $manifest->requiredMigrations,
            'direct_upgrade_supported' => true,
            'requires_restore_point' => true,
            'prepared_dir' => $this->rootPath . '/storage/updates/releases/' . $manifest->releaseId,
            'atomic_switch' => 'rename-current-release-json',
            'rollback' => $manifest->rollbackMetadata + [
                'database' => true,
                'core_pointer' => true,
                'operational_support_files' => true,
            ],
        ];
    }

    public function createRestorePoint(string $packageId): string
    {
        return (new RestorePointService($this->rootPath))->create('before-update-' . $packageId);
    }
}
