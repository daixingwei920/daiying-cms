<?php

declare(strict_types=1);

namespace Cms\Core\Payment;

use Cms\Core\Support\CurrencyRegistry;

final class ManualPaymentProvider implements PaymentProviderInterface, PaymentProviderCurrencySupportInterface
{
    public const PROVIDER_ID = 'core.manual-payment';

    public function providerId(): string
    {
        return self::PROVIDER_ID;
    }

    public function displayName(): string
    {
        return '核心人工确认支付';
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return ['payment.create', 'payment.capture', 'payment.cancel', 'payment.refund', 'payment.status'];
    }

    /** @return list<string> */
    public function supportedCurrencies(): array
    {
        return CurrencyRegistry::enabledCodes();
    }

    public function createPayment(object $command): PaymentResult
    {
        $remoteId = $this->remoteId('pay', $command);
        $public = is_array($command->provider_public_config ?? null) ? $command->provider_public_config : [];
        $instructions = $this->instructions($public['instructions'] ?? '请按站点说明完成线下付款，管理员确认后解锁内容。');

        return new PaymentResult(true, 'manual_payment_pending', 'Manual payment is awaiting administrator confirmation.', false, $remoteId, [
            'status' => 'pending',
            'provider_payment_id' => $remoteId,
            'manual_reference' => $remoteId,
            'manual_instructions' => $instructions,
        ]);
    }

    public function capturePayment(object $command): PaymentResult
    {
        $remoteId = $this->remoteReference($this->optionalCommandString($command, 'provider_payment_id', 'Manual payment remote reference is invalid.') ?? '');
        if ($remoteId === '') {
            $remoteId = $this->remoteId('capture', $command);
        }

        return new PaymentResult(true, 'manual_payment_confirmed', 'Manual payment was confirmed by an administrator.', false, $remoteId, [
            'status' => 'paid',
            'provider_payment_id' => $remoteId,
        ]);
    }

    public function cancelPayment(object $command): PaymentResult
    {
        $remoteId = $this->remoteReference($this->optionalCommandString($command, 'provider_payment_id', 'Manual payment remote reference is invalid.') ?? '');
        if ($remoteId === '') {
            $remoteId = $this->remoteId('cancel', $command);
        }

        return new PaymentResult(true, 'manual_payment_cancelled', 'Manual payment was cancelled by an administrator.', false, $remoteId, [
            'status' => 'cancelled',
            'provider_payment_id' => $remoteId,
        ]);
    }

    public function refundPayment(object $command): PaymentResult
    {
        return new PaymentResult(true, 'manual_refund_recorded', 'Manual refund was recorded by an administrator.', false, '', [
            'status' => 'completed',
            'provider_refund_id' => $this->remoteId('refund', $command),
        ]);
    }

    public function getPaymentStatus(object $command): PaymentResult
    {
        $status = $this->optionalCommandString($command, 'current_status', 'Manual payment current status is invalid.') ?? 'pending';
        if (!in_array($status, ['pending', 'authorized', 'paid', 'failed', 'cancelled'], true)) {
            throw new PaymentException('Manual payment current status is invalid.');
        }
        $remoteId = $this->optionalCommandString($command, 'provider_payment_id', 'Manual payment remote reference is invalid.') ?? '';

        return new PaymentResult(true, 'manual_payment_status', 'Manual payment status is managed by CMS administrators.', false, $remoteId, [
            'status' => $status,
            'provider_payment_id' => $remoteId,
        ]);
    }

    private function remoteId(string $kind, object $command): string
    {
        $seed = implode(':', [
            $kind,
            $this->optionalCommandString($command, 'subject_type', 'Manual payment subject type is invalid.') ?? '',
            $this->optionalCommandString($command, 'subject_id', 'Manual payment subject id is invalid.')
                ?? $this->optionalCommandPositiveIntString($command, 'payment_id', 'Manual payment subject id is invalid.')
                ?? '0',
            (string) $this->commandAmountMinor($command),
            $this->commandCurrency($command),
            $this->optionalCommandString($command, 'idempotency_key', 'Manual payment idempotency key is invalid.') ?? '',
        ]);

        return 'core-manual-' . $kind . '-' . substr(hash('sha256', $seed), 0, 24);
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

    private function optionalCommandPositiveIntString(object $command, string $key, string $message): ?string
    {
        if (!property_exists($command, $key) || $command->{$key} === null) {
            return null;
        }
        if (!is_int($command->{$key}) || $command->{$key} <= 0) {
            throw new PaymentException($message);
        }

        return (string) $command->{$key};
    }

    private function commandAmountMinor(object $command): int
    {
        $amount = $command->amount_minor ?? null;
        $normalized = is_int($amount) ? $amount : (is_string($amount) && preg_match('/^[0-9]+$/', $amount) === 1 ? (int) $amount : 0);
        if ($normalized <= 0 || (string) $amount !== (string) $normalized) {
            throw new PaymentException('Manual payment amount is invalid.');
        }

        return $normalized;
    }

    private function commandCurrency(object $command): string
    {
        $currency = $command->currency ?? '';
        if (!is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new PaymentException('Manual payment currency is invalid.');
        }

        return $currency;
    }

    private function remoteReference(string $remoteId): string
    {
        if ($remoteId === '') {
            return '';
        }
        if ($remoteId !== trim($remoteId) || strlen($remoteId) > 191 || preg_match('/[\x00-\x1F\x7F]/', $remoteId) === 1) {
            throw new PaymentException('Manual payment remote reference is invalid.');
        }

        return $remoteId;
    }

    private function instructions(mixed $value): string
    {
        if (!is_string($value)) {
            throw new PaymentException('Manual payment instructions are invalid.');
        }
        if ($value === '') {
            return '请按站点说明完成线下付款，管理员确认后解锁内容。';
        }
        if ($value !== trim($value) || strlen($value) > 4096 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new PaymentException('Manual payment instructions are invalid.');
        }

        return $value;
    }
}
