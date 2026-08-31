<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class ExtensionCompatibilityChecker
{
    public function __construct(private readonly string $coreVersion)
    {
    }

    public function assertCompatible(MarketPackageManifest $manifest): void
    {
        if (!VersionConstraint::matches($this->coreVersion, $manifest->coreConstraint)) {
            throw new MarketException('Extension requires CMS core version ' . $manifest->coreConstraint . '.');
        }

        if ($manifest->phpConstraint !== '' && !VersionConstraint::matches(PHP_VERSION, $manifest->phpConstraint)) {
            throw new MarketException('Extension requires PHP version ' . $manifest->phpConstraint . '.');
        }
    }
}
