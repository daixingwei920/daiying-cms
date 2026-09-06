<?php

declare(strict_types=1);

namespace Cms\Core\Payment;

use PDO;
use Throwable;

final class PaymentRepository
{
    private const MAX_WEBHOOK_PAYLOAD_BYTES = 1048576;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,mixed> $data */
    public function insertPayment(array $data): int
    {
        $now = gmdate('c');
        $subjectType = $this->normalizedType($this->requiredString($data, 'subject_type', 'Payment subject type is invalid.'), 'Payment subject type is invalid.');
        $subjectId = $this->normalizedId($this->requiredString($data, 'subject_id', 'Payment subject id is invalid.'), 'Payment subject id is invalid.');
        $providerId = $this->normalizedType($this->requiredString($data, 'provider_id', 'Payment provider id is invalid.'), 'Payment provider id is invalid.');
        $remoteId = $this->normalizedReference($this->requiredString($data, 'remote_id', 'Payment remote reference is invalid.'), 'Payment remote reference is invalid.');
        $reference = $this->normalizedReference($this->requiredString($data, 'reference', 'Payment reference is invalid.'), 'Payment reference is invalid.');
        $status = $this->normalizedPaymentStatus($this->requiredString($data, 'status', 'Payment status is invalid.'));
        if (in_array($status, ['partially_refunded', 'refunded'], true)) {
            throw new PaymentException('Payment creation status is invalid.');
        }
        $amountMinor = $this->positiveInt($data['amount_minor'] ?? 0, 'Payment amount is invalid.');
        $currency = $this->normalizedCurrency($this->requiredString($data, 'currency', 'Payment currency is invalid.'), 'Payment currency is invalid.');
        $idempotencyKey = $this->normalizedReference($this->requiredString($data, 'idempotency_key', 'Payment idempotency key is invalid.'), 'Payment idempotency key is invalid.');
        $requestHash = $this->normalizedHash($this->requiredString($data, 'request_hash', 'Payment request hash is invalid.'), 'Payment request hash is invalid.');
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_payments
                (subject_type, subject_id, provider_id, remote_id, reference, status, amount_minor, currency, idempotency_key, request_hash, metadata_json, authorized_at, paid_at, failed_at, cancelled_at, created_at, updated_at)
             VALUES
                (:subject_type, :subject_id, :provider_id, :remote_id, :reference, :status, :amount_minor, :currency, :idempotency_key, :request_hash, :metadata_json, :authorized_at, :paid_at, :failed_at, :cancelled_at, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':subject_type' => $subjectType,
            ':subject_id' => $subjectId,
            ':provider_id' => $providerId,
            ':remote_id' => $remoteId,
            ':reference' => $reference,
            ':status' => $status,
            ':amount_minor' => $amountMinor,
            ':currency' => $currency,
            ':idempotency_key' => $idempotencyKey,
            ':request_hash' => $requestHash,
            ':metadata_json' => $this->metadataJson($this->safeMetadata(is_array($data['metadata'] ?? null) ? $data['metadata'] : [])),
            ':authorized_at' => $status === 'authorized' ? $now : null,
            ':paid_at' => $status === 'paid' ? $now : null,
            ':failed_at' => $status === 'failed' ? $now : null,
            ':cancelled_at' => $status === 'cancelled' ? $now : null,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function insertRefund(array $data): int
    {
        $now = gmdate('c');
        $paymentId = $this->positiveInt($data['payment_id'] ?? 0, 'Payment refund payment id is invalid.');
        $providerId = $this->normalizedType($this->requiredString($data, 'provider_id', 'Payment refund provider id is invalid.'), 'Payment refund provider id is invalid.');
        $remoteId = $this->normalizedReference($this->requiredString($data, 'remote_id', 'Payment refund remote reference is invalid.'), 'Payment refund remote reference is invalid.');
        $status = $this->normalizedRefundStatus($this->requiredString($data, 'status', 'Payment refund status is invalid.'));
        $amountMinor = $this->positiveInt($data['amount_minor'] ?? 0, 'Payment refund amount is invalid.');
        $currency = $this->normalizedCurrency($this->requiredString($data, 'currency', 'Payment refund currency is invalid.'), 'Payment refund currency is invalid.');
        $reason = $this->normalizedReason($this->requiredString($data, 'reason', 'Payment refund reason is invalid.'), 'Payment refund reason is invalid.');
        $idempotencyKey = $this->normalizedReference($this->requiredString($data, 'idempotency_key', 'Payment refund idempotency key is invalid.'), 'Payment refund idempotency key is invalid.');
        $requestHash = $this->normalizedHash($this->requiredString($data, 'request_hash', 'Payment refund request hash is invalid.'), 'Payment refund request hash is invalid.');
        $payment = $this->payment($paymentId);
        if ($payment === null) {
            throw new PaymentException('Payment refund source payment was not found.');
        }
        if ((string) ($payment['provider_id'] ?? '') !== $providerId || (string) ($payment['currency'] ?? '') !== $currency) {
            throw new PaymentException('Payment refund source payment does not match refund provider or currency.');
        }
        if (!in_array((string) ($payment['status'] ?? ''), ['paid', 'partially_refunded', 'refunded'], true)) {
            throw new PaymentException('Payment refund source payment is not refundable.');
        }
        if ($status === 'completed') {
            if ((string) ($payment['status'] ?? '') === 'refunded') {
                throw new PaymentException('Payment refund source payment is already refunded.');
            }
            if ($this->refundedMinorForPayment($paymentId) + $amountMinor > $this->sourcePaymentAmountMinor($payment)) {
                throw new PaymentException('Payment refund exceeds source payment amount.');
            }
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_payment_refunds
                (payment_id, provider_id, remote_id, status, amount_minor, currency, reason, idempotency_key, request_hash, metadata_json, completed_at, failed_at, cancelled_at, created_at, updated_at)
             VALUES
                (:payment_id, :provider_id, :remote_id, :status, :amount_minor, :currency, :reason, :idempotency_key, :request_hash, :metadata_json, :completed_at, :failed_at, :cancelled_at, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':payment_id' => $paymentId,
            ':provider_id' => $providerId,
            ':remote_id' => $remoteId,
            ':status' => $status,
            ':amount_minor' => $amountMinor,
            ':currency' => $currency,
            ':reason' => $reason,
            ':idempotency_key' => $idempotencyKey,
            ':request_hash' => $requestHash,
            ':metadata_json' => $this->metadataJson($this->safeMetadata(is_array($data['metadata'] ?? null) ? $data['metadata'] : [])),
            ':completed_at' => $status === 'completed' ? $now : null,
            ':failed_at' => $status === 'failed' ? $now : null,
            ':cancelled_at' => $status === 'cancelled' ? $now : null,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function payment(int $paymentId): ?array
    {
        $paymentId = $this->positiveInt($paymentId, 'Payment id is invalid.');
        $stmt = $this->pdo->prepare('SELECT * FROM cms_payments WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $paymentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function paymentByIdempotency(string $idempotencyKey): ?array
    {
        $idempotencyKey = $this->normalizedReference($idempotencyKey, 'Payment idempotency key is invalid.');
        $stmt = $this->pdo->prepare('SELECT * FROM cms_payments WHERE idempotency_key = :key LIMIT 1');
        $stmt->execute([':key' => $idempotencyKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function paymentByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        $idempotencyKey = $this->normalizedReference($idempotencyKey, 'Payment idempotency key is invalid.');
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = 'SELECT * FROM cms_payments WHERE idempotency_key = :key LIMIT 1';
        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':key' => $idempotencyKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function paymentByRemote(string $providerId, string $remoteId): ?array
    {
        $providerId = $this->normalizedType($providerId, 'Payment provider id is invalid.');
        $remoteId = $this->normalizedReference($remoteId, 'Payment remote reference is invalid.');
        $stmt = $this->pdo->prepare('SELECT * FROM cms_payments WHERE provider_id = :provider_id AND remote_id = :remote_id LIMIT 1');
        $stmt->execute([':provider_id' => $providerId, ':remote_id' => $remoteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function updatePaymentStatus(int $paymentId, string $status, string $remoteId = '', string $reference = ''): void
    {
        $now = gmdate('c');
        $paymentId = $this->positiveInt($paymentId, 'Payment id is invalid.');
        $status = $this->normalizedPaymentStatus($status);
        $payment = $this->payment($paymentId);
        if ($payment === null) {
            throw new PaymentException('Payment was not found.');
        }
        $this->assertPaymentStatusTransition((string) ($payment['status'] ?? ''), $status);
        $this->assertRefundStatusTotals($payment, $status);
        $remoteId = $remoteId !== '' ? $this->normalizedReference($remoteId, 'Payment remote reference is invalid.') : '';
        $reference = $reference !== '' ? $this->normalizedReference($reference, 'Payment reference is invalid.') : '';
        $alreadyInTransaction = $this->pdo->inTransaction();
        if (!$alreadyInTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $stmt = $this->pdo->prepare(
                'UPDATE cms_payments SET
                    status = :status,
                    remote_id = CASE WHEN :remote_id = \'\' THEN remote_id ELSE :remote_id END,
                    reference = CASE WHEN :reference = \'\' THEN reference ELSE :reference END,
                    authorized_at = COALESCE(:authorized_at, authorized_at),
                    paid_at = COALESCE(:paid_at, paid_at),
                    failed_at = COALESCE(:failed_at, failed_at),
                    cancelled_at = COALESCE(:cancelled_at, cancelled_at),
                    updated_at = :now
                 WHERE id = :id'
            );
            $stmt->execute([
                ':id' => $paymentId,
                ':status' => $status,
                ':remote_id' => $remoteId,
                ':reference' => $reference,
                ':authorized_at' => $status === 'authorized' ? $now : null,
                ':paid_at' => in_array($status, ['paid', 'partially_refunded', 'refunded'], true) ? $now : null,
                ':failed_at' => $status === 'failed' ? $now : null,
                ':cancelled_at' => $status === 'cancelled' ? $now : null,
                ':now' => $now,
            ]);
            if ($status === 'refunded') {
                $this->revokeActiveAuthorizationsForPayment($paymentId);
                $this->revokeActiveEntitlementsForPayment($paymentId);
            }

            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $metadata */
    public function updatePaymentMetadata(int $paymentId, array $metadata): void
    {
        $paymentId = $this->positiveInt($paymentId, 'Payment id is invalid.');
        if ($this->payment($paymentId) === null) {
            throw new PaymentException('Payment was not found.');
        }
        $stmt = $this->pdo->prepare('UPDATE cms_payments SET metadata_json = :metadata_json, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':id' => $paymentId,
            ':metadata_json' => $this->metadataJson($this->safeMetadata($metadata)),
            ':updated_at' => gmdate('c'),
        ]);
    }

    /** @return array<string,mixed>|null */
    public function refund(int $refundId): ?array
    {
        $refundId = $this->positiveInt($refundId, 'Payment refund id is invalid.');
        $stmt = $this->pdo->prepare('SELECT * FROM cms_payment_refunds WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $refundId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function refundByIdempotency(string $idempotencyKey): ?array
    {
        $idempotencyKey = $this->normalizedReference($idempotencyKey, 'Payment refund idempotency key is invalid.');
        $stmt = $this->pdo->prepare('SELECT * FROM cms_payment_refunds WHERE idempotency_key = :key LIMIT 1');
        $stmt->execute([':key' => $idempotencyKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function refundByIdempotencyForUpdate(string $idempotencyKey): ?array
    {
        $idempotencyKey = $this->normalizedReference($idempotencyKey, 'Payment refund idempotency key is invalid.');
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = 'SELECT * FROM cms_payment_refunds WHERE idempotency_key = :key LIMIT 1';
        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':key' => $idempotencyKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function refundByRemote(string $providerId, string $remoteId): ?array
    {
        $providerId = $this->normalizedType($providerId, 'Payment refund provider id is invalid.');
        $remoteId = $this->normalizedReference($remoteId, 'Payment refund remote reference is invalid.');
        $stmt = $this->pdo->prepare('SELECT * FROM cms_payment_refunds WHERE provider_id = :provider_id AND remote_id = :remote_id LIMIT 1');
        $stmt->execute([':provider_id' => $providerId, ':remote_id' => $remoteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function refundedMinorForPayment(int $paymentId): int
    {
        $paymentId = $this->positiveInt($paymentId, 'Payment refund payment id is invalid.');
        $stmt = $this->pdo->prepare("SELECT amount_minor FROM cms_payment_refunds WHERE payment_id = :payment_id AND status = 'completed'");
        $stmt->execute([':payment_id' => $paymentId]);

        $total = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $total += $this->positiveInt($row['amount_minor'] ?? 0, 'Payment refund amount is invalid.');
        }

        return $total;
    }

    /** @param array<string,mixed> $data */
    public function insertAuthorization(array $data): int
    {
        $now = gmdate('c');
        $paymentId = $this->positiveInt($data['payment_id'] ?? 0, 'Payment authorization payment id is invalid.');
        $subjectType = $this->normalizedType($this->requiredString($data, 'subject_type', 'Payment authorization subject type is invalid.'), 'Payment authorization subject type is invalid.');
        $subjectId = $this->normalizedId($this->requiredString($data, 'subject_id', 'Payment authorization subject id is invalid.'), 'Payment authorization subject id is invalid.');
        $tokenHash = $this->normalizedHash($this->requiredString($data, 'token_hash', 'Payment authorization token hash is invalid.'), 'Payment authorization token hash is invalid.');
        $status = $this->normalizedAuthorizationStatus($this->optionalString($data, 'status', 'Payment authorization status is invalid.', 'active'));
        $maxUses = $this->nonNegativeInt($data['max_uses'] ?? 0, 'Payment authorization max uses is invalid.');
        $usedCount = $this->nonNegativeInt($data['used_count'] ?? 0, 'Payment authorization used count is invalid.');
        if ($status !== 'active') {
            throw new PaymentException('Payment authorization creation status is invalid.');
        }
        if ($usedCount !== 0) {
            throw new PaymentException('Payment authorization creation used count is invalid.');
        }
        if (($data['revoked_at'] ?? null) !== null && ($data['revoked_at'] ?? null) !== '') {
            throw new PaymentException('Payment authorization creation revoked timestamp is invalid.');
        }
        if (($data['last_used_at'] ?? null) !== null && ($data['last_used_at'] ?? null) !== '') {
            throw new PaymentException('Payment authorization creation last used timestamp is invalid.');
        }
        if ($maxUses > 0 && $usedCount > $maxUses) {
            throw new PaymentException('Payment authorization used count exceeds max uses.');
        }
        $payment = $this->payment($paymentId);
        if ($payment === null) {
            throw new PaymentException('Payment authorization source payment was not found.');
        }
        if (!in_array((string) ($payment['status'] ?? ''), ['paid', 'partially_refunded'], true)) {
            throw new PaymentException('Payment authorization requires a trusted paid payment.');
        }
        if ((string) ($payment['subject_type'] ?? '') !== $subjectType || (string) ($payment['subject_id'] ?? '') !== $subjectId) {
            throw new PaymentException('Payment authorization subject does not match source payment.');
        }
        $expiresAt = $this->normalizedFutureTimestamp($this->requiredString($data, 'expires_at', 'Payment authorization expiry is invalid.'), 'Payment authorization expiry is invalid.');
        $alreadyInTransaction = $this->pdo->inTransaction();
        if (!$alreadyInTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO cms_payment_authorizations
                    (payment_id, subject_type, subject_id, token_hash, status, max_uses, used_count, expires_at, revoked_at, last_used_at, metadata_json, created_at, updated_at)
                 VALUES
                    (:payment_id, :subject_type, :subject_id, :token_hash, :status, :max_uses, :used_count, :expires_at, :revoked_at, :last_used_at, :metadata_json, :created_at, :updated_at)'
            );
            $stmt->execute([
                ':payment_id' => $paymentId,
                ':subject_type' => $subjectType,
                ':subject_id' => $subjectId,
                ':token_hash' => $tokenHash,
                ':status' => $status,
                ':max_uses' => $maxUses,
                ':used_count' => $usedCount,
                ':expires_at' => $expiresAt,
                ':revoked_at' => $data['revoked_at'] ?? null,
                ':last_used_at' => $data['last_used_at'] ?? null,
                ':metadata_json' => $this->metadataJson($this->safeMetadata(is_array($data['metadata'] ?? null) ? $data['metadata'] : [])),
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);

            $id = (int) $this->pdo->lastInsertId();
            $this->recordAuthorizationEvent($id, $paymentId, 'created', [
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'max_uses' => $maxUses,
                'expires_at' => $expiresAt,
            ]);

            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }

            return $id;
        } catch (Throwable $exception) {
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed>|null */
    public function authorization(int $authorizationId): ?array
    {
        $authorizationId = $this->positiveInt($authorizationId, 'Payment authorization id is invalid.');
        $stmt = $this->pdo->prepare('SELECT * FROM cms_payment_authorizations WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $authorizationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function consumeAuthorization(int $authorizationId): bool
    {
        $authorizationId = $this->positiveInt($authorizationId, 'Payment authorization id is invalid.');
        $authorization = $this->authorization($authorizationId);
        if (!is_array($authorization) || $this->storedPositiveInt($authorization['payment_id'] ?? null) === null) {
            return false;
        }
        $maxUses = $this->storedNonNegativeInt($authorization['max_uses'] ?? null);
        $usedCount = $this->storedNonNegativeInt($authorization['used_count'] ?? null);
        if ($maxUses === null || $usedCount === null || ($maxUses > 0 && $usedCount >= $maxUses)) {
            return false;
        }

        $alreadyInTransaction = $this->pdo->inTransaction();
        if (!$alreadyInTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $stmt = $this->pdo->prepare(
                "UPDATE cms_payment_authorizations
                 SET used_count = used_count + 1, last_used_at = :used_at, updated_at = :updated_at
                 WHERE id = :id AND status = 'active' AND expires_at > :expires_after
                   AND max_uses >= 0 AND used_count >= 0
                   AND (max_uses = 0 OR used_count < max_uses)"
            );
            $now = gmdate('c');
            $stmt->execute([':id' => $authorizationId, ':used_at' => $now, ':updated_at' => $now, ':expires_after' => $now]);

            if ($stmt->rowCount() !== 1) {
                if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }
                return false;
            }

            $authorization = $this->authorization($authorizationId);
            $paymentId = is_array($authorization) ? $this->storedPositiveInt($authorization['payment_id'] ?? null) : null;
            $maxUses = is_array($authorization) ? $this->storedNonNegativeInt($authorization['max_uses'] ?? null) : null;
            $usedCount = is_array($authorization) ? $this->storedNonNegativeInt($authorization['used_count'] ?? null) : null;
            if ($paymentId === null || $maxUses === null || $usedCount === null) {
                throw new PaymentException('Payment authorization usage counters are invalid.');
            }
            $this->recordAuthorizationEvent($authorizationId, $paymentId, 'consumed', [
                'used_count' => $usedCount,
                'max_uses' => $maxUses,
            ]);

            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }

            return true;
        } catch (Throwable $exception) {
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function revokeAuthorization(int $authorizationId): bool
    {
        $authorizationId = $this->positiveInt($authorizationId, 'Payment authorization id is invalid.');
        $authorization = $this->authorization($authorizationId);
        if (!is_array($authorization) || $this->storedPositiveInt($authorization['payment_id'] ?? null) === null) {
            return false;
        }

        $alreadyInTransaction = $this->pdo->inTransaction();
        if (!$alreadyInTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $now = gmdate('c');
            $stmt = $this->pdo->prepare(
                "UPDATE cms_payment_authorizations
                 SET status = 'revoked', revoked_at = :revoked_at, updated_at = :updated_at
                 WHERE id = :id AND status = 'active'"
            );
            $stmt->execute([':id' => $authorizationId, ':revoked_at' => $now, ':updated_at' => $now]);

            if ($stmt->rowCount() !== 1) {
                if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }
                return false;
            }

            $authorization = $this->authorization($authorizationId);
            $paymentId = is_array($authorization) ? $this->storedPositiveInt($authorization['payment_id'] ?? null) : null;
            if ($paymentId !== null) {
                $this->recordAuthorizationEvent($authorizationId, $paymentId, 'revoked', [
                    'revoked_at' => (string) ($authorization['revoked_at'] ?? $now),
                ]);
            }

            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }

            return true;
        } catch (Throwable $exception) {
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return list<int> */
    public function revokeActiveAuthorizationsForPayment(int $paymentId): array
    {
        $paymentId = $this->positiveInt($paymentId, 'Payment authorization payment id is invalid.');

        $stmt = $this->pdo->prepare(
            "SELECT id
             FROM cms_payment_authorizations
             WHERE payment_id = :payment_id AND status = 'active'
             ORDER BY id ASC"
        );
        $stmt->execute([':payment_id' => $paymentId]);
        $ids = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $stmt->fetchAll(PDO::FETCH_ASSOC));
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }

        $alreadyInTransaction = $this->pdo->inTransaction();
        if (!$alreadyInTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $revoked = [];
            $now = gmdate('c');
            $update = $this->pdo->prepare(
                "UPDATE cms_payment_authorizations
                 SET status = 'revoked', revoked_at = :revoked_at, updated_at = :updated_at
                 WHERE id = :id AND payment_id = :payment_id AND status = 'active'"
            );
            foreach ($ids as $id) {
                $update->execute([
                    ':id' => $id,
                    ':payment_id' => $paymentId,
                    ':revoked_at' => $now,
                    ':updated_at' => $now,
                ]);
                if ($update->rowCount() !== 1) {
                    continue;
                }

                $revoked[] = $id;
                $this->recordAuthorizationEvent($id, $paymentId, 'revoked', [
                    'revoked_at' => $now,
                    'reason' => 'payment_refunded',
                ]);
            }

            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }

            return $revoked;
        } catch (Throwable $exception) {
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function expireExpiredAuthorizations(int $limit = 100): int
    {
        $limit = $this->boundedBatchLimit($limit, 'Payment authorization expiry limit is invalid.');
        $now = gmdate('c');
        $candidateLimit = min(1000, max($limit, $limit * 5));
        $stmt = $this->pdo->prepare(
            "SELECT id, payment_id, expires_at
             FROM cms_payment_authorizations
             WHERE status = 'active' AND expires_at <= :now
             ORDER BY expires_at ASC, id ASC
             LIMIT " . $candidateLimit
        );
        $stmt->execute([':now' => $now]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $alreadyInTransaction = $this->pdo->inTransaction();
        if (!$alreadyInTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $expired = 0;
            $update = $this->pdo->prepare(
                "UPDATE cms_payment_authorizations
                 SET status = 'expired', updated_at = :updated_at
                 WHERE id = :id AND status = 'active' AND expires_at = :expires_at AND expires_at <= :now"
            );
            foreach ($rows as $row) {
                $id = $this->storedPositiveInt($row['id'] ?? null);
                $paymentId = $this->storedPositiveInt($row['payment_id'] ?? null);
                if ($id === null || $paymentId === null) {
                    continue;
                }
                $expiresAt = (string) ($row['expires_at'] ?? '');
                if (!$this->canonicalUtcTimestamp($expiresAt)) {
                    continue;
                }
                $update->execute([':id' => $id, ':expires_at' => $expiresAt, ':now' => $now, ':updated_at' => $now]);
                if ($update->rowCount() !== 1) {
                    continue;
                }

                $expired++;
                $this->recordAuthorizationEvent($id, $paymentId, 'expired', [
                    'expires_at' => (string) ($row['expires_at'] ?? ''),
                    'expired_at' => $now,
                ]);
                if ($expired >= $limit) {
                    break;
                }
            }

            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }

            return $expired;
        } catch (Throwable $exception) {
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return list<array<string,mixed>> */
    public function authorizationsForPayment(int $paymentId): array
    {
        $paymentId = $this->positiveInt($paymentId, 'Payment authorization payment id is invalid.');
        $stmt = $this->pdo->prepare('SELECT * FROM cms_payment_authorizations WHERE payment_id = :payment_id ORDER BY id DESC');
        $stmt->execute([':payment_id' => $paymentId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function activeAuthorizationForPaymentSubject(int $paymentId, string $subjectType, string $subjectId): ?array
    {
        $paymentId = $this->positiveInt($paymentId, 'Payment authorization payment id is invalid.');
        $subjectType = $this->normalizedType($subjectType, 'Payment authorization subject type is invalid.');
        $subjectId = $this->normalizedId($subjectId, 'Payment authorization subject id is invalid.');
        $stmt = $this->pdo->prepare(
            "SELECT * FROM cms_payment_authorizations
             WHERE payment_id = :payment_id
               AND subject_type = :subject_type
               AND subject_id = :subject_id
               AND status = 'active'
               AND expires_at > :now
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->execute([
            ':payment_id' => $paymentId,
            ':subject_type' => $subjectType,
            ':subject_id' => $subjectId,
            ':now' => gmdate('c'),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) && $this->activeAuthorizationRowValid($row, $paymentId, $subjectType, $subjectId) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function authorizationForPaymentSubject(int $paymentId, string $subjectType, string $subjectId): ?array
    {
        $paymentId = $this->positiveInt($paymentId, 'Payment authorization payment id is invalid.');
        $subjectType = $this->normalizedType($subjectType, 'Payment authorization subject type is invalid.');
        $subjectId = $this->normalizedId($subjectId, 'Payment authorization subject id is invalid.');
        $stmt = $this->pdo->prepare(
            'SELECT * FROM cms_payment_authorizations
             WHERE payment_id = :payment_id
               AND subject_type = :subject_type
               AND subject_id = :subject_id
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([
            ':payment_id' => $paymentId,
            ':subject_type' => $subjectType,
            ':subject_id' => $subjectId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function authorizationEventsForPayment(int $paymentId): array
    {
        $paymentId = $this->positiveInt($paymentId, 'Payment authorization event payment id is invalid.');
        $stmt = $this->pdo->prepare('SELECT * FROM cms_payment_authorization_events WHERE payment_id = :payment_id ORDER BY id DESC LIMIT 100');
        $stmt->execute([':payment_id' => $paymentId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $data */
    public function insertEntitlement(array $data): int
    {
        $now = gmdate('c');
        $principalType = $this->normalizedType($this->requiredString($data, 'principal_type', 'Payment entitlement principal type is invalid.'), 'Payment entitlement principal type is invalid.');
        $principalId = $this->normalizedId($this->requiredString($data, 'principal_id', 'Payment entitlement principal id is invalid.'), 'Payment entitlement principal id is invalid.');
        $subjectType = $this->normalizedType($this->requiredString($data, 'subject_type', 'Payment entitlement subject type is invalid.'), 'Payment entitlement subject type is invalid.');
        $subjectId = $this->normalizedId($this->requiredString($data, 'subject_id', 'Payment entitlement subject id is invalid.'), 'Payment entitlement subject id is invalid.');
        $sourcePaymentId = $this->positiveInt($data['source_payment_id'] ?? 0, 'Payment entitlement source payment id is invalid.');
        $sourceAuthorizationId = $this->nullablePositiveInt($data['source_authorization_id'] ?? null, 'Payment entitlement source authorization id is invalid.');
        $status = $this->normalizedEntitlementStatus($this->optionalString($data, 'status', 'Payment entitlement status is invalid.', 'active'));
        if ($status !== 'active') {
            throw new PaymentException('Payment entitlement creation status is invalid.');
        }
        if (($data['revoked_at'] ?? null) !== null && ($data['revoked_at'] ?? null) !== '') {
            throw new PaymentException('Payment entitlement creation revoked timestamp is invalid.');
        }
        $payment = $this->payment($sourcePaymentId);
        if ($payment === null) {
            throw new PaymentException('Payment entitlement source payment was not found.');
        }
        if (!in_array((string) ($payment['status'] ?? ''), ['paid', 'partially_refunded'], true)) {
            throw new PaymentException('Payment entitlement requires a trusted paid payment.');
        }
        if ((string) ($payment['subject_type'] ?? '') !== $subjectType || (string) ($payment['subject_id'] ?? '') !== $subjectId) {
            throw new PaymentException('Payment entitlement subject does not match source payment.');
        }
        if ($sourceAuthorizationId !== null) {
            $authorization = $this->authorization($sourceAuthorizationId);
            if ($authorization === null) {
                throw new PaymentException('Payment entitlement source authorization was not found.');
            }
            $authorizationPaymentId = $this->positiveInt($authorization['payment_id'] ?? null, 'Payment entitlement source authorization payment id is invalid.');
            if ($authorizationPaymentId !== $sourcePaymentId
                || (string) ($authorization['subject_type'] ?? '') !== $subjectType
                || (string) ($authorization['subject_id'] ?? '') !== $subjectId
            ) {
                throw new PaymentException('Payment entitlement source authorization does not match source payment.');
            }
            if ((string) ($authorization['status'] ?? '') !== 'active') {
                throw new PaymentException('Payment entitlement source authorization is not active.');
            }
            $sourceAuthorizationExpiresAt = (string) ($authorization['expires_at'] ?? '');
            if (!$this->futureCanonicalUtcTimestamp($sourceAuthorizationExpiresAt)) {
                throw new PaymentException('Payment entitlement source authorization is expired.');
            }
        }
        $expiresAt = $this->normalizedNullableFutureTimestamp($this->optionalString($data, 'expires_at', 'Payment entitlement expiry is invalid.', ''), 'Payment entitlement expiry is invalid.');
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_payment_entitlements
                (principal_type, principal_id, subject_type, subject_id, source_payment_id, source_authorization_id, status, expires_at, revoked_at, metadata_json, created_at, updated_at)
             VALUES
                (:principal_type, :principal_id, :subject_type, :subject_id, :source_payment_id, :source_authorization_id, :status, :expires_at, :revoked_at, :metadata_json, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':principal_type' => $principalType,
            ':principal_id' => $principalId,
            ':subject_type' => $subjectType,
            ':subject_id' => $subjectId,
            ':source_payment_id' => $sourcePaymentId,
            ':source_authorization_id' => $sourceAuthorizationId,
            ':status' => $status,
            ':expires_at' => $expiresAt,
            ':revoked_at' => $data['revoked_at'] ?? null,
            ':metadata_json' => $this->metadataJson($this->safeMetadata(is_array($data['metadata'] ?? null) ? $data['metadata'] : [])),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function entitlement(int $entitlementId): ?array
    {
        $entitlementId = $this->positiveInt($entitlementId, 'Payment entitlement id is invalid.');
        $stmt = $this->pdo->prepare('SELECT * FROM cms_payment_entitlements WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $entitlementId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function activeEntitlements(string $principalType, string $principalId, string $subjectType, string $subjectId): array
    {
        $principalType = $this->normalizedType($principalType, 'Payment entitlement principal type is invalid.');
        $principalId = $this->normalizedId($principalId, 'Payment entitlement principal id is invalid.');
        $subjectType = $this->normalizedType($subjectType, 'Payment entitlement subject type is invalid.');
        $subjectId = $this->normalizedId($subjectId, 'Payment entitlement subject id is invalid.');
        $stmt = $this->pdo->prepare(
            "SELECT * FROM cms_payment_entitlements
             WHERE principal_type = :principal_type
               AND principal_id = :principal_id
               AND subject_type = :subject_type
               AND subject_id = :subject_id
               AND status = 'active'
               AND (expires_at IS NULL OR expires_at > :now)
             ORDER BY id DESC"
        );
        $stmt->execute([
            ':principal_type' => $principalType,
            ':principal_id' => $principalId,
            ':subject_type' => $subjectType,
            ':subject_id' => $subjectId,
            ':now' => gmdate('c'),
        ]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row) && $this->activeEntitlementRowValid($row, $principalType, $principalId, $subjectType, $subjectId)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function entitlementForPaymentPrincipalSubject(int $paymentId, string $principalType, string $principalId, string $subjectType, string $subjectId): ?array
    {
        $paymentId = $this->positiveInt($paymentId, 'Payment entitlement payment id is invalid.');
        $principalType = $this->normalizedType($principalType, 'Payment entitlement principal type is invalid.');
        $principalId = $this->normalizedId($principalId, 'Payment entitlement principal id is invalid.');
        $subjectType = $this->normalizedType($subjectType, 'Payment entitlement subject type is invalid.');
        $subjectId = $this->normalizedId($subjectId, 'Payment entitlement subject id is invalid.');
        $stmt = $this->pdo->prepare(
            'SELECT * FROM cms_payment_entitlements
             WHERE source_payment_id = :source_payment_id
               AND principal_type = :principal_type
               AND principal_id = :principal_id
               AND subject_type = :subject_type
               AND subject_id = :subject_id
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([
            ':source_payment_id' => $paymentId,
            ':principal_type' => $principalType,
            ':principal_id' => $principalId,
            ':subject_type' => $subjectType,
            ':subject_id' => $subjectId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function entitlementsForPayment(int $paymentId): array
    {
        $paymentId = $this->positiveInt($paymentId, 'Payment entitlement payment id is invalid.');
        $stmt = $this->pdo->prepare('SELECT * FROM cms_payment_entitlements WHERE source_payment_id = :payment_id ORDER BY id DESC');
        $stmt->execute([':payment_id' => $paymentId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function revokeEntitlement(int $entitlementId): bool
    {
        $entitlementId = $this->positiveInt($entitlementId, 'Payment entitlement id is invalid.');
        $entitlement = $this->entitlement($entitlementId);
        if (!is_array($entitlement) || $this->storedPositiveInt($entitlement['source_payment_id'] ?? null) === null) {
            return false;
        }

        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            "UPDATE cms_payment_entitlements
             SET status = 'revoked', revoked_at = :revoked_at, updated_at = :updated_at
             WHERE id = :id AND status = 'active'"
        );
        $stmt->execute([':id' => $entitlementId, ':revoked_at' => $now, ':updated_at' => $now]);

        return $stmt->rowCount() === 1;
    }

    public function expireExpiredEntitlements(int $limit = 100): int
    {
        $limit = $this->boundedBatchLimit($limit, 'Payment entitlement expiry limit is invalid.');
        $now = gmdate('c');
        $candidateLimit = min(1000, max($limit, $limit * 5));
        $stmt = $this->pdo->prepare(
            "SELECT id, source_payment_id, expires_at
             FROM cms_payment_entitlements
             WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at <= :now
             ORDER BY expires_at ASC, id ASC
             LIMIT " . $candidateLimit
        );
        $stmt->execute([':now' => $now]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $expired = 0;
        $update = $this->pdo->prepare(
            "UPDATE cms_payment_entitlements
             SET status = 'expired', updated_at = :updated_at
             WHERE id = :id AND status = 'active' AND expires_at = :expires_at AND expires_at <= :now"
        );
        foreach ($rows as $row) {
            $id = $this->storedPositiveInt($row['id'] ?? null);
            $sourcePaymentId = $this->storedPositiveInt($row['source_payment_id'] ?? null);
            if ($id === null || $sourcePaymentId === null) {
                continue;
            }
            $expiresAt = (string) ($row['expires_at'] ?? '');
            if (!$this->canonicalUtcTimestamp($expiresAt)) {
                continue;
            }
            $update->execute([':id' => $id, ':expires_at' => $expiresAt, ':now' => $now, ':updated_at' => $now]);
            if ($update->rowCount() === 1) {
                $expired++;
                if ($expired >= $limit) {
                    break;
                }
            }
        }

        return $expired;
    }

    /** @return list<int> */
    public function revokeActiveEntitlementsForPayment(int $paymentId): array
    {
        $paymentId = $this->positiveInt($paymentId, 'Payment entitlement payment id is invalid.');

        $stmt = $this->pdo->prepare(
            "SELECT id
             FROM cms_payment_entitlements
             WHERE source_payment_id = :payment_id AND status = 'active'
             ORDER BY id ASC"
        );
        $stmt->execute([':payment_id' => $paymentId]);
        $ids = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $stmt->fetchAll(PDO::FETCH_ASSOC));
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }

        $revoked = [];
        $now = gmdate('c');
        $update = $this->pdo->prepare(
            "UPDATE cms_payment_entitlements
             SET status = 'revoked', revoked_at = :revoked_at, updated_at = :updated_at
             WHERE id = :id AND source_payment_id = :payment_id AND status = 'active'"
        );
        foreach ($ids as $id) {
            $update->execute([
                ':id' => $id,
                ':payment_id' => $paymentId,
                ':revoked_at' => $now,
                ':updated_at' => $now,
            ]);
            if ($update->rowCount() === 1) {
                $revoked[] = $id;
            }
        }

        return $revoked;
    }

    /** @param array<string,mixed> $metadata */
    private function recordAuthorizationEvent(int $authorizationId, int $paymentId, string $eventType, array $metadata = []): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_payment_authorization_events
                (authorization_id, payment_id, event_type, metadata_json, created_at)
             VALUES
                (:authorization_id, :payment_id, :event_type, :metadata_json, :created_at)'
        );
        $stmt->execute([
            ':authorization_id' => $authorizationId,
            ':payment_id' => $paymentId,
            ':event_type' => $eventType,
            ':metadata_json' => $this->metadataJson($this->safeMetadata($metadata)),
            ':created_at' => gmdate('c'),
        ]);
    }

    /** @param array<string,mixed> $row */
    private function activeAuthorizationRowValid(array $row, int $paymentId, string $subjectType, string $subjectId): bool
    {
        if (
            $this->storedPositiveInt($row['id'] ?? null) === null
            || $this->storedPositiveInt($row['payment_id'] ?? null) !== $paymentId
            || (string) ($row['subject_type'] ?? '') !== $subjectType
            || (string) ($row['subject_id'] ?? '') !== $subjectId
            || (string) ($row['status'] ?? '') !== 'active'
        ) {
            return false;
        }

        try {
            $this->normalizedHash((string) ($row['token_hash'] ?? ''), 'Payment authorization token hash is invalid.');
        } catch (PaymentException) {
            return false;
        }

        $expiresAt = (string) ($row['expires_at'] ?? '');
        if (!$this->futureCanonicalUtcTimestamp($expiresAt)) {
            return false;
        }

        $maxUses = $this->storedNonNegativeInt($row['max_uses'] ?? null);
        $usedCount = $this->storedNonNegativeInt($row['used_count'] ?? null);
        if ($maxUses === null || $usedCount === null) {
            return false;
        }

        return $maxUses === 0 || $usedCount < $maxUses;
    }

    /** @param array<string,mixed> $row */
    private function activeEntitlementRowValid(array $row, string $principalType, string $principalId, string $subjectType, string $subjectId): bool
    {
        if (
            $this->storedPositiveInt($row['id'] ?? null) === null
            || (string) ($row['principal_type'] ?? '') !== $principalType
            || (string) ($row['principal_id'] ?? '') !== $principalId
            || (string) ($row['subject_type'] ?? '') !== $subjectType
            || (string) ($row['subject_id'] ?? '') !== $subjectId
            || (string) ($row['status'] ?? '') !== 'active'
        ) {
            return false;
        }

        $sourcePaymentId = $this->storedPositiveInt($row['source_payment_id'] ?? null);
        if ($sourcePaymentId === null) {
            return false;
        }

        $sourceAuthorizationId = $row['source_authorization_id'] ?? null;
        $sourceAuthorizationId = $sourceAuthorizationId === null || $sourceAuthorizationId === '' ? null : $this->storedPositiveInt($sourceAuthorizationId);
        if ($sourceAuthorizationId === null && (($row['source_authorization_id'] ?? null) !== null && ($row['source_authorization_id'] ?? null) !== '')) {
            return false;
        }
        if ($sourceAuthorizationId !== null) {
            $authorization = $this->authorization($sourceAuthorizationId);
            if (!is_array($authorization) || !$this->sourceAuthorizationRowValidForEntitlement($authorization, $sourcePaymentId, $subjectType, $subjectId)) {
                return false;
            }
        }

        if (($row['revoked_at'] ?? null) !== null && ($row['revoked_at'] ?? null) !== '') {
            return false;
        }

        $expiresAt = $row['expires_at'] ?? null;
        if ($expiresAt === null || $expiresAt === '') {
            return true;
        }

        return is_string($expiresAt) && $this->futureCanonicalUtcTimestamp($expiresAt);
    }

    /** @param array<string,mixed> $row */
    private function sourceAuthorizationRowValidForEntitlement(array $row, int $paymentId, string $subjectType, string $subjectId): bool
    {
        if (
            $this->storedPositiveInt($row['id'] ?? null) === null
            || $this->storedPositiveInt($row['payment_id'] ?? null) !== $paymentId
            || (string) ($row['subject_type'] ?? '') !== $subjectType
            || (string) ($row['subject_id'] ?? '') !== $subjectId
            || (string) ($row['status'] ?? '') !== 'active'
        ) {
            return false;
        }

        try {
            $this->normalizedHash((string) ($row['token_hash'] ?? ''), 'Payment authorization token hash is invalid.');
        } catch (PaymentException) {
            return false;
        }

        return $this->futureCanonicalUtcTimestamp((string) ($row['expires_at'] ?? ''));
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,pages:int} */
    public function searchPayments(array $filters = []): array
    {
        $page = $this->boundedSearchPage($filters['page'] ?? 1, 'Payment search page is invalid.');
        $perPage = $this->boundedSearchPerPage($filters['per_page'] ?? 25, 'Payment search page size is invalid.');
        [$sqlWhere, $params] = $this->paymentSearchWhere($filters);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM cms_payments' . $sqlWhere);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = $this->pdo->prepare('SELECT * FROM cms_payments' . $sqlWhere . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
        $stmt->execute($params);

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function exportPayments(array $filters = [], int $limit = 5000): array
    {
        $limit = $this->boundedExportLimit($limit, 'Payment export limit is invalid.');
        [$sqlWhere, $params] = $this->paymentSearchWhere($filters);
        $stmt = $this->pdo->prepare('SELECT * FROM cms_payments' . $sqlWhere . ' ORDER BY id DESC LIMIT ' . $limit);
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            try {
                $paymentId = $this->storedPositiveInt($row['id'] ?? null);
                if ($paymentId === null) {
                    throw new PaymentException('Payment export row is invalid.');
                }
                $amountMinor = $this->sourcePaymentAmountMinor($row);
                $refundedMinor = $this->refundedMinorForPayment($paymentId);
                if ($refundedMinor > $amountMinor) {
                    throw new PaymentException('Payment export row is invalid.');
                }
            } catch (PaymentException) {
                throw new PaymentException('Payment export row is invalid.');
            }
            $row['refunded_minor'] = $refundedMinor;
            $row['net_paid_minor'] = $amountMinor - $refundedMinor;
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return list<array{currency:string,payment_count:int,amount_minor:int|string,refunded_minor:int|string,net_paid_minor:int|string}> */
    public function paymentSummary(array $filters = []): array
    {
        [$sqlWhere, $params] = $this->paymentSearchWhere($filters);
        $stmt = $this->pdo->prepare('SELECT id, currency, amount_minor FROM cms_payments' . $sqlWhere . ' ORDER BY currency ASC, id ASC');
        $stmt->execute($params);

        $summary = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rawCurrency = (string) ($row['currency'] ?? '');
            $currency = $rawCurrency !== '' ? $rawCurrency : 'INVALID';
            try {
                $currency = $this->normalizedCurrency($rawCurrency, 'Payment currency is invalid.');
                $paymentId = $this->storedPositiveInt($row['id'] ?? null);
                if ($paymentId === null) {
                    throw new PaymentException('Payment id is invalid.');
                }
                $amountMinor = $this->sourcePaymentAmountMinor($row);
                $refundedMinor = $this->refundedMinorForPayment($paymentId);
                if ($refundedMinor > $amountMinor) {
                    throw new PaymentException('Payment refund exceeds source payment amount.');
                }
            } catch (PaymentException) {
                $this->markInvalidPaymentSummaryBucket($summary, $currency);
                continue;
            }
            if (!isset($summary[$currency])) {
                $summary[$currency] = [
                    'currency' => $currency,
                    'payment_count' => 0,
                    'amount_minor' => 0,
                    'refunded_minor' => 0,
                    'net_paid_minor' => 0,
                ];
            }
            $summary[$currency]['payment_count']++;
            $summary[$currency]['amount_minor'] += $amountMinor;
            $summary[$currency]['refunded_minor'] += $refundedMinor;
            $summary[$currency]['net_paid_minor'] += $amountMinor - $refundedMinor;
        }

        ksort($summary);

        return array_values($summary);
    }

    /** @param array<string,array{currency:string,payment_count:int,amount_minor:int|string,refunded_minor:int|string,net_paid_minor:int|string}> $summary */
    private function markInvalidPaymentSummaryBucket(array &$summary, string $currency): void
    {
        if (!isset($summary[$currency])) {
            $summary[$currency] = [
                'currency' => $currency,
                'payment_count' => 0,
                'amount_minor' => 'invalid',
                'refunded_minor' => 'invalid',
                'net_paid_minor' => 'invalid',
            ];
        }

        $summary[$currency]['payment_count']++;
        $summary[$currency]['amount_minor'] = 'invalid';
        $summary[$currency]['refunded_minor'] = 'invalid';
        $summary[$currency]['net_paid_minor'] = 'invalid';
    }

    /** @return list<array<string,mixed>> */
    public function refundsForPayment(int $paymentId): array
    {
        $paymentId = $this->positiveInt($paymentId, 'Payment refund payment id is invalid.');
        $stmt = $this->pdo->prepare('SELECT * FROM cms_payment_refunds WHERE payment_id = :payment_id ORDER BY id DESC');
        $stmt->execute([':payment_id' => $paymentId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function webhookReceipts(string $providerId = '', int $limit = 20): array
    {
        $limit = $this->boundedReadLimit($limit, 'Payment webhook receipt limit is invalid.');
        if ($providerId !== '') {
            $providerId = $this->normalizedType($providerId, 'Payment webhook provider id is invalid.');
            $stmt = $this->pdo->prepare('SELECT * FROM cms_payment_webhook_receipts WHERE provider_id = :provider_id ORDER BY id DESC LIMIT ' . $limit);
            $stmt->execute([':provider_id' => $providerId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $stmt = $this->pdo->query('SELECT * FROM cms_payment_webhook_receipts ORDER BY id DESC LIMIT ' . $limit);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function webhookReceiptsForPayment(int $paymentId, int $limit = 20): array
    {
        $paymentId = $this->positiveInt($paymentId, 'Payment webhook payment id is invalid.');
        $limit = $this->boundedReadLimit($limit, 'Payment webhook receipt limit is invalid.');
        $stmt = $this->pdo->prepare('SELECT * FROM cms_payment_webhook_receipts WHERE payment_id = :payment_id ORDER BY id DESC LIMIT ' . $limit);
        $stmt->execute([':payment_id' => $paymentId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function webhookReceiptById(int $receiptId): ?array
    {
        $receiptId = $this->positiveInt($receiptId, 'Payment webhook receipt id is invalid.');
        $stmt = $this->pdo->prepare('SELECT * FROM cms_payment_webhook_receipts WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $receiptId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function updateWebhookReceiptStatus(int $receiptId, string $status): bool
    {
        $status = $this->normalizedWebhookReceiptStatus($status);
        if ($status === 'failed') {
            throw new PaymentException('Payment webhook receipt status update is invalid.');
        }
        $receipt = $this->webhookReceiptById($receiptId);
        if ($receipt === null) {
            return false;
        }
        $currentStatus = (string) ($receipt['status'] ?? '');
        if (in_array($currentStatus, ['processed', 'ignored'], true)) {
            return $currentStatus === $status;
        }

        $metadata = $this->storedMetadata($receipt['metadata_json'] ?? '{}', 'Payment webhook receipt metadata is invalid.');
        if ($status === 'processed') {
            unset($metadata['failure_error'], $metadata['failed_at']);
        }

        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            "UPDATE cms_payment_webhook_receipts
             SET status = :status,
                 processed_at = CASE WHEN :status IN ('processed','ignored') THEN :processed_at ELSE processed_at END,
                 metadata_json = :metadata_json,
                 updated_at = :updated_at
             WHERE id = :id"
        );
        $stmt->execute([
            ':id' => $receiptId,
            ':status' => $status,
            ':processed_at' => $now,
            ':metadata_json' => $this->metadataJson($this->safeMetadata($metadata)),
            ':updated_at' => $now,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function attachWebhookReceiptPayment(int $receiptId, int $paymentId): bool
    {
        $receiptId = $this->positiveInt($receiptId, 'Payment webhook receipt id is invalid.');
        $paymentId = $this->positiveInt($paymentId, 'Payment webhook payment id is invalid.');
        $receipt = $this->webhookReceiptById($receiptId);
        if ($receipt === null) {
            return false;
        }
        $payment = $this->payment($paymentId);
        if ($payment === null) {
            throw new PaymentException('Payment webhook payment was not found.');
        }
        if ((string) ($receipt['provider_id'] ?? '') !== (string) ($payment['provider_id'] ?? '')) {
            throw new PaymentException('Payment webhook provider does not match the bound payment.');
        }
        $stmt = $this->pdo->prepare(
            'UPDATE cms_payment_webhook_receipts
             SET payment_id = :payment_id, updated_at = :updated_at
             WHERE id = :id AND (payment_id IS NULL OR payment_id = :payment_id)'
        );
        $stmt->execute([
            ':id' => $receiptId,
            ':payment_id' => $paymentId,
            ':updated_at' => gmdate('c'),
        ]);

        return $stmt->rowCount() === 1;
    }

    /** @param array<string,mixed> $metadata */
    public function markWebhookReceiptFailed(int $receiptId, array $metadata): bool
    {
        $receipt = $this->webhookReceiptById($receiptId);
        if ($receipt === null) {
            return false;
        }

        $merged = $this->storedMetadata($receipt['metadata_json'] ?? '{}', 'Payment webhook receipt metadata is invalid.') + [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || strlen($key) > 64) {
                continue;
            }
            if ($key === 'failure_error' && !$this->webhookFailureErrorSafe($value)) {
                continue;
            }
            if ($key === 'failed_at' && (!is_string($value) || !$this->canonicalUtcTimestamp($value))) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $merged[$key] = $value;
            }
        }

        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            "UPDATE cms_payment_webhook_receipts
             SET status = 'failed',
                 metadata_json = :metadata_json,
                 updated_at = :updated_at
             WHERE id = :id AND status NOT IN ('processed','ignored')"
        );
        $stmt->execute([
            ':id' => $receiptId,
            ':metadata_json' => $this->metadataJson($this->safeMetadata($merged)),
            ':updated_at' => $now,
        ]);

        return $stmt->rowCount() === 1;
    }

    private function webhookFailureErrorSafe(mixed $value): bool
    {
        $secretPattern = '/(?:bearer\s+|payment_token=|sk_[A-Za-z0-9_=-]+|api[_-]?key=|access[_-]?key=|secret=|signature=)/i';
        $decodedValue = is_string($value) ? rawurldecode($value) : '';

        return is_string($value)
            && $value !== ''
            && $value === trim($value)
            && strlen($value) <= 240
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
            && preg_match('/[{}\\[\\]]/', $value) !== 1
            && preg_match($secretPattern, $value) !== 1
            && $decodedValue === trim($decodedValue)
            && preg_match('/[\x00-\x1F\x7F]/', $decodedValue) !== 1
            && preg_match($secretPattern, $decodedValue) !== 1;
    }

    private function webhookPayloadSizeSafe(mixed $value): bool
    {
        return is_int($value) && $value >= 0 && $value <= self::MAX_WEBHOOK_PAYLOAD_BYTES;
    }

    private function webhookMetadataContentTypeSafe(mixed $value): bool
    {
        return is_string($value)
            && $value !== ''
            && $value === trim($value)
            && strlen($value) <= 120
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
            && !$this->metadataValueContainsSecret($value);
    }

    private function webhookMetadataTimestampSafe(mixed $value): bool
    {
        return is_string($value)
            && $value !== ''
            && $value === trim($value)
            && strlen($value) <= 32
            && preg_match('/^[1-9][0-9]{0,11}$/', $value) === 1;
    }

    private function webhookMetadataSourceHashSafe(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }

    /** @return array<string,int|string> */
    public function trustedStatus(string $subjectType, string $subjectId, string $currency = ''): array
    {
        $subjectType = $this->normalizedType($subjectType, 'Payment subject type is invalid.');
        $subjectId = $this->normalizedId($subjectId, 'Payment subject id is invalid.');
        $currency = $currency !== '' ? $this->normalizedCurrency($currency, 'Payment currency is invalid.') : '';
        $paymentCurrencyClause = $currency !== '' ? ' AND currency = :currency' : '';
        $params = [':subject_type' => $subjectType, ':subject_id' => $subjectId];
        if ($currency !== '') {
            $params[':currency'] = $currency;
        }

        $stmt = $this->pdo->prepare("SELECT id, currency, amount_minor FROM cms_payments WHERE subject_type = :subject_type AND subject_id = :subject_id AND status IN ('paid', 'partially_refunded', 'refunded')" . $paymentCurrencyClause);
        $stmt->execute($params);
        $paid = 0;
        $refunded = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $this->normalizedCurrency((string) ($row['currency'] ?? ''), 'Payment currency is invalid.');
            $paymentId = $this->storedPositiveInt($row['id'] ?? null);
            if ($paymentId === null) {
                throw new PaymentException('Payment id is invalid.');
            }
            $amountMinor = $this->sourcePaymentAmountMinor($row);
            $refundedMinor = $this->refundedMinorForPayment($paymentId);
            if ($refundedMinor > $amountMinor) {
                throw new PaymentException('Payment refund exceeds source payment amount.');
            }
            $paid += $amountMinor;
            $refunded += $refundedMinor;
        }

        return [
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'currency' => $currency,
            'paid_minor' => $paid,
            'refunded_minor' => $refunded,
            'net_paid_minor' => max(0, $paid - $refunded),
            'status' => $paid > $refunded ? 'paid' : 'unpaid',
        ];
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    public function recordWebhookReceipt(string $providerId, string $externalEventId, string $payloadHash, string $status, array $metadata): array
    {
        $providerId = $this->normalizedType($providerId, 'Payment webhook provider id is invalid.');
        $externalEventId = $this->normalizedReference($externalEventId, 'Payment webhook event id is invalid.');
        $payloadHash = $this->normalizedHash($payloadHash, 'Payment webhook payload hash is invalid.');
        $status = $this->normalizedWebhookReceiptStatus($status);
        if ($status !== 'received') {
            throw new PaymentException('Payment webhook receipt creation status is invalid.');
        }
        $paymentId = $this->nullablePositiveInt($metadata['payment_id'] ?? null, 'Payment webhook payment id is invalid.');
        if ($paymentId !== null) {
            $payment = $this->payment($paymentId);
            if ($payment === null) {
                throw new PaymentException('Payment webhook payment was not found.');
            }
            if ((string) ($payment['provider_id'] ?? '') !== $providerId) {
                throw new PaymentException('Payment webhook provider does not match the bound payment.');
            }
        }
        if (array_key_exists('payload_size', $metadata) && !$this->webhookPayloadSizeSafe($metadata['payload_size'])) {
            throw new PaymentException('Payment webhook payload-size metadata is invalid.');
        }
        if (array_key_exists('content_type', $metadata) && !$this->webhookMetadataContentTypeSafe($metadata['content_type'])) {
            throw new PaymentException('Payment webhook content type trace metadata is invalid.');
        }
        if (array_key_exists('webhook_timestamp', $metadata) && !$this->webhookMetadataTimestampSafe($metadata['webhook_timestamp'])) {
            throw new PaymentException('Payment webhook timestamp trace metadata is invalid.');
        }
        if (array_key_exists('source_ip_hash', $metadata) && !$this->webhookMetadataSourceHashSafe($metadata['source_ip_hash'])) {
            throw new PaymentException('Payment webhook source trace metadata is invalid.');
        }
        $existing = $this->webhookReceipt($providerId, $externalEventId);
        if ($existing !== null) {
            if ((string) $existing['payload_hash'] !== $payloadHash) {
                throw new PaymentException('Payment webhook event id was reused with different payload.');
            }

            return $existing;
        }

        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_payment_webhook_receipts
                (payment_id, provider_id, external_event_id, payload_hash, status, metadata_json, received_at, processed_at, created_at, updated_at)
             VALUES
                (:payment_id, :provider_id, :external_event_id, :payload_hash, :status, :metadata_json, :received_at, :processed_at, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':payment_id' => $paymentId,
            ':provider_id' => $providerId,
            ':external_event_id' => $externalEventId,
            ':payload_hash' => $payloadHash,
            ':status' => $status,
            ':metadata_json' => $this->metadataJson($this->safeMetadata($metadata)),
            ':received_at' => $now,
            ':processed_at' => in_array($status, ['processed', 'ignored'], true) ? $now : null,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return $this->webhookReceipt($providerId, $externalEventId) ?? [];
    }

    /** @return array<string,mixed>|null */
    private function webhookReceipt(string $providerId, string $externalEventId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_payment_webhook_receipts WHERE provider_id = :provider_id AND external_event_id = :external_event_id LIMIT 1');
        $stmt->execute([':provider_id' => $providerId, ':external_event_id' => $externalEventId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array{0:string,1:array<string,string>} */
    private function paymentSearchWhere(array $filters): array
    {
        $where = [];
        $params = [];

        $status = $this->paymentFilterString($filters, 'status');
        if ($status !== '') {
            $where[] = 'status = :status';
            $params[':status'] = $this->normalizedPaymentStatus($status);
        }

        $providerId = $this->paymentFilterString($filters, 'provider_id');
        if ($providerId !== '') {
            $where[] = 'provider_id = :provider_id';
            $params[':provider_id'] = $this->normalizedType($providerId, 'Payment provider id is invalid.');
        }

        $subjectType = $this->paymentFilterString($filters, 'subject_type');
        if ($subjectType !== '') {
            $where[] = 'subject_type = :subject_type';
            $params[':subject_type'] = $this->normalizedType($subjectType, 'Payment subject type is invalid.');
        }

        $currency = $this->paymentFilterString($filters, 'currency');
        if ($currency !== '') {
            $where[] = 'currency = :currency';
            $params[':currency'] = $this->normalizedCurrency($currency, 'Payment currency is invalid.');
        }

        $createdFrom = $this->paymentDateBoundary($this->paymentFilterString($filters, 'created_from'), false);
        if ($createdFrom !== '') {
            $where[] = 'created_at >= :created_from';
            $params[':created_from'] = $createdFrom;
        }

        $createdTo = $this->paymentDateBoundary($this->paymentFilterString($filters, 'created_to'), true);
        if ($createdTo !== '') {
            $where[] = 'created_at <= :created_to';
            $params[':created_to'] = $createdTo;
        }

        $q = $this->normalizedSearchQuery($this->paymentFilterString($filters, 'q'));
        if ($q !== '') {
            $where[] = '(subject_id LIKE :q OR remote_id LIKE :q OR reference LIKE :q OR idempotency_key LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params];
    }

    /** @param array<string,mixed> $filters */
    private function paymentFilterString(array $filters, string $key): string
    {
        if (!array_key_exists($key, $filters) || $filters[$key] === null) {
            return '';
        }
        if (!is_string($filters[$key])) {
            throw new PaymentException('Payment filter field is invalid.');
        }

        return $filters[$key];
    }

    private function paymentDateBoundary(string $value, bool $endOfDay): string
    {
        if ($value === '') {
            return '';
        }
        if ($value !== trim($value)) {
            throw new PaymentException('Payment date filter is invalid.');
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) === 1) {
            if (!checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
                throw new PaymentException('Payment date filter is invalid.');
            }

            return $value . ($endOfDay ? 'T23:59:59+00:00' : 'T00:00:00+00:00');
        }

        if (!$this->canonicalUtcTimestamp($value)) {
            throw new PaymentException('Payment date filter is invalid.');
        }

        return $value;
    }

    /** @param array<string,mixed> $data */
    private function requiredString(array $data, string $key, string $message): string
    {
        if (!array_key_exists($key, $data) || !is_string($data[$key])) {
            throw new PaymentException($message);
        }

        return $data[$key];
    }

    /** @param array<string,mixed> $data */
    private function optionalString(array $data, string $key, string $message, string $default): string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return $default;
        }
        if (!is_string($data[$key])) {
            throw new PaymentException($message);
        }

        return $data[$key];
    }

    private function positiveInt(mixed $value, string $message): int
    {
        $int = is_int($value) ? $value : (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/', $value) === 1 ? (int) $value : 0);
        if ($int <= 0) {
            throw new PaymentException($message);
        }

        return $int;
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

    private function storedNonNegativeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,17})$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function nonNegativeInt(mixed $value, string $message): int
    {
        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,17})$/', $value) === 1) {
            $int = (int) $value;
        } else {
            throw new PaymentException($message);
        }
        if ($int < 0) {
            throw new PaymentException($message);
        }

        return $int;
    }

    private function nullablePositiveInt(mixed $value, string $message): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->positiveInt($value, $message);
    }

    private function boundedBatchLimit(int $limit, string $message): int
    {
        if ($limit <= 0 || $limit > 1000) {
            throw new PaymentException($message);
        }

        return $limit;
    }

    private function boundedReadLimit(int $limit, string $message): int
    {
        if ($limit <= 0) {
            throw new PaymentException($message);
        }

        return min(100, $limit);
    }

    private function boundedSearchPage(mixed $value, string $message): int
    {
        $page = $this->strictPositiveInt($value, $message);

        return min(1000000, $page);
    }

    private function boundedSearchPerPage(mixed $value, string $message): int
    {
        $perPage = $this->strictPositiveInt($value, $message);

        return min(100, $perPage);
    }

    private function boundedExportLimit(int $limit, string $message): int
    {
        if ($limit <= 0) {
            throw new PaymentException($message);
        }

        return min(10000, $limit);
    }

    private function strictPositiveInt(mixed $value, string $message): int
    {
        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/', $value) === 1) {
            $int = (int) $value;
        } else {
            throw new PaymentException($message);
        }
        if ($int <= 0) {
            throw new PaymentException($message);
        }

        return $int;
    }

    private function normalizedType(string $value, string $message): string
    {
        $normalized = strtolower(trim($value));
        if ($value !== $normalized) {
            throw new PaymentException($message);
        }
        $value = $normalized;
        if (preg_match('/^[a-z0-9][a-z0-9._-]{1,95}[a-z0-9]$/', $value) !== 1) {
            throw new PaymentException($message);
        }

        return $value;
    }

    private function normalizedId(string $value, string $message): string
    {
        if (
            $value === ''
            || $value !== trim($value)
            || strlen($value) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            || $this->metadataValueContainsSecret($value)
        ) {
            throw new PaymentException($message);
        }

        return $value;
    }

    private function normalizedReference(string $value, string $message): string
    {
        if (
            $value === ''
            || $value !== trim($value)
            || strlen($value) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            || $this->metadataValueContainsSecret($value)
        ) {
            throw new PaymentException($message);
        }

        return $value;
    }

    private function normalizedHash(string $value, string $message): string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new PaymentException($message);
        }

        return $value;
    }

    private function normalizedAuthorizationStatus(string $status): string
    {
        if (!in_array($status, ['active', 'revoked', 'expired'], true)) {
            throw new PaymentException('Payment authorization status is invalid.');
        }

        return $status;
    }

    private function normalizedPaymentStatus(string $status): string
    {
        if (!in_array($status, ['pending', 'authorized', 'paid', 'partially_refunded', 'refunded', 'failed', 'cancelled'], true)) {
            throw new PaymentException('Payment status is invalid.');
        }

        return $status;
    }

    private function assertPaymentStatusTransition(string $currentStatus, string $nextStatus): void
    {
        $currentStatus = $this->normalizedPaymentStatus($currentStatus);
        $nextStatus = $this->normalizedPaymentStatus($nextStatus);
        if ($currentStatus === $nextStatus) {
            return;
        }

        $allowed = [
            'pending' => ['authorized', 'paid', 'failed', 'cancelled'],
            'authorized' => ['paid', 'failed', 'cancelled'],
            'paid' => ['partially_refunded', 'refunded'],
            'partially_refunded' => ['refunded'],
            'refunded' => [],
            'failed' => [],
            'cancelled' => [],
        ];
        if (!in_array($nextStatus, $allowed[$currentStatus] ?? [], true)) {
            throw new PaymentException('Payment status transition is not allowed.');
        }
    }

    /** @param array<string,mixed> $payment */
    private function assertRefundStatusTotals(array $payment, string $nextStatus): void
    {
        if (!in_array($nextStatus, ['partially_refunded', 'refunded'], true)) {
            return;
        }

        $paymentId = $this->storedPositiveInt($payment['id'] ?? null);
        if ($paymentId === null) {
            throw new PaymentException('Payment id is invalid.');
        }
        $amountMinor = $this->sourcePaymentAmountMinor($payment);
        $refundedMinor = $this->refundedMinorForPayment($paymentId);
        if ($nextStatus === 'partially_refunded' && ($refundedMinor <= 0 || $refundedMinor >= $amountMinor)) {
            throw new PaymentException('Payment partially refunded status does not match completed refund totals.');
        }
        if ($nextStatus === 'refunded' && $refundedMinor !== $amountMinor) {
            throw new PaymentException('Payment refunded status does not match completed refund totals.');
        }
    }

    /** @param array<string,mixed> $payment */
    private function sourcePaymentAmountMinor(array $payment): int
    {
        $value = $payment['amount_minor'] ?? null;
        if (is_int($value)) {
            $amountMinor = $value;
        } elseif (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,17})$/', $value) === 1) {
            $amountMinor = (int) $value;
        } else {
            throw new PaymentException('Payment source amount is invalid.');
        }

        if ($amountMinor <= 0) {
            throw new PaymentException('Payment source amount is invalid.');
        }

        return $amountMinor;
    }

    private function normalizedRefundStatus(string $status): string
    {
        if (!in_array($status, ['pending', 'completed', 'failed', 'cancelled'], true)) {
            throw new PaymentException('Payment refund status is invalid.');
        }

        return $status;
    }

    private function normalizedWebhookReceiptStatus(string $status): string
    {
        if (!in_array($status, ['received', 'processed', 'ignored', 'failed'], true)) {
            throw new PaymentException('Payment webhook receipt status is invalid.');
        }

        return $status;
    }

    private function normalizedCurrency(string $currency, string $message): string
    {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new PaymentException($message);
        }

        return $currency;
    }

    private function normalizedSearchQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }
        if ($query !== trim($query)) {
            throw new PaymentException('Payment search query is invalid.');
        }
        if (
            $query !== ''
            && (
                strlen($query) > 191
                || preg_match('/[\x00-\x1F\x7F]/', $query) === 1
                || $this->metadataValueContainsSecret($query)
            )
        ) {
            throw new PaymentException('Payment search query is invalid.');
        }

        return $query;
    }

    private function normalizedReason(string $reason, string $message): string
    {
        if ($reason !== '' && $reason !== trim($reason)) {
            throw new PaymentException($message);
        }
        if (strlen($reason) > 500 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason) === 1) {
            throw new PaymentException($message);
        }

        return $reason;
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

    /** @param array<string,mixed> $metadata */
    private function metadataJson(array $metadata): string
    {
        try {
            return json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PaymentException('Payment metadata is invalid.');
        }
    }

    /** @return array<string,mixed> */
    private function storedMetadata(mixed $json, string $message): array
    {
        if (!is_string($json)) {
            throw new PaymentException($message);
        }
        try {
            $metadata = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PaymentException($message);
        }
        if (!is_array($metadata) || ($metadata !== [] && array_is_list($metadata))) {
            throw new PaymentException($message);
        }

        return $metadata;
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

    private function normalizedEntitlementStatus(string $status): string
    {
        if (!in_array($status, ['active', 'revoked', 'expired'], true)) {
            throw new PaymentException('Payment entitlement status is invalid.');
        }

        return $status;
    }

    private function normalizedFutureTimestamp(string $value, string $message): string
    {
        if (!$this->futureCanonicalUtcTimestamp($value)) {
            throw new PaymentException($message);
        }

        return $value;
    }

    private function normalizedNullableFutureTimestamp(string $value, string $message): ?string
    {
        if ($value === '') {
            return null;
        }
        if ($value !== trim($value)) {
            throw new PaymentException($message);
        }

        return $this->normalizedFutureTimestamp($value, $message);
    }

    private function futureCanonicalUtcTimestamp(string $value): bool
    {
        if (!$this->canonicalUtcTimestamp($value)) {
            return false;
        }

        $timestamp = strtotime($value);

        return $timestamp !== false && $timestamp > time();
    }

    private function canonicalUtcTimestamp(string $value): bool
    {
        if ($value === '' || $value !== trim($value)) {
            return false;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})\+00:00$/', $value, $matches) !== 1) {
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
}
