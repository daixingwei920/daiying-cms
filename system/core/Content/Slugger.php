<?php

declare(strict_types=1);

namespace Cms\Core\Content;

final class Slugger
{
    public static function fromInput(string $value, string $fallback): string
    {
        if ($value === '') {
            return self::make($fallback);
        }
        if (str_contains($value, '..') || preg_match('/[\/\\\\%?#\x00-\x1F\x7F]/', $value) !== 0) {
            throw new ContentException('Slug contains unsafe path characters.');
        }
        $slug = preg_replace('/[^\p{L}\p{N}《》-]+/u', '-', strtolower(trim($value))) ?? '';
        $slug = trim($slug, '-');
        if (!self::isPublic($slug)) {
            throw new ContentException('Slug is invalid or exceeds 191 bytes.');
        }

        return $slug;
    }

    public static function isPublic(string $slug): bool
    {
        return $slug !== '' && strlen($slug) <= 191
            && preg_match('/^[\p{L}\p{N}《》-]+$/u', $slug) === 1;
    }

    public static function make(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'content-' . date('YmdHis');
    }
}
