<?php

declare(strict_types=1);

namespace Cms\Core\Media;

final class NullMediaProbe implements MediaProbeInterface
{
    public function probe(string $path, string $mime): array
    {
        return ['duration_seconds' => null];
    }
}
