<?php

declare(strict_types=1);

namespace Cms\Core\Media;

interface MediaProbeInterface
{
    /** @return array{duration_seconds?:float|null} */
    public function probe(string $path, string $mime): array;
}
