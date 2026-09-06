<?php

declare(strict_types=1);

namespace Cms\Core\Support;

final class PublicApiRegistry
{
    public const CONTRACT_VERSION = '1.2.0';

    /** @return list<array{id:string,version:string,class:string,stability:string,summary:string,capabilities:list<string>}> */
    public static function contracts(): array
    {
        return [
            [
                'id' => 'content.repository',
                'version' => self::CONTRACT_VERSION,
                'class' => 'Cms\\Core\\Content\\ContentRepository',
                'stability' => 'stable',
                'summary' => 'Create, update, list and read CMS content through the Core content model.',
                'capabilities' => ['content.read', 'content.write'],
            ],
            [
                'id' => 'media.library',
                'version' => self::CONTRACT_VERSION,
                'class' => 'Cms\\Core\\Media\\MediaLibrary',
                'stability' => 'stable',
                'summary' => 'Register, deduplicate, read and localize managed media records.',
                'capabilities' => ['media.read', 'media.write'],
            ],
            [
                'id' => 'payment.service',
                'version' => self::CONTRACT_VERSION,
                'class' => 'Cms\\Core\\Payment\\PaymentService',
                'stability' => 'stable',
                'summary' => 'Create provider payments and handle trusted paid state transitions.',
                'capabilities' => ['payment.create', 'payment.capture'],
            ],
            [
                'id' => 'plugin.context',
                'version' => self::CONTRACT_VERSION,
                'class' => 'Cms\\Core\\Plugin\\PluginContext',
                'stability' => 'stable',
                'summary' => 'Expose the bounded plugin runtime registration surface.',
                'capabilities' => ['blocks.register', 'admin.menu'],
            ],
            [
                'id' => 'theme.runtime',
                'version' => self::CONTRACT_VERSION,
                'class' => 'Cms\\Core\\Theme\\ThemeRuntime',
                'stability' => 'stable',
                'summary' => 'Render templates through the Core theme runtime and fallback chain.',
                'capabilities' => ['theme.render'],
            ],
            [
                'id' => 'market.client',
                'version' => self::CONTRACT_VERSION,
                'class' => 'Cms\\Core\\Market\\MarketPackageInstaller',
                'stability' => 'stable',
                'summary' => 'Verify authorized Market packages and install reviewed extensions.',
                'capabilities' => ['market.install'],
            ],
        ];
    }

    /** @return array{id:string,version:string,class:string,stability:string,summary:string,capabilities:list<string>}|null */
    public static function contract(string $id): ?array
    {
        foreach (self::contracts() as $contract) {
            if ($contract['id'] === $id) {
                return $contract;
            }
        }

        return null;
    }

    public static function isPublicClass(string $class): bool
    {
        foreach (self::contracts() as $contract) {
            if ($contract['class'] === $class) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(static fn (array $contract): string => $contract['id'], self::contracts());
    }
}
