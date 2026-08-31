<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class ExtensionDependencyResolver
{
    public function __construct(private readonly MarketInstallRepository $repository)
    {
    }

    public function assertSatisfied(MarketPackageManifest $manifest): void
    {
        foreach ($manifest->dependencies as $dependency) {
            if ($dependency->optional) {
                continue;
            }
            $installed = $this->repository->latestSource($dependency->extensionId, $dependency->type);
            if ($installed === null) {
                throw new MarketException('Missing required extension dependency: ' . $dependency->extensionId);
            }
            $installedVersion = (string) ($installed['version'] ?? '');
            if (!VersionConstraint::matches($installedVersion, $dependency->constraint)) {
                throw new MarketException('Dependency version mismatch for ' . $dependency->extensionId . ': requires ' . $dependency->constraint);
            }
        }
    }
}
