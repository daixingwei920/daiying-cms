<?php

declare(strict_types=1);

namespace Local\Storage\Baidu;

use Cms\Core\Media\MediaProviderItem;

final class BaiduFileBrowser
{
    /** @param array<string,mixed> $row */
    public function item(array $row): MediaProviderItem
    {
        $path = (string) ($row['path'] ?? '');
        $name = (string) ($row['server_filename'] ?? basename($path));
        $isDir = (int) ($row['isdir'] ?? 0) === 1;
        $mime = $isDir ? 'inode/directory' : $this->mimeFromName($name);
        $type = $isDir ? 'folder' : $this->typeFromMime($mime);

        return new MediaProviderItem(
            BaiduTokenRepository::PLUGIN_ID,
            (string) ($row['fs_id'] ?? ''),
            'baidu://' . ltrim($path, '/'),
            $name,
            $type,
            $mime,
            max(0, (int) ($row['size'] ?? 0)),
            null,
            null,
            null,
            (string) ($row['md5'] ?? ''),
            isset($row['server_ctime']) ? gmdate('c', (int) $row['server_ctime']) : null,
            isset($row['server_mtime']) ? gmdate('c', (int) $row['server_mtime']) : null,
            [
                'fs_id' => (string) ($row['fs_id'] ?? ''),
                'category' => (int) ($row['category'] ?? 0),
                'raw_path' => $path,
            ],
        );
    }

    private function mimeFromName(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'bmp' => 'image/bmp',
            'heic' => 'image/heic',
            'heif' => 'image/heif',
            'tif', 'tiff' => 'image/tiff',
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'opus' => 'audio/opus',
            'wma' => 'audio/x-ms-wma',
            'amr' => 'audio/amr',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'mkv' => 'video/x-matroska',
            'm4v' => 'video/x-m4v',
            'avi' => 'video/x-msvideo',
            'wmv' => 'video/x-ms-wmv',
            'flv' => 'video/x-flv',
            'ts' => 'video/mp2t',
            'm3u8' => 'application/vnd.apple.mpegurl',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'md' => 'text/markdown',
            'csv' => 'text/csv',
            'rtf' => 'application/rtf',
            'epub' => 'application/epub+zip',
            'zip' => 'application/zip',
            'rar' => 'application/vnd.rar',
            '7z' => 'application/x-7z-compressed',
            'tar' => 'application/x-tar',
            'gz' => 'application/gzip',
            'bz2' => 'application/x-bzip2',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            default => 'application/octet-stream',
        };
    }

    private function typeFromMime(string $mime): string
    {
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mime, 'audio/')) {
            return 'audio';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }
        if ($mime === 'application/vnd.apple.mpegurl') {
            return 'video';
        }
        return 'attachment';
    }
}
