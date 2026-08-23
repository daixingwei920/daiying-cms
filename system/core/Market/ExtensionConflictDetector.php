<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class ExtensionConflictDetector
{
    public function __construct(private readonly MarketInstallRepository $repository)
    {
    }

    /** @return list<string> */
    public function uninstallConflicts(string $extensionId, string $type): array
    {
        $target = $type . ':' . $extensionId;
        $conflicts = [];
        foreach ($this->repository->latestInstalledByExtension() as $installed) {
            $metadata = json_decode((string) ($installed['metadata_json'] ?? ''), true);
            foreach (($metadata['dependencies'] ?? []) as $dependency) {
                if (!is_array($dependency)) {
                    continue;
                }
                $key = (string) ($dependency['type'] ?? 'plugin') . ':' . (string) ($dependency['extension_id'] ?? '');
                if ($key === $target && !((bool) ($dependency['optional'] ?? false))) {
                    $conflicts[] = (string) $installed['extension_type'] . ':' . (string) $installed['extension_id'];
                }
            }
        }
        sort($conflicts);

        return $conflicts;
    }
}
