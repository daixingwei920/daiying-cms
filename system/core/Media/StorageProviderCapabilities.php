<?php

declare(strict_types=1);

namespace Cms\Core\Media;

final class StorageProviderCapabilities
{
    public const READ = 'read';
    public const UPLOAD = 'upload';
    public const DELETE = 'delete';
    public const MOVE = 'move';
    public const METADATA = 'metadata';
    public const METADATA_REFRESH = 'metadata_refresh';
    public const PUBLIC_URL = 'public_url';
    public const SIGNED_URL = 'signed_url';
    public const PROXY_URL = 'proxy_url';
    public const PROXY_STREAM = 'proxy_stream';
    public const BYTE_RANGE = 'byte_range';
    public const DOWNLOAD = 'download';
    public const READ_ONLY = 'read_only';
    public const TEST_CONNECTION = 'test_connection';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::READ,
            self::UPLOAD,
            self::DELETE,
            self::MOVE,
            self::METADATA,
            self::METADATA_REFRESH,
            self::PUBLIC_URL,
            self::SIGNED_URL,
            self::PROXY_URL,
            self::PROXY_STREAM,
            self::BYTE_RANGE,
            self::DOWNLOAD,
            self::READ_ONLY,
            self::TEST_CONNECTION,
        ];
    }

    /** @param list<string> $capabilities @return list<string> */
    public static function normalize(array $capabilities): array
    {
        $known = array_flip(self::all());
        $normalized = [];
        foreach ($capabilities as $capability) {
            $capability = strtolower(trim($capability));
            if ($capability !== '' && isset($known[$capability])) {
                $normalized[] = $capability;
            }
        }

        return array_values(array_unique($normalized));
    }
}
