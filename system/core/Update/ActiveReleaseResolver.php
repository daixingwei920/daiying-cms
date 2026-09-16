<?php

declare(strict_types=1);

namespace Cms\Core\Update;

final class ActiveReleaseResolver
{
    /** @return array{release_id?:string,version?:string,path?:string,build?:string,switched_at?:string} */
    public static function read(string $rootPath): array
    {
        $pointerFile = rtrim($rootPath, '/') . '/storage/updates/current-release.json';
        if (!is_file($pointerFile)) {
            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($pointerFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $version = self::safeScalar($decoded['version'] ?? null);
        if ($version === '' || preg_match('/^[0-9]+(?:\.[0-9]+){1,3}(?:[-+][A-Za-z0-9_.-]+)?$/', $version) !== 1) {
            return [];
        }

        $release = [
            'version' => $version,
        ];
        foreach (['release_id', 'path', 'build', 'switched_at'] as $key) {
            $value = self::safeScalar($decoded[$key] ?? null);
            if ($value !== '') {
                $release[$key] = $value;
            }
        }

        return $release;
    }

    public static function version(string $rootPath, string $fallback): string
    {
        $release = self::read($rootPath);
        $version = (string) ($release['version'] ?? '');

        return $version !== '' ? $version : $fallback;
    }

    private static function safeScalar(mixed $value): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return '';
        }

        $text = trim((string) $value);
        if ($text === '' || strlen($text) > 255 || preg_match('/[\x00-\x1F\x7F]/', $text) === 1) {
            return '';
        }

        return $text;
    }
}
