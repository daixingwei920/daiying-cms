<?php

declare(strict_types=1);

namespace Cms\Core\Export;

use Cms\Core\Content\ContentRepository;
use Cms\Core\Content\ContentTypeRegistry;
use Cms\Core\UrlMapping\UrlMappingRepository;
use PDO;
use Throwable;

final class OfficialExportContentImporter
{
    /** @var array<string,bool>|null */
    private ?array $mediaColumns = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ExportPackageReader $reader = new ExportPackageReader(),
    ) {
    }

    /** @return array{media:array{imported:int,updated:int,skipped:int},content:array{created:int,updated:int,skipped:int},url_mappings:array{created:int,skipped:int},extension_data:array{created:int,updated:int,skipped:int}} */
    public function importPackage(string $zipPath): array
    {
        $this->assertOfficialPackage($zipPath);
        $mediaRows = $this->rows($this->reader->jsonPayload($zipPath, 'media/media.json'), 'Export package media payload is invalid.');
        $contentRows = $this->rows($this->reader->jsonPayload($zipPath, 'content/content.json'), 'Export package content payload is invalid.');
        $mappingRows = $this->rows($this->reader->jsonPayload($zipPath, 'url-map/url-map.json'), 'Export package URL mapping payload is invalid.');
        $extensionRows = $this->extensionDataRows($this->reader->jsonPayload($zipPath, 'extensions/extensions.json'));

        $started = !$this->pdo->inTransaction();
        if ($started) {
            $this->pdo->beginTransaction();
        }

        try {
            $media = $this->importMediaMetadata($mediaRows);
            $content = $this->importContent($contentRows, $media['id_map']);
            $urlMappings = $this->importUrlMappings($mappingRows);
            $extensionData = $this->importExtensionData($extensionRows);
            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }

            return [
                'media' => [
                    'imported' => $media['imported'],
                    'updated' => $media['updated'],
                    'skipped' => $media['skipped'],
                ],
                'content' => $content,
                'url_mappings' => $urlMappings,
                'extension_data' => $extensionData,
            ];
        } catch (Throwable $exception) {
            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array{media_count:int,content_count:int,url_mapping_count:int,extension_data_count:int} */
    public function preflight(string $zipPath): array
    {
        $this->assertOfficialPackage($zipPath);
        $mappingRows = $this->rows($this->reader->jsonPayload($zipPath, 'url-map/url-map.json'), 'Export package URL mapping payload is invalid.');
        $this->validateUrlMappings($mappingRows);

        return [
            'media_count' => count($this->rows($this->reader->jsonPayload($zipPath, 'media/media.json'), 'Export package media payload is invalid.')),
            'content_count' => count($this->rows($this->reader->jsonPayload($zipPath, 'content/content.json'), 'Export package content payload is invalid.')),
            'url_mapping_count' => count($mappingRows),
            'extension_data_count' => count($this->extensionDataRows($this->reader->jsonPayload($zipPath, 'extensions/extensions.json'))),
        ];
    }

    private function assertOfficialPackage(string $zipPath): void
    {
        $manifest = $this->reader->verifyPackage($zipPath);
        if (($manifest['platform_id'] ?? '') !== 'php-cms' || ($manifest['export_schema_version'] ?? '') !== ExportPackageBuilder::EXPORT_SCHEMA_VERSION) {
            throw new ExportException('Official export package schema is unsupported.');
        }
    }

    /** @return list<array<string,mixed>> */
    private function rows(array $payload, string $error): array
    {
        $rows = [];
        foreach ($payload as $row) {
            if (!is_array($row)) {
                throw new ExportException($error);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @param list<array<string,mixed>> $rows @return array{imported:int,updated:int,skipped:int,id_map:array<int,int>} */
    private function importMediaMetadata(array $rows): array
    {
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $idMap = [];
        foreach ($rows as $row) {
            $oldId = $this->positiveInt($row['id'] ?? null, 'Export media id is invalid.');
            $hash = $this->sha256($row['sha256_hash'] ?? null, 'Export media hash is invalid.');
            $storageProvider = $this->safeToken($row['storage_provider'] ?? null, 'Export media storage provider is invalid.', 64);
            $mediaType = $this->mediaType($row['media_type'] ?? null);
            $mimeType = $this->safeMime($row['mime_type'] ?? null);
            $originalName = $this->safeText($row['original_name'] ?? null, 'Export media original name is invalid.', 255, true);
            $relativePath = $this->safeStoragePath($row['relative_path'] ?? null);
            $storageKey = $this->safeStoragePath(($row['storage_key'] ?? '') !== '' ? $row['storage_key'] : $relativePath);
            $byteSize = $this->nonNegativeInt($row['byte_size'] ?? null, 'Export media byte size is invalid.');
            $metadataJson = $this->safeJsonObjectString($row['metadata_json'] ?? '{}', 'Export media metadata JSON is invalid.');
            $createdAt = $this->timestamp($row['created_at'] ?? null, 'Export media created_at is invalid.');
            $updatedAt = $this->timestamp($row['updated_at'] ?? null, 'Export media updated_at is invalid.');
            $extension = $this->optionalToken($row['extension'] ?? null, 'Export media extension is invalid.', 16);
            $width = $this->optionalNonNegativeInt($row['width'] ?? null, 'Export media width is invalid.');
            $height = $this->optionalNonNegativeInt($row['height'] ?? null, 'Export media height is invalid.');
            $duration = $this->optionalNonNegativeFloat($row['duration_seconds'] ?? null, 'Export media duration is invalid.');
            $title = $this->optionalText($row['title'] ?? null, 'Export media title is invalid.', 255);
            $description = $this->optionalText($row['description'] ?? null, 'Export media description is invalid.', 4096);
            $altText = $this->optionalText($row['alt_text'] ?? null, 'Export media alt text is invalid.', 255);
            $uploadedBy = $this->optionalPositiveInt($row['uploaded_by'] ?? null, 'Export media uploader is invalid.');
            $status = $this->mediaStatus($row['status'] ?? 'Active');
            $deletedAt = $this->optionalTimestamp($row['deleted_at'] ?? null, 'Export media deleted_at is invalid.');

            $existingId = $this->mediaIdById($oldId);
            if ($existingId !== null) {
                $existing = $this->mediaRow($existingId);
                if (is_array($existing) && (string) ($existing['sha256_hash'] ?? '') === $hash) {
                    $this->updateMediaMetadata($existingId, compact('storageProvider', 'mediaType', 'mimeType', 'originalName', 'relativePath', 'storageKey', 'byteSize', 'hash', 'metadataJson', 'createdAt', 'updatedAt', 'extension', 'width', 'height', 'duration', 'title', 'description', 'altText', 'uploadedBy', 'status', 'deletedAt'));
                    $idMap[$oldId] = $existingId;
                    $updated++;
                    continue;
                }
            }

            $mappedId = $this->mediaIdByHash($hash) ?? $this->mediaIdByStorageKey($storageKey);
            if ($mappedId !== null) {
                $idMap[$oldId] = $mappedId;
                $skipped++;
                continue;
            }

            $this->insertMediaMetadata($oldId, compact('storageProvider', 'mediaType', 'mimeType', 'originalName', 'relativePath', 'storageKey', 'byteSize', 'hash', 'metadataJson', 'createdAt', 'updatedAt', 'extension', 'width', 'height', 'duration', 'title', 'description', 'altText', 'uploadedBy', 'status', 'deletedAt'));
            $idMap[$oldId] = $oldId;
            $imported++;
        }

        return ['imported' => $imported, 'updated' => $updated, 'skipped' => $skipped, 'id_map' => $idMap];
    }

    /** @param array<string,mixed> $data */
    private function insertMediaMetadata(int $id, array $data): void
    {
        $values = $this->mediaValues($id, $data);
        $columns = array_keys($values);
        $sql = 'INSERT INTO cms_media (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')';
        $this->pdo->prepare($sql)->execute($this->bindValues($values));
    }

    /** @param array<string,mixed> $data */
    private function updateMediaMetadata(int $id, array $data): void
    {
        $values = $this->mediaValues($id, $data);
        unset($values['id'], $values['sha256_hash']);
        $sets = [];
        foreach (array_keys($values) as $column) {
            $sets[] = $column . ' = :' . $column;
        }
        $this->pdo->prepare('UPDATE cms_media SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($this->bindValues($values + ['id' => $id]));
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function mediaValues(int $id, array $data): array
    {
        $values = [
            'id' => $id,
            'storage_provider' => $data['storageProvider'],
            'media_type' => $data['mediaType'],
            'mime_type' => $data['mimeType'],
            'original_name' => $data['originalName'],
            'relative_path' => $data['relativePath'],
            'byte_size' => $data['byteSize'],
            'sha256_hash' => $data['hash'],
            'metadata_json' => $data['metadataJson'],
            'created_at' => $data['createdAt'],
            'updated_at' => $data['updatedAt'],
            'storage_key' => $data['storageKey'],
            'extension' => $data['extension'],
            'width' => $data['width'],
            'height' => $data['height'],
            'duration_seconds' => $data['duration'],
            'title' => $data['title'],
            'description' => $data['description'],
            'alt_text' => $data['altText'],
            'uploaded_by' => $data['uploadedBy'],
            'status' => $data['status'],
            'deleted_at' => $data['deletedAt'],
        ];
        $columns = $this->mediaColumns();

        return array_filter($values, static fn (string $column): bool => isset($columns[$column]), ARRAY_FILTER_USE_KEY);
    }

    /** @return array<string,bool> */
    private function mediaColumns(): array
    {
        if ($this->mediaColumns !== null) {
            return $this->mediaColumns;
        }
        $columns = [];
        if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $rows = $this->pdo->query('PRAGMA table_info(cms_media)')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $columns[(string) ($row['name'] ?? '')] = true;
            }
        } else {
            $rows = $this->pdo->query('SHOW COLUMNS FROM cms_media')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $columns[(string) ($row['Field'] ?? '')] = true;
            }
        }

        return $this->mediaColumns = $columns;
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    private function bindValues(array $values): array
    {
        $bound = [];
        foreach ($values as $key => $value) {
            $bound[':' . $key] = $value;
        }

        return $bound;
    }

    private function mediaIdById(int $id): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM cms_media WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    private function mediaIdByHash(string $hash): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM cms_media WHERE sha256_hash = :hash LIMIT 1');
        $stmt->execute([':hash' => $hash]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    private function mediaIdByStorageKey(string $storageKey): ?int
    {
        if (!isset($this->mediaColumns()['storage_key'])) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM cms_media WHERE storage_key = :storage_key LIMIT 1');
        $stmt->execute([':storage_key' => $storageKey]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /** @return array<string,mixed>|null */
    private function mediaRow(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_media WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param list<array<string,mixed>> $rows @param array<int,int> $mediaIdMap @return array{created:int,updated:int,skipped:int} */
    private function importContent(array $rows, array $mediaIdMap): array
    {
        $repo = new ContentRepository($this->pdo, ContentTypeRegistry::defaults());
        $created = 0;
        $updated = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $type = $this->contentType($row['content_type'] ?? null);
            $title = $this->safeText($row['title'] ?? null, 'Export content title is invalid.', 255, true);
            $slug = $this->slug($row['slug'] ?? null);
            $status = $this->contentStatus($row['status'] ?? null);
            $blocks = $this->jsonList($row['blocks_json'] ?? '[]', 'Export content blocks JSON is invalid.');
            $meta = $this->jsonObject($row['meta_json'] ?? '{}', 'Export content meta JSON is invalid.');
            $blocks = $this->remapMediaIds($blocks, $mediaIdMap);
            $existingId = $this->contentIdByTypeSlug($type, $slug);
            if ($existingId !== null) {
                $repo->update($existingId, $type, $title, $slug, $blocks, $status, $meta);
                $updated++;
            } else {
                $repo->create($type, $title, $slug, $blocks, $status, $meta);
                $created++;
            }
        }
        if ($rows === []) {
            $skipped = 0;
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    private function contentIdByTypeSlug(string $type, string $slug): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM cms_contents WHERE content_type = :type AND slug = :slug LIMIT 1');
        $stmt->execute([':type' => $type, ':slug' => $slug]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /** @param mixed $value @param array<int,int> $mediaIdMap @return mixed */
    private function remapMediaIds(mixed $value, array $mediaIdMap): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $remapped = [];
        foreach ($value as $key => $item) {
            if (($key === 'media_id' || $key === 'poster_media_id') && is_int($item) && isset($mediaIdMap[$item])) {
                $remapped[$key] = $mediaIdMap[$item];
                continue;
            }
            if ($key === 'media_ids' && is_array($item)) {
                $remapped[$key] = array_values(array_map(static fn (mixed $id): mixed => is_int($id) && isset($mediaIdMap[$id]) ? $mediaIdMap[$id] : $id, $item));
                continue;
            }
            $remapped[$key] = $this->remapMediaIds($item, $mediaIdMap);
        }

        return $remapped;
    }

    /** @param list<array<string,mixed>> $rows @return array{created:int,skipped:int} */
    private function importUrlMappings(array $rows): array
    {
        $repo = new UrlMappingRepository($this->pdo);
        $created = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            [$source, $target, $status, $platform] = $this->validatedUrlMapping($row);
            if ($this->urlMappingExists($source, $target, $status, $platform)) {
                $skipped++;
                continue;
            }
            $repo->record($source, $target, $status, $platform);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /** @param list<array<string,mixed>> $rows */
    private function validateUrlMappings(array $rows): void
    {
        foreach ($rows as $row) {
            $this->validatedUrlMapping($row);
        }
    }

    /** @param array<string,mixed> $row @return array{0:string,1:string,2:int,3:string} */
    private function validatedUrlMapping(array $row): array
    {
        $source = $this->urlValue($row['source_url'] ?? null, 'Export URL mapping source is invalid.');
        $target = $this->urlValue($row['target_url'] ?? null, 'Export URL mapping target is invalid.');
        $status = $this->redirectStatus($row['status_code'] ?? null);
        $platform = $this->safeToken($row['source_platform'] ?? 'official-export', 'Export URL mapping source platform is invalid.', 64);
        if (!$this->safeUrlMappingSource($source)) {
            throw new ExportException('Export URL mapping source is invalid.');
        }
        if (!$this->safeUrlMappingTarget($target)) {
            throw new ExportException('Export URL mapping target is invalid.');
        }

        return [$source, $target, $status, $platform];
    }

    /** @param array<string,mixed> $payload @return list<array<string,mixed>> */
    private function extensionDataRows(array $payload): array
    {
        $rows = $payload['dormant_data'] ?? [];
        if (!is_array($rows)) {
            throw new ExportException('Export package extension data payload is invalid.');
        }

        return $this->rows($rows, 'Export package extension data payload is invalid.');
    }

    /** @param list<array<string,mixed>> $rows @return array{created:int,updated:int,skipped:int} */
    private function importExtensionData(array $rows): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $extensionId = $this->safeToken($row['extension_id'] ?? null, 'Export extension data extension_id is invalid.', 191);
            $dataType = $this->safeToken($row['data_type'] ?? null, 'Export extension data type is invalid.', 64);
            $dataKey = $this->safeToken($row['data_key'] ?? null, 'Export extension data key is invalid.', 191);
            $payload = $this->safeJsonObjectString($row['payload'] ?? '{}', 'Export extension data payload is invalid.');
            $status = $this->safeToken($row['status'] ?? 'dormant', 'Export extension data status is invalid.', 32);
            $createdAt = $this->timestamp($row['created_at'] ?? null, 'Export extension data created_at is invalid.');
            $updatedAt = $this->timestamp($row['updated_at'] ?? null, 'Export extension data updated_at is invalid.');
            $existing = $this->extensionDataId($extensionId, $dataType, $dataKey);
            if ($existing === null) {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO cms_core_extension_data (extension_id, data_type, data_key, payload, status, created_at, updated_at)
                     VALUES (:extension_id, :data_type, :data_key, :payload, :status, :created_at, :updated_at)'
                );
                $stmt->execute([
                    ':extension_id' => $extensionId,
                    ':data_type' => $dataType,
                    ':data_key' => $dataKey,
                    ':payload' => $payload,
                    ':status' => $status,
                    ':created_at' => $createdAt,
                    ':updated_at' => $updatedAt,
                ]);
                $created++;
                continue;
            }
            $currentPayload = $this->pdo->prepare('SELECT payload, status FROM cms_core_extension_data WHERE id = :id LIMIT 1');
            $currentPayload->execute([':id' => $existing]);
            $current = $currentPayload->fetch(PDO::FETCH_ASSOC);
            if (is_array($current) && (string) ($current['payload'] ?? '') === $payload && (string) ($current['status'] ?? '') === $status) {
                $skipped++;
                continue;
            }
            $stmt = $this->pdo->prepare(
                'UPDATE cms_core_extension_data SET payload = :payload, status = :status, updated_at = :updated_at WHERE id = :id'
            );
            $stmt->execute([
                ':id' => $existing,
                ':payload' => $payload,
                ':status' => $status,
                ':updated_at' => $updatedAt,
            ]);
            $updated++;
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    private function extensionDataId(string $extensionId, string $dataType, string $dataKey): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM cms_core_extension_data WHERE extension_id = :extension_id AND data_type = :data_type AND data_key = :data_key LIMIT 1');
        $stmt->execute([
            ':extension_id' => $extensionId,
            ':data_type' => $dataType,
            ':data_key' => $dataKey,
        ]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    private function urlMappingExists(string $source, string $target, int $status, string $platform): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM cms_url_mappings WHERE source_url = :source_url AND target_url = :target_url AND status_code = :status_code AND source_platform = :source_platform LIMIT 1');
        $stmt->execute([
            ':source_url' => $source,
            ':target_url' => $target,
            ':status_code' => $status,
            ':source_platform' => $platform,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    private function positiveInt(mixed $value, string $error): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/', $value) === 1) {
            return (int) $value;
        }

        throw new ExportException($error);
    }

    private function optionalPositiveInt(mixed $value, string $error): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->positiveInt($value, $error);
    }

    private function nonNegativeInt(mixed $value, string $error): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,17})$/', $value) === 1) {
            return (int) $value;
        }

        throw new ExportException($error);
    }

    private function optionalNonNegativeInt(mixed $value, string $error): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->nonNegativeInt($value, $error);
    }

    private function optionalNonNegativeFloat(mixed $value, string $error): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_float($value) || is_int($value)) {
            $float = (float) $value;
        } elseif (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,12})(\.[0-9]{1,6})?$/', $value) === 1) {
            $float = (float) $value;
        } else {
            throw new ExportException($error);
        }
        if ($float < 0) {
            throw new ExportException($error);
        }

        return $float;
    }

    private function sha256(mixed $value, string $error): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new ExportException($error);
        }

        return $value;
    }

    private function safeToken(mixed $value, string $error, int $max): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > $max || preg_match('/^[A-Za-z0-9._-]+$/', $value) !== 1) {
            throw new ExportException($error);
        }

        return $value;
    }

    private function optionalToken(mixed $value, string $error, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->safeToken($value, $error, $max);
    }

    private function safeText(mixed $value, string $error, int $max, bool $required = false): string
    {
        if (!is_string($value) || ($required && $value === '') || $value !== trim($value) || strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new ExportException($error);
        }

        return $value;
    }

    private function optionalText(mixed $value, string $error, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->safeText($value, $error, $max);
    }

    private function safeStoragePath(mixed $value): string
    {
        if (!is_string($value) || $value === '' || $value !== trim($value, '/') || strlen($value) > 512 || str_contains($value, '..') || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new ExportException('Export media storage path is invalid.');
        }
        foreach (explode('/', $value) as $part) {
            if ($part === '' || $part[0] === '.' || preg_match('/^[A-Za-z0-9._-]{1,128}$/', $part) !== 1) {
                throw new ExportException('Export media storage path is invalid.');
            }
        }

        return $value;
    }

    private function safeMime(mixed $value): string
    {
        if (!is_string($value) || preg_match('#^[a-z0-9][a-z0-9.+-]{0,63}/[a-z0-9][a-z0-9.+-]{0,127}$#', $value) !== 1) {
            throw new ExportException('Export media MIME type is invalid.');
        }

        return $value;
    }

    private function mediaType(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['image', 'audio', 'video', 'attachment'], true)) {
            throw new ExportException('Export media type is invalid.');
        }

        return $value;
    }

    private function mediaStatus(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['Active', 'Deleted'], true)) {
            throw new ExportException('Export media status is invalid.');
        }

        return $value;
    }

    private function contentType(mixed $value): string
    {
        if (!is_string($value) || !ContentTypeRegistry::defaults()->has($value)) {
            throw new ExportException('Export content type is invalid.');
        }

        return $value;
    }

    private function contentStatus(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['draft', 'published', 'scheduled', 'archived'], true)) {
            throw new ExportException('Export content status is invalid.');
        }

        return $value;
    }

    private function slug(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-z0-9][a-z0-9-]{0,190}$/', $value) !== 1) {
            throw new ExportException('Export content slug is invalid.');
        }

        return $value;
    }

    /** @return list<array<string,mixed>> */
    private function jsonList(mixed $value, string $error): array
    {
        if (!is_string($value) || $value === '' || $value !== trim($value)) {
            throw new ExportException($error);
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ExportException($error);
        }
        if (!is_array($decoded)) {
            throw new ExportException($error);
        }
        $list = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                throw new ExportException($error);
            }
            $list[] = $row;
        }

        return $list;
    }

    /** @return array<string,mixed> */
    private function jsonObject(mixed $value, string $error): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_string($value) || $value !== trim($value)) {
            throw new ExportException($error);
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ExportException($error);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ExportException($error);
        }

        return $decoded;
    }

    private function safeJsonObjectString(mixed $value, string $error): string
    {
        $decoded = $this->jsonObject($value === null || $value === '' ? '{}' : $value, $error);
        try {
            return json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ExportException($error);
        }
    }

    private function timestamp(mixed $value, string $error): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $value) !== 1) {
            throw new ExportException($error);
        }

        return $value;
    }

    private function optionalTimestamp(mixed $value, string $error): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->timestamp($value, $error);
    }

    private function urlValue(mixed $value, string $error): string
    {
        if (!is_string($value) || $value === '' || $value !== trim($value) || strlen($value) > 512 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new ExportException($error);
        }
        if (str_starts_with($value, '/')) {
            if (!str_starts_with($value, '//') && !str_contains($value, '..')) {
                return $value;
            }
            throw new ExportException($error);
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        $host = (string) parse_url($value, PHP_URL_HOST);
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new ExportException($error);
        }

        return $value;
    }

    private function safeUrlMappingSource(string $source): bool
    {
        if (!str_starts_with($source, '/')) {
            return true;
        }

        return !str_starts_with($source, '//')
            && !str_contains($source, '..')
            && !str_contains($source, '\\')
            && !str_contains($source, '?')
            && !str_contains($source, '#');
    }

    private function safeUrlMappingTarget(string $target): bool
    {
        return str_starts_with($target, '/')
            && !str_starts_with($target, '//')
            && !str_contains($target, '..')
            && !str_contains($target, '\\')
            && !str_contains($target, '?')
            && !str_contains($target, '#');
    }

    private function redirectStatus(mixed $value): int
    {
        $status = $this->nonNegativeInt($value, 'Export URL mapping status is invalid.');
        if (!in_array($status, [301, 302, 307, 308], true)) {
            throw new ExportException('Export URL mapping status is invalid.');
        }

        return $status;
    }
}
