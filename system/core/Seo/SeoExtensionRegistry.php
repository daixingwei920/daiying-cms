<?php

declare(strict_types=1);

namespace Cms\Core\Seo;

final class SeoExtensionRegistry
{
    /** @var array<string,callable(array<string,mixed>): array<string,mixed>> */
    private static array $jsonLdProviders = [];

    /** @var array<string,callable(array<string,mixed>): array<string,string>> */
    private static array $metaProviders = [];

    /** @param callable(array<string,mixed>): array<string,mixed> $provider */
    public static function registerJsonLd(string $id, callable $provider): void
    {
        self::assertId($id);
        self::$jsonLdProviders[$id] = $provider;
    }

    /** @param callable(array<string,mixed>): array<string,string> $provider */
    public static function registerMeta(string $id, callable $provider): void
    {
        self::assertId($id);
        self::$metaProviders[$id] = $provider;
    }

    /** @param array<string,mixed> $context @return list<array<string,mixed>> */
    public static function jsonLd(array $context): array
    {
        $items = [];
        foreach (self::$jsonLdProviders as $provider) {
            $value = $provider($context);
            if ($value !== []) {
                $items[] = $value;
            }
        }

        return $items;
    }

    /** @param array<string,mixed> $context @return array<string,string> */
    public static function meta(array $context): array
    {
        $meta = [];
        foreach (self::$metaProviders as $provider) {
            foreach ($provider($context) as $key => $value) {
                $meta[$key] = $value;
            }
        }

        return $meta;
    }

    private static function assertId(string $id): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,95}$/', $id)) {
            throw new \InvalidArgumentException('SEO extension id is invalid.');
        }
    }
}
