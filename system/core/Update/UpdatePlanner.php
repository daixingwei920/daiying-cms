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
        if (!version_compare($currentVersion, $manifest->fromVersionMin, '>=') || !version_compare($currentVersion, $manifest->fromVersionMax, '<=')) {
            throw new UpdateException('Update package source version does not match current Core.');
        }

        return [
            'package_id' => $manifest->packageId,
            'release_id' => $manifest->releaseId,
            'from_version' => $currentVersion,
            'supported_from' => ['min' => $manifest->fromVersionMin, 'max' => $manifest->fromVersionMax],
            'to_version' => $manifest->toVersion,
            'file_count' => count($manifest->files),
            'migration_count' => count($manifest->migrations),
            'requires_restore_point' => true,
            'prepared_dir' => $this->rootPath . '/storage/updates/releases/' . $manifest->releaseId,
            'atomic_switch' => 'rename-current-release-json',
        ];
    }

    public function createRestorePoint(string $packageId): string
    {
        return (new RestorePointService($this->rootPath))->create('before-update-' . $packageId);
    }
}
