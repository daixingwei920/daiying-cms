<?php

declare(strict_types=1);

namespace Cms\Core\Content;

final class SearchResourceRegistry
{
    /** @var array<string,array{id:string,label:string,owner:string}> */
    private static array $resources = [
        'contents' => ['id' => 'contents', 'label' => 'Contents', 'owner' => 'core'],
        'pages' => ['id' => 'pages', 'label' => 'Pages', 'owner' => 'core'],
    ];

    public static function register(string $id, string $label, string $owner = 'core'): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*$/', $id)) {
            throw new ContentException('Search resource id is invalid.');
        }
        self::$resources[$id] = ['id' => $id, 'label' => $label !== '' ? $label : $id, 'owner' => $owner !== '' ? $owner : 'core'];
    }

    /** @return array<string,array{id:string,label:string,owner:string}> */
    public static function all(): array
    {
        ksort(self::$resources);
        return self::$resources;
    }
}
