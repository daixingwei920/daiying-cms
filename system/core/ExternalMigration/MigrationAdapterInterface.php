<?php

declare(strict_types=1);

namespace Cms\Core\ExternalMigration;

interface MigrationAdapterInterface
{
    public function id(): string;

    public function label(): string;

    public function supports(string $filename, string $payload): bool;

    /** @return array<string, mixed> */
    public function scan(string $filename, string $payload): array;

    /** @return array<string, mixed> */
    public function toPackage(string $filename, string $payload): array;
}
