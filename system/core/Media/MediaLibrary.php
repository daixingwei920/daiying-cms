<?php

declare(strict_types=1);

namespace Cms\Core\Media;

use PDO;
use Throwable;

final class MediaLibrary
{
    private const EXTENSION_MIME = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'avif' => ['image/avif'],
        'mp3' => ['audio/mpeg'],
        'm4a' => ['audio/mp4', 'audio/aac', 'video/mp4'],
        'aac' => ['audio/aac', 'audio/mp4'],
        'ogg' => ['audio/ogg', 'application/ogg', 'video/ogg'],
        'wav' => ['audio/wav', 'audio/x-wav'],
        'mp4' => ['video/mp4'],
        'webm' => ['video/webm'],
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
    ];

    private const MEDIA_TYPE = [
        'jpg' => 'image',
        'jpeg' => 'image',
        'png' => 'image',
        'gif' => 'image',
        'webp' => 'image',
        'avif' => 'image',
        'mp3' => 'audio',
        'm4a' => 'audio',
        'aac' => 'audio',
        'ogg' => 'audio',
        'wav' => 'audio',
        'mp4' => 'video',
        'webm' => 'video',
        'pdf' => 'attachment',
        'txt' => 'attachment',
        'zip' => 'attachment',
        'docx' => 'attachment',
        'xlsx' => 'attachment',
        'pptx' => 'attachment',
    ];

    private const DANGEROUS_EXTENSIONS = ['php', 'phtml', 'phar', 'html', 'htm', 'js', 'cgi', 'pl', 'py', 'rb', 'sh', 'exe', 'dll', 'bat', 'cmd', 'com', 'scr', 'svg'];

    /** @param array<string, mixed> $limits */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $uploadRoot,
        private readonly array $limits = [],
        private readonly ?MediaProbeInterface $probe = null,
        private readonly ?MediaStorageProviderInterface $storageProvider = null,
        private readonly ?ImageDerivativeService $imageDerivativeService = null,
    ) {
        $this->ensureUploadProtections();
    }

    public function registerLocalFile(string $absolutePath, string $originalName): int
    {
        return $this->ingest($absolutePath, $originalName, null, false);
    }

    public function uploadLocalFile(string $temporaryPath, string $originalName, int $adminId): int
    {
        try {
            return $this->ingest($temporaryPath, $originalName, $adminId, true);
        } catch (Throwable $exception) {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_media WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return list<array<string, mixed>> */
    public function list(array $filters = [], int $limit = 50): array
    {
        $where = [];
        $params = [];
        if (($filters['type'] ?? '') !== '') {
            $where[] = 'media_type = :type';
            $params[':type'] = (string) $filters['type'];
        }
        if (($filters['filename'] ?? '') !== '') {
            $where[] = 'original_name LIKE :filename';
            $params[':filename'] = '%' . (string) $filters['filename'] . '%';
        }
        if (($filters['status'] ?? '') !== '') {
            $where[] = 'status = :status';
            $params[':status'] = (string) $filters['status'];
        }
        $sql = 'SELECT * FROM cms_media' . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT :limit';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', max(1, min($limit, 200)), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    /** @param array<string, string> $fields */
    public function updateMeta(int $id, array $fields): void
    {
        $stmt = $this->pdo->prepare('UPDATE cms_media SET title = :title, description = :description, alt_text = :alt_text, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':id' => $id,
            ':title' => $this->cleanText($fields['title'] ?? ''),
            ':description' => $this->cleanText($fields['description'] ?? ''),
            ':alt_text' => $this->cleanText($fields['alt_text'] ?? ''),
            ':updated_at' => gmdate('c'),
        ]);
    }

    public function markDeleted(int $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE cms_media SET status = 'Deleted', deleted_at = :deleted_at, updated_at = :updated_at WHERE id = :id");
        $stmt->execute([':id' => $id, ':deleted_at' => gmdate('c'), ':updated_at' => gmdate('c')]);
    }

    public function hardDelete(int $id): void
    {
        if ($this->references($id) !== []) {
            throw new MediaException('Media is still referenced by content.');
        }
        $media = $this->find($id);
        if ($media === null) {
            return;
        }
        $this->pdo->prepare('DELETE FROM cms_media WHERE id = :id')->execute([':id' => $id]);

        if (!$this->isStorageKeyUsed((string) $media['storage_key'])) {
            $this->storage()->delete((string) $media['storage_key']);
        }
        foreach (($media['metadata']['derivatives'] ?? []) as $derivative) {
            if (is_array($derivative) && isset($derivative['storage_key'])) {
                $this->storage()->delete((string) $derivative['storage_key']);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    public function references(int $mediaId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.*, c.title, c.slug, c.content_type
             FROM cms_media_references r
             INNER JOIN cms_contents c ON c.id = r.content_id
             WHERE r.media_id = :media_id ORDER BY r.id'
        );
        $stmt->execute([':media_id' => $mediaId]);
        $references = $stmt->fetchAll();

        $references = array_merge($references, (new PluginMediaReferenceIndex($this->pdo))->references($mediaId));

        return $references;
    }

    /** @param list<array<string, mixed>> $blocks */
    public function syncContentReferences(int $contentId, array $blocks): void
    {
        $this->pdo->prepare('DELETE FROM cms_media_references WHERE content_id = :content_id')->execute([':content_id' => $contentId]);
        foreach ($this->collectReferences($blocks) as $ref) {
            $this->pdo->prepare('INSERT INTO cms_media_references (media_id, content_id, block_type, field_name, created_at) VALUES (:media_id, :content_id, :block_type, :field_name, :created_at)')
                ->execute([
                    ':media_id' => $ref['media_id'],
                    ':content_id' => $contentId,
                    ':block_type' => $ref['block_type'],
                    ':field_name' => $ref['field_name'],
                    ':created_at' => gmdate('c'),
                ]);
        }
    }

    /** @param list<array<string, mixed>> $blocks @return list<string> */
    public function validateBlocks(array $blocks): array
    {
        $errors = [];
        foreach ($this->collectReferences($blocks) as $ref) {
            $media = $this->find((int) $ref['media_id']);
            if ($media === null || !in_array((string) ($media['status'] ?? ''), ['Active'], true)) {
                $errors[] = ucfirst($ref['block_type']) . ' block references missing or unavailable media.';
                continue;
            }
            $expected = $ref['expected_type'];
            if ($expected !== 'any' && (string) $media['media_type'] !== $expected) {
                $errors[] = ucfirst($ref['block_type']) . ' block media type mismatch.';
            }
        }

        return $errors;
    }

    /** @return array<string, mixed> */
    public function viewModel(int $id): array
    {
        $media = $this->find($id);
        if ($media === null || (string) ($media['status'] ?? '') !== 'Active') {
            return ['id' => $id, 'available' => false, 'url' => '', 'media_type' => 'missing'];
        }

        $exists = $this->storage()->exists((string) ($media['storage_key'] ?: $media['relative_path']));

        return [
            'id' => $id,
            'available' => $exists,
            'url' => '/media/' . $id,
            'download_url' => '/media/' . $id . '?download=1',
            'media_type' => (string) $media['media_type'],
            'mime_type' => (string) $media['mime_type'],
            'filename' => (string) $media['original_name'],
            'title' => (string) ($media['title'] ?: $media['original_name']),
            'description' => (string) ($media['description'] ?? ''),
            'alt_text' => (string) ($media['alt_text'] ?? ''),
            'byte_size' => (int) $media['byte_size'],
            'extension' => (string) ($media['extension'] ?? ''),
            'width' => $media['width'],
            'height' => $media['height'],
            'duration_seconds' => $media['duration_seconds'],
            'thumbnail_url' => isset($media['metadata']['derivatives']['thumbnail']) ? '/media/' . $id . '?variant=thumbnail' : '',
            'derivatives' => is_array($media['metadata']['derivatives'] ?? null) ? $media['metadata']['derivatives'] : [],
        ];
    }

    /** @return array{path:string, media:array<string,mixed>} */
    public function fileForResponse(int $id, string $variant = ''): array
    {
        $media = $this->find($id);
        if ($media === null || (string) ($media['status'] ?? '') !== 'Active') {
            throw new MediaException('Media not found.');
        }
        $path = $this->absolutePath($media, $variant);
        if (!is_file($path)) {
            throw new MediaException('Media file is missing.');
        }
        if ($variant !== '') {
            $derivative = $media['metadata']['derivatives'][$variant] ?? null;
            if (is_array($derivative) && isset($derivative['mime_type'])) {
                $media['mime_type'] = (string) $derivative['mime_type'];
                $media['original_name'] = pathinfo((string) $media['original_name'], PATHINFO_FILENAME) . '-' . $variant . '.webp';
                $media['media_type'] = 'image';
            }
        }

        return ['path' => $path, 'media' => $media];
    }

    private function ingest(string $path, string $originalName, ?int $adminId, bool $move): int
    {
        if (!is_file($path)) {
            throw new MediaException('Upload file is missing.');
        }

        $byteSize = filesize($path);
        if (!is_int($byteSize) || $byteSize <= 0) {
            throw new MediaException('Upload file is empty.');
        }
        if ($byteSize > $this->limit('max_file_bytes', 52428800)) {
            throw new MediaException('Upload file exceeds size limit.');
        }
        if ($this->currentUsage() + $byteSize > $this->limit('quota_bytes', 1073741824)) {
            throw new MediaException('Media quota exceeded.');
        }

        $safeName = $this->safeOriginalName($originalName);
        $extension = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        $this->assertSafeExtension($safeName, $extension);
        $mime = $this->detectMime($path);
        if (!in_array($mime, self::EXTENSION_MIME[$extension] ?? [], true)) {
            throw new MediaException('File extension does not match detected MIME.');
        }
        if ($mime === 'image/svg+xml' || $extension === 'svg') {
            throw new MediaException('SVG uploads are disabled.');
        }

        [$width, $height] = $this->imageDimensions($path, $extension);
        $duration = null;
        if (in_array(self::MEDIA_TYPE[$extension], ['audio', 'video'], true)) {
            $duration = ($this->probe ?? new NullMediaProbe())->probe($path, $mime)['duration_seconds'] ?? null;
        }

        $imageProcessing = null;
        $storageSource = $path;
        $storageMove = $move;
        if ((self::MEDIA_TYPE[$extension] ?? '') === 'image' && $width !== null && $height !== null) {
            $imageProcessing = $this->imageProcessor()->prepare($path, $extension, $width, $height, $this->temporaryRoot());
            $storageSource = (string) $imageProcessing['source_path'];
            $storageMove = (bool) $imageProcessing['cleanup_source'];
        }
        $storedByteSize = filesize($storageSource);
        if (!is_int($storedByteSize) || $storedByteSize <= 0) {
            $this->cleanupImageProcessing($imageProcessing);
            throw new MediaException('Upload file is empty.');
        }

        $hash = hash_file('sha256', $storageSource);
        $existing = $this->byHash($hash);
        if ($existing !== null) {
            $this->cleanupImageProcessing($imageProcessing);
            if ($move && is_file($path)) {
                unlink($path);
            }
            return (int) $existing['id'];
        }

        $relativeDir = gmdate('Y/m');
        $storageName = bin2hex(random_bytes(16)) . '.' . $extension;
        $relativePath = $relativeDir . '/' . $storageName;
        $this->storage()->put($storageSource, $relativePath, $storageMove);
        if ($move && is_file($path)) {
            unlink($path);
        }

        $metadata = ['original_name' => $safeName];
        if (is_array($imageProcessing)) {
            $metadata['privacy'] = $imageProcessing['privacy'];
            $metadata['derivatives'] = $this->storeDerivatives($imageProcessing, $relativeDir, pathinfo($storageName, PATHINFO_FILENAME));
        }

        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_media
                (storage_provider, media_type, mime_type, original_name, relative_path, storage_key, byte_size, sha256_hash, metadata_json, extension, width, height, duration_seconds, title, description, alt_text, uploaded_by, status, created_at, updated_at)
             VALUES
                (:storage_provider, :media_type, :mime_type, :original_name, :relative_path, :storage_key, :byte_size, :sha256_hash, :metadata_json, :extension, :width, :height, :duration_seconds, :title, :description, :alt_text, :uploaded_by, :status, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':storage_provider' => $this->storage()->id(),
            ':media_type' => self::MEDIA_TYPE[$extension],
            ':mime_type' => $mime,
            ':original_name' => $safeName,
            ':relative_path' => $relativePath,
            ':storage_key' => $relativePath,
            ':byte_size' => $storedByteSize,
            ':sha256_hash' => $hash,
            ':metadata_json' => $this->json($metadata),
            ':extension' => $extension,
            ':width' => $width,
            ':height' => $height,
            ':duration_seconds' => $duration,
            ':title' => pathinfo($safeName, PATHINFO_FILENAME),
            ':description' => '',
            ':alt_text' => '',
            ':uploaded_by' => $adminId,
            ':status' => 'Active',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    private function byHash(string $hash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_media WHERE sha256_hash = :hash LIMIT 1');
        $stmt->execute([':hash' => $hash]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    private function detectMime(string $path): string
    {
        if (!$this->fileinfoAvailable()) {
            throw new MediaException('当前服务器未启用 PHP Fileinfo 扩展，媒体上传功能暂时无法使用。请在服务器 PHP 环境中启用 Fileinfo 扩展后重试。');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        return (string) ($finfo->file($path) ?: 'application/octet-stream');
    }

    private function fileinfoAvailable(): bool
    {
        if (array_key_exists('fileinfo_available', $this->limits)) {
            return (bool) $this->limits['fileinfo_available'];
        }

        return extension_loaded('fileinfo') && class_exists('finfo') && defined('FILEINFO_MIME_TYPE');
    }

    private function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]+/', '', $name) ?? '';
        $cleaned = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $name);
        if (!is_string($cleaned)) {
            $cleaned = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name) ?? '';
        }
        $name = $cleaned;
        $name = trim($name, ". \t\n\r\0\x0B");

        return $name !== '' ? $name : 'upload.bin';
    }

    private function assertSafeExtension(string $name, string $extension): void
    {
        $parts = array_map('strtolower', explode('.', $name));
        if ($extension === '' || !isset(self::EXTENSION_MIME[$extension])) {
            throw new MediaException('Unsupported file extension.');
        }
        foreach (array_slice($parts, 0, -1) as $part) {
            if (in_array($part, self::DANGEROUS_EXTENSIONS, true)) {
                throw new MediaException('Unsafe double extension.');
            }
        }
        if (in_array($extension, self::DANGEROUS_EXTENSIONS, true)) {
            throw new MediaException('Executable or script uploads are not allowed.');
        }
    }

    /** @return array{0:int|null,1:int|null} */
    private function imageDimensions(string $path, string $extension): array
    {
        if ((self::MEDIA_TYPE[$extension] ?? '') !== 'image') {
            return [null, null];
        }
        $size = @getimagesize($path);
        if (!is_array($size)) {
            throw new MediaException('Invalid image file.');
        }
        $width = (int) ($size[0] ?? 0);
        $height = (int) ($size[1] ?? 0);
        if ($width <= 0 || $height <= 0 || ($width * $height) > $this->limit('max_image_pixels', 40000000)) {
            throw new MediaException('Image dimensions exceed safety limit.');
        }

        return [$width, $height];
    }

    private function currentUsage(): int
    {
        return (int) $this->pdo->query("SELECT COALESCE(SUM(byte_size), 0) FROM cms_media WHERE status <> 'Deleted'")->fetchColumn();
    }

    private function limit(string $key, int $default): int
    {
        return (int) ($this->limits[$key] ?? $default);
    }

    /** @param list<array<string, mixed>> $blocks @return list<array{media_id:int,block_type:string,field_name:string,expected_type:string}> */
    private function collectReferences(array $blocks): array
    {
        $refs = [];
        foreach ($blocks as $block) {
            $type = (string) ($block['type'] ?? '');
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            if ($type === 'image' && (int) ($data['media_id'] ?? 0) > 0) {
                $refs[] = ['media_id' => (int) $data['media_id'], 'block_type' => 'image', 'field_name' => 'media_id', 'expected_type' => 'image'];
            } elseif ($type === 'gallery') {
                foreach (($data['media_ids'] ?? []) as $id) {
                    if ((int) $id > 0) {
                        $refs[] = ['media_id' => (int) $id, 'block_type' => 'gallery', 'field_name' => 'media_ids', 'expected_type' => 'image'];
                    }
                }
                foreach (($data['items'] ?? []) as $item) {
                    if (is_array($item) && (int) ($item['media_id'] ?? 0) > 0) {
                        $refs[] = ['media_id' => (int) $item['media_id'], 'block_type' => 'gallery', 'field_name' => 'items.media_id', 'expected_type' => 'image'];
                    }
                }
            } elseif ($type === 'audio' && (int) ($data['media_id'] ?? 0) > 0) {
                $refs[] = ['media_id' => (int) $data['media_id'], 'block_type' => 'audio', 'field_name' => 'media_id', 'expected_type' => 'audio'];
            } elseif ($type === 'video') {
                if ((int) ($data['media_id'] ?? 0) > 0) {
                    $refs[] = ['media_id' => (int) $data['media_id'], 'block_type' => 'video', 'field_name' => 'media_id', 'expected_type' => 'video'];
                }
                if ((int) ($data['poster_media_id'] ?? 0) > 0) {
                    $refs[] = ['media_id' => (int) $data['poster_media_id'], 'block_type' => 'video', 'field_name' => 'poster_media_id', 'expected_type' => 'image'];
                }
            } elseif ($type === 'attachment' && (int) ($data['media_id'] ?? 0) > 0) {
                $refs[] = ['media_id' => (int) $data['media_id'], 'block_type' => 'attachment', 'field_name' => 'media_id', 'expected_type' => 'attachment'];
            }
        }

        return $refs;
    }

    /** @param array<string, mixed> $media */
    private function absolutePath(array $media, string $variant = ''): string
    {
        if ($variant !== '') {
            $derivative = $media['metadata']['derivatives'][$variant] ?? null;
            if (!is_array($derivative) || !isset($derivative['storage_key'])) {
                throw new MediaException('Media derivative not found.');
            }

            return $this->storage()->path((string) $derivative['storage_key']);
        }

        return $this->storage()->path((string) ($media['storage_key'] ?: $media['relative_path']));
    }

    private function storage(): MediaStorageProviderInterface
    {
        return $this->storageProvider ?? new LocalMediaStorageProvider($this->uploadRoot);
    }

    private function isStorageKeyUsed(string $storageKey): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM cms_media WHERE storage_key = :storage_key');
        $stmt->execute([':storage_key' => $storageKey]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function imageProcessor(): ImageDerivativeService
    {
        return $this->imageDerivativeService ?? new ImageDerivativeService();
    }

    private function temporaryRoot(): string
    {
        return rtrim($this->uploadRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.tmp';
    }

    /** @param array<string,mixed>|null $imageProcessing */
    private function cleanupImageProcessing(?array $imageProcessing): void
    {
        if (!is_array($imageProcessing)) {
            return;
        }
        if (($imageProcessing['cleanup_source'] ?? false) && is_file((string) ($imageProcessing['source_path'] ?? ''))) {
            unlink((string) $imageProcessing['source_path']);
        }
        foreach (($imageProcessing['derivatives'] ?? []) as $derivative) {
            if (is_array($derivative) && is_file((string) ($derivative['path'] ?? ''))) {
                unlink((string) $derivative['path']);
            }
        }
    }

    /** @param array<string,mixed> $imageProcessing @return array<string,array<string,mixed>> */
    private function storeDerivatives(array $imageProcessing, string $relativeDir, string $baseName): array
    {
        $stored = [];
        foreach (($imageProcessing['derivatives'] ?? []) as $name => $derivative) {
            if (!is_string($name) || !is_array($derivative) || !is_file((string) ($derivative['path'] ?? ''))) {
                continue;
            }
            $storageKey = $relativeDir . '/' . $baseName . '-' . $name . '.webp';
            $this->storage()->put((string) $derivative['path'], $storageKey, true);
            $stored[$name] = [
                'storage_key' => $storageKey,
                'mime_type' => (string) ($derivative['mime_type'] ?? 'image/webp'),
                'width' => (int) ($derivative['width'] ?? 0),
                'height' => (int) ($derivative['height'] ?? 0),
                'byte_size' => (int) ($derivative['byte_size'] ?? 0),
            ];
        }

        return $stored;
    }

    private function cleanText(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/', '', $value) ?? '';
        $value = preg_replace('/<\s*script\b[^>]*>.*?<\s*\/\s*script\s*>/is', '', $value) ?? '';

        return trim(strip_tags($value));
    }

    private function ensureUploadProtections(): void
    {
        if (!is_dir($this->uploadRoot)) {
            mkdir($this->uploadRoot, 0755, true);
        }
        $htaccess = $this->uploadRoot . '/.htaccess';
        if (!is_file($htaccess)) {
            file_put_contents($htaccess, "Options -ExecCGI\nRemoveHandler .php .phtml .phar .cgi .pl .py .rb .sh .js .html .htm\nRemoveType .php .phtml .phar .cgi .pl .py .rb .sh .js .html .htm\n<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py|rb|sh|js|html|htm)$\">\n    Require all denied\n</FilesMatch>\n");
        }
        $nginx = $this->uploadRoot . '/upload-security.nginx.conf';
        if (!is_file($nginx)) {
            file_put_contents($nginx, "location ^~ /content/uploads/ {\n    location ~* \\.(php|phtml|phar|cgi|pl|py|rb|sh|js|html|htm)$ {\n        return 403;\n    }\n}\n");
        }
    }

    /** @param array<mixed,mixed> $value */
    private function json(array $value): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new MediaException('Media metadata JSON is invalid.');
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function hydrate(array $row): array
    {
        $row['metadata'] = json_decode((string) ($row['metadata_json'] ?? '{}'), true) ?: [];
        $row['storage_key'] = (string) ($row['storage_key'] ?? $row['relative_path'] ?? '');
        $row['extension'] = (string) ($row['extension'] ?? pathinfo((string) ($row['original_name'] ?? ''), PATHINFO_EXTENSION));
        $row['status'] = (string) ($row['status'] ?? 'Active');

        return $row;
    }
}
