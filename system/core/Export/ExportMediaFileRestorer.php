<?php

declare(strict_types=1);

namespace Cms\Core\Export;

use ZipArchive;

final class ExportMediaFileRestorer
{
    public function __construct(
        private readonly string $rootPath,
        private readonly ExportPackageReader $reader = new ExportPackageReader(),
    ) {
    }

    /** @return array{restored:int,skipped:int,files:list<array{path:string,status:string}>} */
    public function restore(string $zipPath): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ExportException('ZipArchive extension is required for export packages.');
        }

        $files = $this->reader->mediaFiles($zipPath);
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new ExportException('Unable to open export package.');
        }

        $restored = 0;
        $skipped = 0;
        $results = [];
        try {
            foreach ($files as $file) {
                $path = (string) ($file['path'] ?? '');
                $relative = $this->mediaStorageKey($path);
                $target = $this->targetPath($relative);
                $content = $zip->getFromName($path);
                if (!is_string($content)
                    || hash('sha256', $content) !== (string) ($file['sha256'] ?? '')
                    || strlen($content) !== (int) ($file['byte_size'] ?? -1)
                ) {
                    throw new ExportException('Export package media file checksum mismatch.');
                }

                if (is_file($target)) {
                    if (hash_file('sha256', $target) === (string) ($file['sha256'] ?? '')) {
                        $skipped++;
                        $results[] = ['path' => $relative, 'status' => 'skipped'];
                        continue;
                    }
                    throw new ExportException('Refusing to overwrite an existing media file with different contents.');
                }
                if (is_dir($target) || is_link($target)) {
                    throw new ExportException('Refusing to restore media file over an unsafe target.');
                }

                $this->writeFile($target, $content, (string) ($file['sha256'] ?? ''));
                $restored++;
                $results[] = ['path' => $relative, 'status' => 'restored'];
            }
        } finally {
            $zip->close();
        }

        return ['restored' => $restored, 'skipped' => $skipped, 'files' => $results];
    }

    private function mediaStorageKey(string $path): string
    {
        if (!str_starts_with($path, 'media/files/')) {
            throw new ExportException('Export package media file path is invalid.');
        }
        $relative = substr($path, strlen('media/files/'));
        if ($relative === '' || $relative !== trim($relative, '/') || str_contains($relative, '\\') || str_contains($relative, '..') || strlen($relative) > 240) {
            throw new ExportException('Export package media file path is invalid.');
        }
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part[0] === '.' || preg_match('/^[A-Za-z0-9._-]{1,96}$/', $part) !== 1) {
                throw new ExportException('Export package media file path is invalid.');
            }
        }

        return $relative;
    }

    private function targetPath(string $relative): string
    {
        $root = rtrim($this->rootPath, '/');
        if ($root === '') {
            throw new ExportException('Export media restore root is invalid.');
        }
        $uploads = $root . '/content/uploads';
        $target = $uploads . '/' . $relative;
        $normalizedUploads = $this->normalizePath($uploads);
        $normalizedTarget = $this->normalizePath($target);
        if ($normalizedTarget === $normalizedUploads || !str_starts_with($normalizedTarget, $normalizedUploads . '/')) {
            throw new ExportException('Export media restore target is invalid.');
        }

        return $target;
    }

    private function normalizePath(string $path): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return '/' . implode('/', $parts);
    }

    private function writeFile(string $target, string $content, string $sha256): void
    {
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new ExportException('Unable to create export media restore directory.');
        }
        if (is_link($dir)) {
            throw new ExportException('Refusing to restore media file into a symlinked directory.');
        }

        $tmp = $dir . '/.restore-' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            if (file_put_contents($tmp, $content, LOCK_EX) === false) {
                throw new ExportException('Unable to write restored media file.');
            }
            @chmod($tmp, 0644);
            if (hash_file('sha256', $tmp) !== $sha256) {
                throw new ExportException('Restored media file checksum mismatch.');
            }
            if (!rename($tmp, $target)) {
                throw new ExportException('Unable to finalize restored media file.');
            }
        } finally {
            if (is_file($tmp) || is_link($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
