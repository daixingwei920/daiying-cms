<?php

declare(strict_types=1);

namespace Cms\Core\Recovery;

use Cms\Core\Integrity\ManifestBuilder;

final class IntegrityChecker
{
    /** @return array{status:string,missing:list<string>,changed:list<string>,unknown:list<string>} */
    public function check(string $rootPath): array
    {
        $manifestFile = $rootPath . '/system/core-manifest.json';
        if (!is_file($manifestFile)) {
            return ['status' => 'missing-manifest', 'missing' => [], 'changed' => [], 'unknown' => []];
        }

        $expected = json_decode((string) file_get_contents($manifestFile), true);
        if (!is_array($expected)) {
            return ['status' => 'invalid-manifest', 'missing' => [], 'changed' => [], 'unknown' => []];
        }

        $actual = ManifestBuilder::build($rootPath . '/system/core');
        $missing = [];
        $changed = [];
        foreach ($expected as $path => $hash) {
            if (!isset($actual[$path])) {
                $missing[] = (string) $path;
            } elseif (!hash_equals((string) $hash, (string) $actual[$path])) {
                $changed[] = (string) $path;
            }
        }

        $unknown = array_values(array_diff(array_keys($actual), array_keys($expected)));
        $status = ($missing === [] && $changed === [] && $unknown === []) ? 'ok' : 'failed';

        return ['status' => $status, 'missing' => $missing, 'changed' => $changed, 'unknown' => $unknown];
    }
}
