<?php

declare(strict_types=1);

namespace Cms\Core\Payment;

use Cms\Core\Support\CurrencyRegistry;

final class HostedRedirectPaymentProvider implements PaymentProviderInterface, PaymentProviderCurrencySupportInterface
{
    public const PROVIDER_ID = 'core.hosted-redirect';

    public function providerId(): string
    {
        return self::PROVIDER_ID;
    }

    public function displayName(): string
    {
        return '核心托管跳转支付';
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return ['payment.create', 'payment.status'];
    }

    /** @return list<string> */
    public function supportedCurrencies(): array
    {
        return CurrencyRegistry::enabledCodes();
    }

    public function createPayment(object $command): PaymentResult
    {
        $public = is_array($command->provider_public_config ?? null) ? $command->provider_public_config : [];
        $secrets = is_array($command->provider_secret_config ?? null) ? $command->provider_secret_config : [];
        $checkoutUrl = $this->publicUrlValue($public, ['checkout_url', 'checkout_base_url'], true);
        if (!$this->isSafeUrl($checkoutUrl)) {
            throw new PaymentException('Hosted payment checkout URL is not configured.');
        }
        if ($this->hasSensitiveQuery($checkoutUrl)) {
            throw new PaymentException('Hosted payment checkout URL cannot contain sensitive query parameters.');
        }

        $remoteId = $this->remoteId($command);
        $metadata = is_array($command->metadata ?? null) ? $command->metadata : [];
        $returnUrlBase = $this->publicUrlValue($public, ['return_url_base'], false);
        if ($returnUrlBase !== '' && !$this->isSafeUrl($returnUrlBase)) {
            throw new PaymentException('Hosted payment return URL base must use HTTPS.');
        }
        if ($returnUrlBase !== '' && $this->hasQuery($returnUrlBase)) {
            throw new PaymentException('Hosted payment return URL base cannot contain query parameters.');
        }
        if ($returnUrlBase !== '' && $this->hasSensitiveQuery($returnUrlBase)) {
            throw new PaymentException('Hosted payment return URL base cannot contain sensitive query parameters.');
        }
        $returnUrl = $this->absoluteReturnUrl($this->metadataUrlValue($metadata, 'success_url'), $returnUrlBase, true);
        $cancelUrl = $this->absoluteReturnUrl($this->metadataUrlValue($metadata, 'cancel_url'), $returnUrlBase, false);
        $params = [
            'cms_payment_id' => $remoteId,
            'cms_subject_type' => $this->optionalCommandString($command, 'subject_type', 'Hosted payment subject type is invalid.') ?? '',
            'cms_subject_id' => $this->optionalCommandString($command, 'subject_id', 'Hosted payment subject id is invalid.') ?? '',
            'amount_minor' => (string) $this->commandAmountMinor($command),
            'currency' => $this->commandCurrency($command),
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
        ];
        $signatureSecret = $this->checkoutSignatureSecret($secrets);
        if ($signatureSecret !== '') {
            $params['cms_signature'] = hash_hmac('sha256', implode('|', [
                $remoteId,
                $params['amount_minor'],
                $params['currency'],
                $this->optionalCommandString($command, 'idempotency_key', 'Hosted payment idempotency key is invalid.') ?? '',
                $returnUrl,
            ]), $signatureSecret);
        }

        return new PaymentResult(true, 'hosted_redirect_pending', 'Hosted checkout redirect was created.', false, $remoteId, [
            'status' => 'pending',
            'provider_payment_id' => $remoteId,
            'checkout_url' => $this->appendQuery($checkoutUrl, $params),
        ]);
    }

    public function capturePayment(object $command): PaymentResult
    {
        return new PaymentResult(false, 'hosted_redirect_unsupported', 'Hosted redirect capture must be confirmed by webhook status.', false);
    }

    public function cancelPayment(object $command): PaymentResult
    {
        return new PaymentResult(false, 'hosted_redirect_unsupported', 'Hosted redirect cancellation must be confirmed by webhook status.', false);
    }

    public function refundPayment(object $command): PaymentResult
    {
        return new PaymentResult(false, 'hosted_redirect_unsupported', 'Hosted redirect refunds must be handled by a Provider-specific adapter or webhook.', false);
    }

    public function getPaymentStatus(object $command): PaymentResult
    {
        $current = $this->optionalCommandString($command, 'current_status', 'Hosted payment current status is invalid.') ?? 'pending';
        if (!in_array($current, ['pending', 'authorized', 'paid', 'partially_refunded', 'refunded', 'failed', 'cancelled'], true)) {
            throw new PaymentException('Hosted payment current status is invalid.');
        }
        $remoteId = $this->optionalCommandString($command, 'provider_payment_id', 'Hosted payment remote reference is invalid.') ?? '';

        return new PaymentResult(true, 'hosted_redirect_status_pending', 'Hosted redirect status is trusted only after a signed webhook updates Core.', false, $remoteId, [
            'status' => $current,
            'provider_payment_id' => $remoteId,
        ]);
    }

    private function remoteId(object $command): string
    {
        $seed = implode(':', [
            $this->optionalCommandString($command, 'subject_type', 'Hosted payment subject type is invalid.') ?? '',
            $this->optionalCommandString($command, 'subject_id', 'Hosted payment subject id is invalid.') ?? '',
            (string) $this->commandAmountMinor($command),
            $this->commandCurrency($command),
            $this->optionalCommandString($command, 'idempotency_key', 'Hosted payment idempotency key is invalid.') ?? '',
        ]);

        return 'core-hosted-pay-' . substr(hash('sha256', $seed), 0, 32);
    }

    private function optionalCommandString(object $command, string $key, string $message): ?string
    {
        if (!property_exists($command, $key) || $command->{$key} === null) {
            return null;
        }
        if (!is_string($command->{$key})) {
            throw new PaymentException($message);
        }
        if (!$this->commandStringSafe($command->{$key})) {
            throw new PaymentException($message);
        }

        return $command->{$key};
    }

    private function commandStringSafe(string $value): bool
    {
        return $value === ''
            || (
                $value === trim($value)
                && strlen($value) <= 191
                && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
                && !$this->commandStringContainsSecret($value)
            );
    }

    private function commandStringContainsSecret(string $value): bool
    {
        $pattern = '/(?:bearer\s+|payment_token=|sk_[A-Za-z0-9_=-]+|api[_-]?key=|access[_-]?key=|secret=|signature=)/i';
        $decodedValue = rawurldecode($value);

        return preg_match($pattern, $value) === 1
            || $decodedValue !== trim($decodedValue)
            || preg_match('/[\x00-\x1F\x7F]/', $decodedValue) === 1
            || preg_match($pattern, $decodedValue) === 1;
    }

    private function commandAmountMinor(object $command): int
    {
        $amount = $command->amount_minor ?? null;
        $normalized = is_int($amount) ? $amount : (is_string($amount) && preg_match('/^[0-9]+$/', $amount) === 1 ? (int) $amount : 0);
        if ($normalized <= 0 || (string) $amount !== (string) $normalized) {
            throw new PaymentException('Hosted payment amount is invalid.');
        }

        return $normalized;
    }

    private function commandCurrency(object $command): string
    {
        $currency = $command->currency ?? '';
        if (!is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new PaymentException('Hosted payment currency is invalid.');
        }

        return $currency;
    }

    /**
     * @param array<string,mixed> $public
     * @param list<string> $keys
     */
    private function publicUrlValue(array $public, array $keys, bool $required): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $public) || $public[$key] === null) {
                continue;
            }
            if (!is_string($public[$key])) {
                throw new PaymentException($required ? 'Hosted payment checkout URL is not configured.' : 'Hosted payment return URL base must use HTTPS.');
            }

            return $public[$key];
        }

        return '';
    }

    /** @param array<string,mixed> $metadata */
    private function metadataUrlValue(array $metadata, string $key): string
    {
        if (!array_key_exists($key, $metadata) || $metadata[$key] === null) {
            return '';
        }
        if (!is_string($metadata[$key])) {
            throw new PaymentException('Hosted payment return URL is not canonical.');
        }

        return $metadata[$key];
    }

    /** @param array<string,mixed> $secrets */
    private function checkoutSignatureSecret(array $secrets): string
    {
        $secret = $secrets['checkout_secret'] ?? '';
        if ($secret === '') {
            return '';
        }
        if (!is_string($secret) || $secret !== trim($secret) || strlen($secret) < 16 || strlen($secret) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $secret) === 1) {
            throw new PaymentException('Hosted payment checkout signing secret is invalid.');
        }

        return $secret;
    }

    /** @param array<string,string> $params */
    private function appendQuery(string $url, array $params): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function absoluteReturnUrl(string $url, string $base, bool $completion): string
    {
        if ($url === '') {
            return '';
        }
        if ($url !== trim($url) || $base !== trim($base)) {
            throw new PaymentException('Hosted payment return URL is not canonical.');
        }
        $base = rtrim($base, '/');
        if ($base !== '' && $url[0] === '/' && !str_starts_with($url, '//') && $this->isSafeUrl($base) && $this->isCoreReturnPath($url, $completion)) {
            return $base . $url;
        }

        throw new PaymentException('Hosted payment return URL is not safe.');
    }

    private function isCoreReturnPath(string $path, bool $completion): bool
    {
        if ($completion) {
            return preg_match('#^/paid-content/[1-9][0-9]{0,17}/complete\?payment_key=[A-Za-z0-9._~-]{1,191}&claim=[a-f0-9]{64}$#', $path) === 1
                || preg_match('#^/paid-download/[1-9][0-9]{0,17}/[1-9][0-9]{0,17}/complete\?payment_key=[A-Za-z0-9._~-]{1,191}&claim=[a-f0-9]{64}$#', $path) === 1;
        }

        return preg_match('#^/(?:articles/[a-z0-9][a-z0-9-]{0,190}|[a-z0-9][a-z0-9-]{0,190})$#', $path) === 1;
    }

    private function isSafeUrl(string $url): bool
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

    private function urlValueContainsSecret(string $value): bool
    {
        return preg_match('/(?:bearer\s+|payment_token=|sk_[A-Za-z0-9_=-]+|api[_-]?key=|access[_-]?key=|secret=|signature=)/i', $value) === 1;
    }

    private function hasSensitiveQuery(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        if ($this->urlValueContainsSecret(rawurldecode((string) ($parts['path'] ?? '')))) {
            return true;
        }
        if (!isset($parts['query'])) {
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
            if ($this->urlValueContainsSecret(rawurldecode((string) $value))) {
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
