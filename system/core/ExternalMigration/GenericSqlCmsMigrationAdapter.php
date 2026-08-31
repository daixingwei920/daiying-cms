<?php

declare(strict_types=1);

namespace Cms\Core\ExternalMigration;

abstract class GenericSqlCmsMigrationAdapter implements MigrationAdapterInterface
{
    use JsonMigrationAdapterTrait;

    /** @return list<string> */
    abstract protected function tableNames(): array;

    /** @return list<string> */
    abstract protected function signatures(): array;

    /** @param array<string,list<array<string,string>>> $tables @return array<string,mixed> */
    abstract protected function packageFromTables(array $tables, string $payload): array;

    public function supports(string $filename, string $payload): bool
    {
        $lower = strtolower($filename);
        if (str_ends_with($lower, '.json') && str_contains(strtolower($payload), $this->id())) {
            return true;
        }
        if (!str_ends_with($lower, '.sql')) {
            return false;
        }
        foreach ($this->signatures() as $signature) {
            if (preg_match($signature, $payload) === 1) {
                return true;
            }
        }

        return false;
    }

    public function scan(string $filename, string $payload): array
    {
        $package = $this->toPackage($filename, $payload);

        return [
            'source_system' => $this->id(),
            'source_version' => (string) ($package['source_version'] ?? ''),
            'source_site_id' => (string) ($package['site']['source_site_id'] ?? $this->id() . ':' . substr(hash('sha256', $payload), 0, 16)),
            'counts' => [
                'users' => count($package['users'] ?? []),
                'categories' => count($package['categories'] ?? []),
                'tags' => count($package['tags'] ?? []),
                'contents' => count($package['contents'] ?? []),
                'media' => count($package['media'] ?? []),
                'comments' => count($package['comments'] ?? []),
                'redirects' => count($package['redirects'] ?? []),
            ],
        ];
    }

    public function toPackage(string $filename, string $payload): array
    {
        $lower = strtolower($filename);
        if (str_ends_with($lower, '.json')) {
            return $this->packageFromGenericJson($payload, $this->id(), $this->label() . ' 站点');
        }
        if (!str_ends_with($lower, '.sql')) {
            throw new MigrationException('暂不支持这个迁移文件。');
        }

        return $this->packageFromTables((new SqlDumpInsertParser($this->tableNames()))->parse($payload), $payload);
    }

    protected function time(mixed $value): ?string
    {
        return $this->timeValue($value);
    }

    /** @param array<string,mixed> $value @return list<string> */
    protected function listFromCsv(array $value, string $key): array
    {
        $raw = (string) ($value[$key] ?? '');
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', preg_split('/[,，\s]+/u', $raw) ?: [])));
    }
}
