<?php

declare(strict_types=1);

namespace Cms\Core\Support;

use InvalidArgumentException;

final class Money
{
    private const MAX_MINOR = 99999999999999;

    public static function toMinor(string|int $amount, string $currency): int
    {
        $code = CurrencyRegistry::normalizeCode($currency);
        $exponent = CurrencyRegistry::exponent($code);
        $value = self::normalizeAmountString((string) $amount);
        if ($value === '') {
            throw new InvalidArgumentException('Amount is required.');
        }
        if (!preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]+))?$/', $value, $matches)) {
            throw new InvalidArgumentException('Amount format is invalid.');
        }

        $integer = $matches[1];
        $fraction = $matches[2] ?? '';
        if (strlen($fraction) > $exponent) {
            throw new InvalidArgumentException('Amount has too many decimal places for the currency.');
        }
        if ($exponent === 0 && $fraction !== '') {
            throw new InvalidArgumentException('Currency does not allow decimal places.');
        }

        $fraction = str_pad($fraction, $exponent, '0');
        $minor = ltrim($integer . $fraction, '0');
        $minor = $minor === '' ? '0' : $minor;
        if (strlen($minor) > strlen((string) self::MAX_MINOR) || (strlen($minor) === strlen((string) self::MAX_MINOR) && strcmp($minor, (string) self::MAX_MINOR) > 0)) {
            throw new InvalidArgumentException('Amount is too large.');
        }

        return (int) $minor;
    }

    public static function fromMinor(string|int $amountMinor, string $currency): string
    {
        $code = CurrencyRegistry::normalizeCode($currency);
        $exponent = CurrencyRegistry::exponent($code);
        $minor = self::minorString($amountMinor);
        if ($exponent === 0) {
            return $minor;
        }
        $padded = str_pad($minor, $exponent + 1, '0', STR_PAD_LEFT);
        $integer = substr($padded, 0, -$exponent);
        $fraction = substr($padded, -$exponent);

        return $integer . '.' . $fraction;
    }

    public static function format(string|int $amountMinor, string $currency, bool $includeCode = false): string
    {
        $code = CurrencyRegistry::normalizeCode($currency);
        $amount = self::fromMinor($amountMinor, $code);
        $label = CurrencyRegistry::symbol($code) . $amount;

        return $includeCode ? $label . ' ' . $code : $label;
    }

    private static function normalizeAmountString(string $amount): string
    {
        $value = str_replace(["\r", "\n", "\t", ' '], '', trim($amount));
        if (str_starts_with($value, '.')) {
            $value = '0' . $value;
        }

        return $value;
    }

    private static function minorString(string|int $amountMinor): string
    {
        if (is_int($amountMinor)) {
            if ($amountMinor < 0) {
                throw new InvalidArgumentException('Amount minor must be non-negative.');
            }
            return (string) $amountMinor;
        }
        if (!preg_match('/^(0|[1-9][0-9]*)$/', $amountMinor)) {
            throw new InvalidArgumentException('Amount minor is invalid.');
        }
        if (strlen($amountMinor) > strlen((string) self::MAX_MINOR) || (strlen($amountMinor) === strlen((string) self::MAX_MINOR) && strcmp($amountMinor, (string) self::MAX_MINOR) > 0)) {
            throw new InvalidArgumentException('Amount minor is too large.');
        }

        return $amountMinor;
    }
}
