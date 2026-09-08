<?php

declare(strict_types=1);

namespace Cms\Core\Mail;

final class MailProviderRegistry
{
    /** @var array<string,MailProviderInterface> */
    private static array $providers = [];

    public static function register(MailProviderInterface $provider): void
    {
        if (!preg_match('/^[a-z0-9._-]{2,120}$/', $provider->id())) {
            throw new MailException('Mail provider id is invalid.');
        }
        self::$providers[$provider->id()] = $provider;
    }

    public static function get(string $id): ?MailProviderInterface
    {
        self::ensureDefaults();
        return self::$providers[$id] ?? null;
    }

    /** @return array<string,MailProviderInterface> */
    public static function all(): array
    {
        self::ensureDefaults();
        return self::$providers;
    }

    public static function clear(): void
    {
        self::$providers = [];
    }

    private static function ensureDefaults(): void
    {
        if (self::$providers === []) {
            self::register(new PhpMailProvider());
            self::register(new SmtpMailProvider());
        }
    }
}
