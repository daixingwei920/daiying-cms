<?php

declare(strict_types=1);

namespace Cms\Core\Mail;

final class MailEventRegistry
{
    /** @var array<string,array{id:string,label:string,variables:list<string>,owner:string}> */
    private static array $events = [];

    public static function register(string $eventId, string $label, array $variables = [], string $owner = 'core'): void
    {
        if (preg_match('/^[a-z0-9._-]{2,120}$/', $eventId) !== 1) {
            throw new MailException('Mail event id is invalid.');
        }
        self::$events[$eventId] = [
            'id' => $eventId,
            'label' => self::label($label),
            'variables' => self::variables($variables),
            'owner' => self::label($owner),
        ];
    }

    /** @return array<string,array{id:string,label:string,variables:list<string>,owner:string}> */
    public static function all(): array
    {
        self::ensureDefaults();
        return self::$events;
    }

    public static function clear(): void
    {
        self::$events = [];
    }

    private static function ensureDefaults(): void
    {
        if (self::$events !== []) {
            return;
        }
        foreach ([
            'user.registered',
            'user.password_reset',
            'order.created',
            'order.paid',
            'order.refunded',
            'license.issued',
            'plugin.reviewed',
            'system.error',
            'system.update',
            'security.alert',
        ] as $eventId) {
            self::register($eventId, $eventId, ['site_name'], 'core');
        }
    }

    private static function label(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 191 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new MailException('Mail event metadata is invalid.');
        }

        return $value;
    }

    /** @return list<string> */
    private static function variables(array $variables): array
    {
        $clean = [];
        foreach ($variables as $variable) {
            $variable = trim((string) $variable);
            if ($variable !== '' && preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $variable) === 1) {
                $clean[] = $variable;
            }
        }

        return array_values(array_unique($clean));
    }
}
