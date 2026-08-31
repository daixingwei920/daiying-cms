<?php

declare(strict_types=1);

namespace Cms\Core\Media;

interface MediaStorageProviderInterface
{
    public function id(): string;

    public function put(string $sourcePath, string $storageKey, bool $move): void;

    public function delete(string $storageKey): void;

    public function exists(string $storageKey): bool;

    public function path(string $storageKey): string;
}
