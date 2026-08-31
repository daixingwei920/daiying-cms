<?php

declare(strict_types=1);

namespace Cms\Core\Payment;

use PDO;

final class PaymentEntitlementService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PaymentRepository $payments,
    ) {
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    public function grantFromPayment(
        int $paymentId,
        string $principalType,
        string $principalId,
        int $sourceAuthorizationId = 0,
        string $expiresAt = '',
        array $metadata = [],
    ): array {
        $principalType = $this->normalizeType($principalType, 'Payment entitlement principal type is invalid.');
        $principalId = $this->normalizeId($principalId, 'Payment entitlement principal id is invalid.');
        $payment = $this->payments->payment($paymentId);
        if ($payment === null || !in_array((string) ($payment['status'] ?? ''), ['paid', 'partially_refunded'], true)) {
            throw new PaymentException('Payment entitlement requires a trusted paid payment.');
        }

        $authorization = null;
        if ($sourceAuthorizationId < 0) {
            throw new PaymentException('Payment entitlement source authorization id is invalid.');
        }
        if ($sourceAuthorizationId > 0) {
            $authorization = $this->payments->authorization($sourceAuthorizationId);
            $authorizationPaymentId = is_array($authorization) ? $this->storedPositiveInt($authorization['payment_id'] ?? null) : null;
            if ($authorization === null || $authorizationPaymentId !== $paymentId) {
                throw new PaymentException('Payment entitlement source authorization was not found.');
            }
            if ((string) ($authorization['subject_type'] ?? '') !== (string) $payment['subject_type'] || (string) ($authorization['subject_id'] ?? '') !== (string) $payment['subject_id']) {
                throw new PaymentException('Payment entitlement source authorization does not match payment subject.');
            }
            if ((string) ($authorization['status'] ?? '') !== 'active') {
                throw new PaymentException('Payment entitlement source authorization is not active.');
            }
            if ($expiresAt === '') {
                $expiresAt = (string) ($authorization['expires_at'] ?? '');
            }
        }

        $subjectType = (string) $payment['subject_type'];
        $subjectId = (string) $payment['subject_id'];
        $expiresAt = $this->normalizeExpiry($expiresAt);
        if ($this->payments->entitlementForPaymentPrincipalSubject($paymentId, $principalType, $principalId, $subjectType, $subjectId) !== null) {
            throw new PaymentException('Payment entitlement was already granted for this payment.');
        }

        $entitlementId = $this->payments->insertEntitlement([
            'principal_type' => $principalType,
            'principal_id' => $principalId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'source_payment_id' => $paymentId,
            'source_authorization_id' => $sourceAuthorizationId > 0 ? $sourceAuthorizationId : null,
            'status' => 'active',
            'expires_at' => $expiresAt,
            'metadata' => $this->safeMetadata($metadata),
        ]);

        return $this->payments->entitlement($entitlementId) ?? [];
    }

    public function isEntitled(string $principalType, string $principalId, string $subjectType, string $subjectId): bool
    {
        $principalType = $this->normalizeType($principalType, 'Payment entitlement principal type is invalid.');
        $principalId = $this->normalizeId($principalId, 'Payment entitlement principal id is invalid.');
        $subjectType = $this->normalizeType($subjectType, 'Payment entitlement subject type is invalid.');
        $subjectId = $this->normalizeId($subjectId, 'Payment entitlement subject id is invalid.');
        $now = time();

        foreach ($this->payments->activeEntitlements($principalType, $principalId, $subjectType, $subjectId) as $entitlement) {
            if (!$this->optionalFutureTimestampActive((string) ($entitlement['expires_at'] ?? ''), $now)) {
                continue;
            }

            $sourcePaymentId = $this->storedPositiveInt($entitlement['source_payment_id'] ?? null);
            if ($sourcePaymentId === null) {
                continue;
            }
            $payment = $this->payments->payment($sourcePaymentId);
            if ($payment === null || !in_array((string) ($payment['status'] ?? ''), ['paid', 'partially_refunded'], true)) {
                continue;
            }

            $paymentId = $this->storedPositiveInt($payment['id'] ?? null);
            if ($paymentId === null) {
                continue;
            }
            $authorizationId = $this->storedNullablePositiveInt($entitlement['source_authorization_id'] ?? null);
            if ($authorizationId === null) {
                continue;
            }
            if ($authorizationId > 0 && !$this->sourceAuthorizationValid($authorizationId, $paymentId, $subjectType, $subjectId, $now)) {
                continue;
            }

            $trusted = $this->payments->trustedStatus($subjectType, $subjectId, (string) ($payment['currency'] ?? ''));
            if ((string) ($trusted['status'] ?? '') === 'paid') {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    public function revoke(int $entitlementId): array
    {
        if ($entitlementId <= 0 || !$this->payments->revokeEntitlement($entitlementId)) {
            throw new PaymentException('Payment entitlement could not be revoked.');
        }

        return $this->payments->entitlement($entitlementId) ?? [];
    }

    private function sourceAuthorizationValid(int $authorizationId, int $sourcePaymentId, string $subjectType, string $subjectId, int $now): bool
    {
        $authorization = $this->payments->authorization($authorizationId);
        if ($authorization === null || (string) ($authorization['status'] ?? '') !== 'active') {
            return false;
        }
        if ($this->storedPositiveInt($authorization['payment_id'] ?? null) !== $sourcePaymentId) {
            return false;
        }
        if ((string) ($authorization['subject_type'] ?? '') !== $subjectType || (string) ($authorization['subject_id'] ?? '') !== $subjectId) {
            return false;
        }
        return $this->requiredFutureTimestampActive((string) ($authorization['expires_at'] ?? ''), $now);
    }

    private function storedNullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return $this->storedPositiveInt($value);
    }

    private function storedPositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function normalizeType(string $type, string $message): string
    {
        $normalized = strtolower(trim($type));
        if ($type !== $normalized) {
            throw new PaymentException($message);
        }
        $type = $normalized;
        if (preg_match('/^[a-z0-9][a-z0-9._-]{1,95}[a-z0-9]$/', $type) !== 1) {
            throw new PaymentException($message);
        }

        return $type;
    }

    private function normalizeId(string $id, string $message): string
    {
        if (
            $id === ''
            || $id !== trim($id)
            || strlen($id) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $id) === 1
            || $this->metadataValueContainsSecret($id)
        ) {
            throw new PaymentException($message);
        }

        return $id;
    }

    private function normalizeExpiry(string $expiresAt): ?string
    {
        if ($expiresAt === '') {
            return null;
        }
        if (!$this->futureCanonicalUtcTimestamp($expiresAt)) {
            throw new PaymentException('Payment entitlement expiry is invalid.');
        }

        return $expiresAt;
    }

    private function optionalFutureTimestampActive(string $expiresAt, int $now): bool
    {
        if ($expiresAt === '') {
            return true;
        }

        return $this->requiredFutureTimestampActive($expiresAt, $now);
    }

    private function requiredFutureTimestampActive(string $expiresAt, int $now): bool
    {
        if (!$this->canonicalUtcTimestamp($expiresAt)) {
            return false;
        }
        $timestamp = strtotime($expiresAt);

        return $timestamp !== false && $timestamp > $now;
    }

    private function futureCanonicalUtcTimestamp(string $expiresAt): bool
    {
        if (!$this->canonicalUtcTimestamp($expiresAt)) {
            return false;
        }
        $timestamp = strtotime($expiresAt);

        return $timestamp !== false && $timestamp > time();
    }

    private function canonicalUtcTimestamp(string $expiresAt): bool
    {
        if ($expiresAt === '' || $expiresAt !== trim($expiresAt)) {
            return false;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})\+00:00$/', $expiresAt, $matches) !== 1) {
            return false;
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];
        $hour = (int) $matches[4];
        $minute = (int) $matches[5];
        $second = (int) $matches[6];

        return checkdate($month, $day, $year)
            && $hour >= 0 && $hour <= 23
            && $minute >= 0 && $minute <= 59
            && $second >= 0 && $second <= 59;
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    private function safeMetadata(array $metadata): array
    {
        $safe = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $key) !== 1) {
                continue;
            }
            $name = strtolower((string) $key);
            if (preg_match('/password|secret|token|authorization|signature|auth|api[_-]?key|access[_-]?key|private|email|phone|address/', $name) === 1) {
                $safe[$key] = '[redacted]';
                continue;
            }
            if (str_contains($name, 'url') && is_string($value)) {
                $safe[$key] = $this->redactUrlSecrets($value);
                continue;
            }
            if (is_string($value) && $this->metadataValueContainsSecret($value)) {
                $safe[$key] = '[redacted]';
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    private function metadataValueContainsSecret(string $value): bool
    {
        $pattern = '/(?:bearer\s+|payment_token=|sk_[A-Za-z0-9_=-]+|api[_-]?key=|access[_-]?key=|secret=|signature=)/i';
        $decodedValue = rawurldecode($value);

        return preg_match($pattern, $value) === 1
            || $decodedValue !== trim($decodedValue)
            || preg_match('/[\x00-\x1F\x7F]/', $decodedValue) === 1
            || preg_match($pattern, $decodedValue) === 1;
    }

    private function redactUrlSecrets(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }
        if ($this->metadataValueContainsSecret((string) ($parts['path'] ?? ''))) {
            return '[redacted]';
        }
        if (!isset($parts['query'])) {
            return $this->urlWithoutFragment($parts);
        }

        parse_str((string) $parts['query'], $query);
        foreach ($query as $key => $value) {
            if (!is_scalar($value)) {
                return '[redacted]';
            }
            $name = strtolower((string) $key);
            if (
                preg_match('/claim|token|signature|secret|authorization|auth|key|password|private/', $name) === 1
                || $this->metadataValueContainsSecret((string) $value)
            ) {
                $query[$key] = '[redacted]';
            }
        }

        $rebuilt = '';
        if (isset($parts['scheme'])) {
            $rebuilt .= (string) $parts['scheme'] . '://';
        }
        if (isset($parts['host'])) {
            $rebuilt .= (string) $parts['host'];
        }
        if (isset($parts['port'])) {
            $rebuilt .= ':' . (int) $parts['port'];
        }
        $rebuilt .= (string) ($parts['path'] ?? '');
        $rebuilt .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $rebuilt;
    }

    /** @param array<string,mixed> $parts */
    private function urlWithoutFragment(array $parts): string
    {
        $rebuilt = '';
        if (isset($parts['scheme'])) {
            $rebuilt .= (string) $parts['scheme'] . '://';
        }
        if (isset($parts['host'])) {
            $rebuilt .= (string) $parts['host'];
        }
        if (isset($parts['port'])) {
            $rebuilt .= ':' . (int) $parts['port'];
        }
        $rebuilt .= (string) ($parts['path'] ?? '');

        return $rebuilt;
    }
}
