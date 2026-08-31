<?php

declare(strict_types=1);

namespace Cms\Core\Content;

final class Slugger
{
    public static function make(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9\p{Han}]+/u', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'content-' . date('YmdHis');
    }
}
