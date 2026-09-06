<?php

declare(strict_types=1);

namespace Cms\Core\Payment;

final class FixturePaymentProvider implements PaymentProviderInterface
{
    public const PROVIDER_ID = 'core.fixture-payment';

    public function providerId(): string
    {
        return self::PROVIDER_ID;
    }

    public function displayName(): string
    {
        return '核心模拟支付';
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return ['payment.create', 'payment.capture', 'payment.cancel', 'payment.refund', 'payment.status'];
    }

    public function createPayment(object $command): PaymentResult
    {
        $scenario = $this->commandScenario($command);
        $remoteId = $this->remoteId('pay', $command);

        if (in_array($scenario, ['failure', 'fail'], true)) {
            return new PaymentResult(false, 'fixture_payment_failed', 'Fixture payment failed.', false, '', [
                'status' => 'failed',
                'provider_payment_id' => $remoteId,
            ]);
        }

        if (in_array($scenario, ['cancel', 'cancelled', 'canceled'], true)) {
            return new PaymentResult(true, 'fixture_payment_cancelled', 'Fixture payment cancelled.', false, '', [
                'status' => 'cancelled',
                'provider_payment_id' => $remoteId,
            ]);
        }

        if ($scenario === 'authorized') {
            return new PaymentResult(true, 'fixture_payment_authorized', 'Fixture payment authorized.', false, '', [
                'status' => 'authorized',
                'provider_payment_id' => $remoteId,
            ]);
        }

        return new PaymentResult(true, 'fixture_payment_paid', 'Fixture payment paid.', false, '', [
            'status' => 'paid',
            'provider_payment_id' => $remoteId,
        ]);
    }

    public function capturePayment(object $command): PaymentResult
    {
        $remoteId = $this->optionalCommandString($command, 'provider_payment_id', 'Fixture payment remote reference is invalid.') ?? $this->remoteId('capture', $command);

        return new PaymentResult(true, 'fixture_payment_captured', 'Fixture payment captured.', false, '', [
            'status' => 'paid',
            'provider_payment_id' => $remoteId,
        ]);
    }

    public function cancelPayment(object $command): PaymentResult
    {
        $remoteId = $this->optionalCommandString($command, 'provider_payment_id', 'Fixture payment remote reference is invalid.') ?? $this->remoteId('cancel', $command);

        return new PaymentResult(true, 'fixture_payment_cancelled', 'Fixture payment cancelled.', false, '', [
            'status' => 'cancelled',
            'provider_payment_id' => $remoteId,
        ]);
    }

    public function refundPayment(object $command): PaymentResult
    {
        return new PaymentResult(true, 'fixture_refund_completed', 'Fixture refund completed.', false, '', [
            'status' => 'completed',
            'provider_refund_id' => $this->remoteId('refund', $command),
        ]);
    }

    public function getPaymentStatus(object $command): PaymentResult
    {
        $status = $this->optionalCommandString($command, 'expected_status', 'Fixture payment expected status is invalid.') ?? 'paid';
        $remoteId = $this->optionalCommandString($command, 'provider_payment_id', 'Fixture payment remote reference is invalid.') ?? $this->remoteId('status', $command);

        return new PaymentResult(true, 'fixture_payment_status', 'Fixture payment status returned.', false, '', [
            'status' => $status,
            'provider_payment_id' => $remoteId,
        ]);
    }

    private function remoteId(string $kind, object $command): string
    {
        $seed = implode(':', [
            $kind,
            $this->optionalCommandString($command, 'subject_type', 'Fixture payment subject type is invalid.') ?? '',
            $this->optionalCommandString($command, 'subject_id', 'Fixture payment subject id is invalid.')
                ?? $this->optionalCommandPositiveIntString($command, 'payment_id', 'Fixture payment subject id is invalid.')
                ?? '0',
            (string) $this->commandAmountMinor($command),
            $this->commandCurrency($command),
            $this->optionalCommandString($command, 'idempotency_key', 'Fixture payment idempotency key is invalid.') ?? '',
        ]);

        return 'core-fixture-' . $kind . '-' . substr(hash('sha256', $seed), 0, 24);
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

    private function commandScenario(object $command): string
    {
        $scenario = $command->scenario ?? 'success';
        if ($scenario === '') {
            return 'success';
        }
        if (!is_string($scenario) || $scenario !== trim($scenario) || $scenario !== strtolower($scenario) || strlen($scenario) > 64 || preg_match('/^[a-z0-9._-]+$/', $scenario) !== 1) {
            throw new PaymentException('Fixture payment scenario is invalid.');
        }

        return $scenario;
    }

    private function commandAmountMinor(object $command): int
    {
        $amount = $command->amount_minor ?? null;
        $normalized = is_int($amount) ? $amount : (is_string($amount) && preg_match('/^[0-9]+$/', $amount) === 1 ? (int) $amount : 0);
        if ($normalized <= 0 || (string) $amount !== (string) $normalized) {
            throw new PaymentException('Fixture payment amount is invalid.');
        }

        return $normalized;
    }

    private function commandCurrency(object $command): string
    {
        $currency = $command->currency ?? '';
        if (!is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new PaymentException('Fixture payment currency is invalid.');
        }

        return $currency;
    }
}
