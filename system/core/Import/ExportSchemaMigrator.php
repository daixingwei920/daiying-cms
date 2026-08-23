<?php

declare(strict_types=1);

namespace Cms\Core\Import;

final class ExportSchemaMigrator
{
    /** @param array<string, mixed> $manifest */
    public function migrateManifest(array $manifest, string $targetVersion): array
    {
        $current = (string) ($manifest['export_schema_version'] ?? '0.0.0');
        if ($current === $targetVersion) {
            return $manifest;
        }

        if ($current === '0.9.0' && $targetVersion === '1.0.0') {
            $manifest['export_schema_version'] = '1.0.0';
            $manifest['migrated_from'] = '0.9.0';
            return $manifest;
        }

        throw new ImportException('No export schema migration path from ' . $current . ' to ' . $targetVersion);
    }
}
