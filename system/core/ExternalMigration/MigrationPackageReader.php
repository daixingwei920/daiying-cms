<?php

declare(strict_types=1);

namespace Cms\Core\ExternalMigration;

use ZipArchive;

final class MigrationPackageReader
{
    private const MAX_ENTRIES = 20000;
    private const MAX_TOTAL_BYTES = 536870912;
    private const BLOCKED_EXTENSIONS = ['php', 'phtml', 'phar', 'cgi', 'pl', 'py', 'rb', 'sh', 'exe', 'dll', 'bat', 'cmd', 'com', 'scr'];

    /** @return array<string,mixed> */
    public function read(string $zipPath): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new MigrationException('当前服务器未启用 PHP ZipArchive 扩展，无法读取迁移包。');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new MigrationException('迁移包 ZIP 打开失败。');
        }
        try {
            $this->assertSafeZip($zip);
            $manifest = $this->jsonEntry($zip, 'manifest.json');
            if ((string) ($manifest['migration_package_version'] ?? '') !== '1') {
                throw new MigrationException('Unsupported source version');
            }
            $site = $this->jsonEntry($zip, 'site.json', false);
            $users = $this->jsonListEntry($zip, 'users.json');
            $categories = $this->jsonListEntry($zip, 'categories.json');
            $tags = $this->jsonListEntry($zip, 'tags.json');
            $contents = $this->jsonlEntry($zip, 'contents.jsonl');
            if ($contents === []) {
                $contents = $this->jsonlEntryFromPath($zipPath, 'contents.jsonl');
            }
            $media = $this->jsonListEntry($zip, 'media.json');
            $comments = $this->jsonlEntry($zip, 'comments.jsonl');
            if ($comments === []) {
                $comments = $this->jsonlEntryFromPath($zipPath, 'comments.jsonl');
            }
            $redirects = $this->jsonListEntry($zip, 'redirects.json');
            $metadata = $this->jsonEntry($zip, 'metadata.json', false);
            $package = [
                'migration_package_version' => '1',
                'source_system' => (string) ($manifest['source_system'] ?? 'daiying_package'),
                'source_version' => (string) ($manifest['source_version'] ?? ''),
                'site' => $site ?: ['source_site_id' => 'package:' . substr(hash_file('sha256', $zipPath), 0, 16)],
                'users' => $users,
                'categories' => $categories,
                'tags' => $tags,
                'contents' => $contents,
                'media' => $media,
                'comments' => $comments,
                'redirects' => $redirects,
                'metadata' => $metadata,
            ];
            $this->attachMediaLocalPaths($zip, $package);

            return $package;
        } finally {
            $zip->close();
        }
    }

    private function assertSafeZip(ZipArchive $zip): void
    {
        if ($zip->numFiles <= 0 || $zip->numFiles > self::MAX_ENTRIES) {
            throw new MigrationException('迁移包文件数量不在允许范围内。');
        }
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
            if (!$this->safeEntryName($name)) {
                throw new MigrationException('迁移包包含不安全路径。');
            }
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
                throw new MigrationException('迁移包包含不允许的可执行文件。');
            }
            $total += (int) ($stat['size'] ?? 0);
            if ($total > self::MAX_TOTAL_BYTES) {
                throw new MigrationException('迁移包超过大小限制。');
            }
        }
    }

    private function safeEntryName(string $name): bool
    {
        return $name !== ''
            && !str_starts_with($name, '/')
            && !str_contains($name, '\\')
            && !str_contains($name, "\0")
            && !str_contains($name, '../')
            && !str_contains('/' . $name, '/../')
            && !str_ends_with($name, '/..');
    }

    /** @return array<string,mixed> */
    private function jsonEntry(ZipArchive $zip, string $name, bool $required = true): array
    {
        $content = $zip->getFromName($name);
        if (!is_string($content)) {
            if ($required) {
                throw new MigrationException('迁移包缺少 ' . $name . '。');
            }
            return [];
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new MigrationException($name . ' 解析失败。');
        }

        return $decoded;
    }

    /** @return list<array<string,mixed>> */
    private function jsonListEntry(ZipArchive $zip, string $name): array
    {
        $decoded = $this->jsonEntry($zip, $name, false);
        return array_values(array_filter($decoded, static fn (mixed $item): bool => is_array($item)));
    }

    /** @return list<array<string,mixed>> */
    private function jsonlEntry(ZipArchive $zip, string $name): array
    {
        $content = $this->entryContent($zip, $name);
        if (!is_string($content) || trim($content) === '') {
            return [];
        }
        $items = [];
        foreach ($this->jsonlLines($content) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $items[] = $decoded;
            }
        }

        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function jsonlEntryFromPath(string $zipPath, string $name): array
    {
        $content = @file_get_contents('zip://' . $zipPath . '#' . $name);
        if (!is_string($content) || trim($content) === '') {
            return [];
        }
        $items = [];
        foreach ($this->jsonlLines($content) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $items[] = $decoded;
            }
        }

        return $items;
    }

    /** @return list<string> */
    private function jsonlLines(string $content): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", trim($content));
        return array_values(array_filter(explode("\n", $normalized), static fn (string $line): bool => trim($line) !== ''));
    }

    private function entryContent(ZipArchive $zip, string $name): string|false
    {
        $content = $zip->getFromName($name);
        if (is_string($content)) {
            return $content;
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (is_array($stat) && (string) ($stat['name'] ?? '') === $name) {
                return $zip->getFromIndex($i);
            }
        }

        return false;
    }

    /** @param array<string,mixed> $package */
    private function attachMediaLocalPaths(ZipArchive $zip, array &$package): void
    {
        if (!is_array($package['media'] ?? null)) {
            return;
        }
        $tmp = sys_get_temp_dir() . '/daiying-migration-package-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0700, true);
        foreach ($package['media'] as &$media) {
            if (!is_array($media)) {
                continue;
            }
            $entry = ltrim((string) ($media['package_path'] ?? $media['path'] ?? ''), '/');
            if ($entry === '') {
                continue;
            }
            if (!str_starts_with($entry, 'media/') || !$this->safeEntryName($entry)) {
                throw new MigrationException('迁移包媒体路径不安全。');
            }
            $content = $zip->getFromName($entry);
            if (!is_string($content) || $content === '') {
                continue;
            }
            $target = $tmp . '/' . basename($entry);
            file_put_contents($target, $content, LOCK_EX);
            $media['local_path'] = $target;
        }
        unset($media);
    }
}
