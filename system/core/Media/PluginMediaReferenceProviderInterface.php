<?php

declare(strict_types=1);

namespace Cms\Core\Media;

interface PluginMediaReferenceProviderInterface
{
    public function pluginId(): string;

    /** @return list<array<string,mixed>> */
    public function findReferences(int $mediaId): array;

    public function referenceCount(int $mediaId): int;

    /** @return list<string> */
    public function describeReferences(int $mediaId): array;
}
