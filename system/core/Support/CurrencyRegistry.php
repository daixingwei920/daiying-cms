<?php

declare(strict_types=1);

namespace Cms\Core\Support;

use InvalidArgumentException;

final class CurrencyRegistry
{
    /** @return array<string,array{code:string,name:string,symbol:string,exponent:int,enabled:bool}> */
    public static function all(): array
    {
        return [
            'USD' => ['code' => 'USD', 'name' => '美元', 'symbol' => '$', 'exponent' => 2, 'enabled' => true],
            'CNY' => ['code' => 'CNY', 'name' => '人民币', 'symbol' => '¥', 'exponent' => 2, 'enabled' => true],
            'EUR' => ['code' => 'EUR', 'name' => '欧元', 'symbol' => '€', 'exponent' => 2, 'enabled' => true],
            'GBP' => ['code' => 'GBP', 'name' => '英镑', 'symbol' => '£', 'exponent' => 2, 'enabled' => true],
            'CAD' => ['code' => 'CAD', 'name' => '加拿大元', 'symbol' => 'C$', 'exponent' => 2, 'enabled' => true],
            'AUD' => ['code' => 'AUD', 'name' => '澳大利亚元', 'symbol' => 'A$', 'exponent' => 2, 'enabled' => true],
            'JPY' => ['code' => 'JPY', 'name' => '日元', 'symbol' => '¥', 'exponent' => 0, 'enabled' => true],
            'HKD' => ['code' => 'HKD', 'name' => '港币', 'symbol' => 'HK$', 'exponent' => 2, 'enabled' => true],
            'SGD' => ['code' => 'SGD', 'name' => '新加坡元', 'symbol' => 'S$', 'exponent' => 2, 'enabled' => true],
        ];
    }

    /** @return array<string,array{code:string,name:string,symbol:string,exponent:int,enabled:bool}> */
    public static function enabled(): array
    {
        return array_filter(self::all(), static fn (array $currency): bool => $currency['enabled']);
    }

    /** @return list<string> */
    public static function enabledCodes(): array
    {
        return array_keys(self::enabled());
    }

    /** @return array{code:string,name:string,symbol:string,exponent:int,enabled:bool} */
    public static function require(string $currency): array
    {
        $code = self::normalizeCode($currency);
        $record = self::all()[$code] ?? null;
        if ($record === null || !$record['enabled']) {
            throw new InvalidArgumentException('Currency is not supported.');
        }

        return $record;
    }

    public static function normalizeCode(string $currency): string
    {
        $code = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3}$/', $code)) {
            throw new InvalidArgumentException('Currency code is invalid.');
        }

        return $code;
    }

    public static function isEnabled(string $currency): bool
    {
        try {
            $record = self::require($currency);
        } catch (InvalidArgumentException) {
            return false;
        }

        return $record['enabled'];
    }

    public static function exponent(string $currency): int
    {
        return self::require($currency)['exponent'];
    }

    public static function symbol(string $currency): string
    {
        return self::require($currency)['symbol'];
    }

    public static function displayName(string $currency): string
    {
        $record = self::require($currency);

        return $record['code'] . ' — ' . $record['name'];
    }
}
