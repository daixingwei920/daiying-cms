<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class ExtensionDependencyGraph
{
    /** @param list<MarketPackageManifest> $manifests @return array<string, list<string>> */
    public function build(array $manifests): array
    {
        $graph = [];
        foreach ($manifests as $manifest) {
            $key = $manifest->type . ':' . $manifest->extensionId;
            $graph[$key] = [];
            foreach ($manifest->dependencies as $dependency) {
                $graph[$key][] = $dependency->type . ':' . $dependency->extensionId;
            }
        }
        ksort($graph);

        return $graph;
    }

    /** @param array<string, list<string>> $graph @return list<string> */
    public function dependentsOf(array $graph, string $extensionKey): array
    {
        $dependents = [];
        foreach ($graph as $node => $dependencies) {
            if (in_array($extensionKey, $dependencies, true)) {
                $dependents[] = $node;
            }
        }
        sort($dependents);

        return $dependents;
    }
}
