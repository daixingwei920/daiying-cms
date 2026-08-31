<?php

declare(strict_types=1);

namespace Cms\Core\Payment;

use Cms\Core\Config\Settings;
use Cms\Core\Support\CurrencyRegistry;
use InvalidArgumentException;
use PDO;

final class PaymentProviderSelector
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Settings $settings,
    ) {
    }

    public function defaultProviderId(string $currency = ''): string
    {
        $currency = $this->normalizeCurrencyFilter($currency);
        $defaultProviderIds = [];
        foreach ($this->enabledProviderSettings() as $setting) {
            $providerId = (string) ($setting['provider_id'] ?? '');
            $public = $this->publicConfig($setting);
            if (
                $public !== null
                && ($public['default_provider'] ?? null) === true
                && $this->checkoutAvailable($providerId, $setting, $currency)
            ) {
                $defaultProviderIds[] = $providerId;
            }
        }
        if (count($defaultProviderIds) === 1) {
            return $defaultProviderIds[0];
        }
        if (count($defaultProviderIds) > 1) {
            throw new PaymentException('Default payment provider configuration is ambiguous.');
        }

        foreach ($this->enabledProviderSettings() as $setting) {
            $providerId = (string) ($setting['provider_id'] ?? '');
            if ($this->checkoutAvailable($providerId, $setting, $currency)) {
                return $providerId;
            }
        }

        throw new PaymentException('No enabled payment provider is available.');
    }

    public function requireEnabled(string $providerId, string $currency = ''): string
    {
        $currency = $this->normalizeCurrencyFilter($currency);
        try {
            $normalized = PaymentProviderRegistry::normalize($providerId);
        } catch (InvalidArgumentException) {
            throw new PaymentException('Payment provider is not available.');
        }
        if ($providerId !== $normalized) {
            throw new PaymentException('Payment provider is not available.');
        }
        $providerId = $normalized;
        if (PaymentProviderRegistry::get($providerId) === null) {
            throw new PaymentException('Payment provider is not available.');
        }
        if (!$this->supportsCheckout($providerId)) {
            throw new PaymentException('Payment provider does not support checkout.');
        }
        if (!in_array($providerId, $this->enabledProviderIds(), true)) {
            throw new PaymentException('Payment provider is not enabled.');
        }
        $setting = $this->setting($providerId);
        if ($setting === null || !$this->checkoutAvailable($providerId, $setting, $currency)) {
            throw new PaymentException('Payment provider checkout configuration is unavailable.');
        }

        return $providerId;
    }

    /** @return list<array{id:string,label:string}> */
    public function enabledProviders(string $currency = ''): array
    {
        $currency = $this->normalizeCurrencyFilter($currency);
        $settings = [];
        $repo = new PaymentProviderSettingsRepository(
            $this->pdo,
            (string) $this->settings->get('security.encryption_key', ''),
        );
        foreach ($repo->all() as $setting) {
            try {
                $providerId = $this->normalizeProviderId((string) ($setting['provider_id'] ?? ''));
            } catch (InvalidArgumentException) {
                continue;
            }
            $settings[$providerId] = $setting;
        }

        $providers = [];
        foreach ($this->enabledProviderIds() as $providerId) {
            $provider = PaymentProviderRegistry::get($providerId);
            $setting = $settings[$providerId] ?? null;
            if ($provider === null || !is_array($setting) || !$this->checkoutAvailable($providerId, $setting, $currency)) {
                continue;
            }
            $label = $this->displayNameLabel((string) ($settings[$providerId]['display_name'] ?? ''));
            $providers[] = [
                'id' => $providerId,
                'label' => $label !== '' ? $label : $provider->displayName(),
            ];
        }

        return $providers;
    }

    /** @return list<string> */
    private function enabledProviderIds(): array
    {
        return array_map(
            static fn (array $setting): string => (string) ($setting['provider_id'] ?? ''),
            $this->enabledProviderSettings(),
        );
    }

    /** @return list<array<string,mixed>> */
    private function enabledProviderSettings(): array
    {
        $repo = new PaymentProviderSettingsRepository(
            $this->pdo,
            (string) $this->settings->get('security.encryption_key', ''),
        );
        $settings = [];
        foreach ($repo->all() as $setting) {
            try {
                $providerId = $this->normalizeProviderId((string) ($setting['provider_id'] ?? ''));
            } catch (InvalidArgumentException) {
                continue;
            }
            if ($providerId !== '' && (string) ($setting['status'] ?? '') === 'enabled') {
                $setting['provider_id'] = $providerId;
                $settings[$providerId] = $setting;
            }
        }

        return array_values($settings);
    }

    private function normalizeProviderId(string $providerId): string
    {
        $normalized = PaymentProviderRegistry::normalize($providerId);
        if ($providerId !== $normalized) {
            throw new InvalidArgumentException('Payment provider id is invalid.');
        }

        return $normalized;
    }

    private function supportsCheckout(string $providerId): bool
    {
        $provider = PaymentProviderRegistry::get($providerId);

        return $provider !== null && in_array('payment.create', $provider->capabilities(), true);
    }

    private function displayNameLabel(string $displayName): string
    {
        if ($displayName === '') {
            return '';
        }
        if ($displayName !== trim($displayName) || strlen($displayName) > 191 || preg_match('/[\x00-\x1F\x7F]/', $displayName) === 1 || $this->publicConfigValueContainsSecret($displayName)) {
            return '';
        }

        return $displayName;
    }

    /** @param array<string,mixed> $setting */
    private function checkoutAvailable(string $providerId, array $setting, string $currency = ''): bool
    {
        if (!$this->supportsCheckout($providerId)) {
            return false;
        }
        if ($currency !== '' && !$this->providerSupportsCurrency($providerId, $currency)) {
            return false;
        }
        $public = $this->decodePublicConfig($setting);
        if (!is_array($public)) {
            return false;
        }
        $public = $this->safePublicConfig($public);
        if ($public === null) {
            return false;
        }
        $provider = PaymentProviderRegistry::get($providerId);
        if ($provider instanceof PaymentProviderConfigurationInterface) {
            try {
                $maskedSecrets = (new PaymentProviderSettingsRepository(
                    $this->pdo,
                    (string) $this->settings->get('security.encryption_key', ''),
                ))->maskedSecrets($providerId);
            } catch (PaymentException) {
                return false;
            }
            return $provider->isConfigured($public, $maskedSecrets);
        }
        if ($providerId !== HostedRedirectPaymentProvider::PROVIDER_ID) {
            return true;
        }

        $checkoutUrl = (string) ($public['checkout_url'] ?? $public['checkout_base_url'] ?? '');
        if (!$this->httpsUrl($checkoutUrl) || $this->hasSensitiveQuery($checkoutUrl)) {
            return false;
        }
        $returnUrlBase = (string) ($public['return_url_base'] ?? '');

        return $returnUrlBase === '' || ($this->httpsUrl($returnUrlBase) && !$this->hasQuery($returnUrlBase));
    }

    private function normalizeCurrencyFilter(string $currency): string
    {
        if (trim($currency) === '') {
            return '';
        }
        try {
            $code = CurrencyRegistry::normalizeCode($currency);
            CurrencyRegistry::require($code);
            return $code;
        } catch (InvalidArgumentException) {
            throw new PaymentException('Payment currency is not supported.');
        }
    }

    private function providerSupportsCurrency(string $providerId, string $currency): bool
    {
        $provider = PaymentProviderRegistry::get($providerId);
        if (!$provider instanceof PaymentProviderCurrencySupportInterface) {
            return true;
        }
        $supported = array_map(static fn (string $code): string => strtoupper($code), $provider->supportedCurrencies());

        return in_array($currency, $supported, true);
    }

    /** @return array<string,mixed>|null */
    private function setting(string $providerId): ?array
    {
        $repo = new PaymentProviderSettingsRepository(
            $this->pdo,
            (string) $this->settings->get('security.encryption_key', ''),
        );

        return $repo->setting($providerId);
    }

    /** @param array<string,mixed> $setting @return array<string,mixed>|null */
    private function publicConfig(array $setting): ?array
    {
        $public = $this->decodePublicConfig($setting);
        if (!is_array($public)) {
            return null;
        }

        return $this->safePublicConfig($public);
    }

    /** @param array<string,mixed> $setting @return array<string,mixed>|null */
    private function decodePublicConfig(array $setting): ?array
    {
        $raw = (string) ($setting['public_config_json'] ?? '{}');
        if ($raw === '') {
            $raw = '{}';
        }
        if ($raw !== trim($raw)) {
            return null;
        }
        try {
            $public = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($public)) {
            return null;
        }
        try {
            $canonicalJson = $raw === '{}' && $public === []
                ? '{}'
                : json_encode($public, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_string($canonicalJson) || $raw !== $canonicalJson) {
            return null;
        }

        return $public;
    }

    /** @param array<string,mixed> $config @return array<string,mixed>|null */
    private function safePublicConfig(array $config): ?array
    {
        $safe = [];
        foreach ($config as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $key) !== 1) {
                return null;
            }
            if (preg_match('/password|secret|token|authorization|signature|auth|api[_-]?key|access[_-]?key|private/i', $key) === 1) {
                return null;
            }
            if ($key === 'default_provider' && !is_bool($value) && $value !== null) {
                return null;
            }
            if (!(is_scalar($value) || $value === null)) {
                return null;
            }
            if (($key === 'return_url_base' || str_contains(strtolower($key), 'url')) && $value !== null && !is_string($value)) {
                return null;
            }
            if (is_string($value) && ($value !== trim($value) || strlen($value) > 4096 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1)) {
                return null;
            }
            if (is_string($value) && $this->publicConfigValueContainsSecret($value)) {
                return null;
            }
            if ($key === 'return_url_base' && is_string($value) && !$this->safeReturnUrlBase($value)) {
                return null;
            }
            if ($key !== 'return_url_base' && str_contains(strtolower($key), 'url') && is_string($value) && !$this->safePublicUrl($value)) {
                return null;
            }
            $safe[$key] = $value;
        }

        return $safe;
    }

    private function httpsUrl(string $url): bool
    {
        if ($url === '' || $url !== trim($url) || strlen($url) > 2048) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && trim((string) ($parts['host'] ?? '')) !== ''
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['fragment']);
    }

    private function safePublicUrl(string $url): bool
    {
        if ($url === '') {
            return true;
        }
        if ($url !== trim($url) || strlen($url) > 2048) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            return false;
        }
        if ($this->publicConfigValueContainsSecret(rawurldecode((string) ($parts['path'] ?? '')))) {
            return false;
        }

        return !$this->hasSensitiveQuery($url);
    }

    private function safeReturnUrlBase(string $url): bool
    {
        return $url === '' || ($this->httpsUrl($url) && !$this->hasQuery($url));
    }

    private function publicConfigValueContainsSecret(string $value): bool
    {
        $pattern = '/(?:bearer\s+|payment_token=|sk_[A-Za-z0-9_=-]+|api[_-]?key=|access[_-]?key=|secret=|signature=)/i';

        return preg_match($pattern, $value) === 1
            || preg_match($pattern, rawurldecode($value)) === 1;
    }

    private function hasSensitiveQuery(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['query'])) {
            return false;
        }
        parse_str((string) $parts['query'], $query);
        foreach ($query as $key => $value) {
            if (preg_match('/token|secret|signature|authorization|auth|key|password|private/i', (string) $key) === 1) {
                return true;
            }
            if (!is_scalar($value)) {
                return true;
            }
            if ($this->publicConfigValueContainsSecret(rawurldecode((string) $value))) {
                return true;
            }
        }

        return false;
    }

    private function hasQuery(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && isset($parts['query']);
    }
}
