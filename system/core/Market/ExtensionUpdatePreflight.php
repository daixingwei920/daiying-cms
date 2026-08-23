<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class ExtensionUpdatePreflight
{
    public function __construct(private readonly MarketInstallRepository $repository)
    {
    }

    /** @return array<string, mixed> */
    public function check(string $extensionId, string $type, string $targetVersion): array
    {
        $installed = $this->repository->latestSource($extensionId, $type);
        if ($installed === null || (string) ($installed['source'] ?? '') === 'uninstalled') {
            throw new MarketException('Extension is not installed.');
        }

        $currentVersion = (string) ($installed['version'] ?? '0.0.0');
        if (!version_compare($targetVersion, $currentVersion, '>=')) {
            throw new MarketException('Target version is older than the installed version.');
        }

        $conflicts = (new ExtensionConflictDetector($this->repository))->uninstallConflicts($extensionId, $type);

        return [
            'extension_id' => $extensionId,
            'type' => $type,
            'current_version' => $currentVersion,
            'target_version' => $targetVersion,
            'conflicts' => $conflicts,
            'status' => $conflicts === [] ? 'Ready' : 'NeedsReview',
        ];
    }
}
