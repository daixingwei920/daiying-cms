<?php

declare(strict_types=1);

namespace Cms\Core\Payment;

use Cms\Core\Audit\AuditLogger;
use Cms\Core\Support\CurrencyRegistry;
use PDO;
use Throwable;

final class PaymentService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PaymentRepository $payments,
        private readonly string $providerSettingsKey = '',
    ) {
    }

    /** @return list<array{id:string,label:string}> */
    public function enabledProviders(string $currency = ''): array
    {
        $currency = $this->normalizeCurrencyFilter($currency);
        $providers = [];
        $repo = new PaymentProviderSettingsRepository($this->pdo, $this->providerSettingsKey);
        foreach ($repo->all() as $setting) {
            try {
                $providerId = $this->normalizeProviderId((string) ($setting['provider_id'] ?? ''));
            } catch (PaymentException) {
                continue;
            }
            if ((string) ($setting['status'] ?? '') !== 'enabled') {
                continue;
            }
            $provider = PaymentProviderRegistry::get($providerId);
            if ($provider === null || !in_array('payment.create', $provider->capabilities(), true)) {
                continue;
            }
            if (!$this->providerCheckoutAvailable($providerId, $setting, $currency)) {
                continue;
            }
            $label = $this->providerDisplayName($providerId, $setting);
            $providers[] = [
                'id' => $providerId,
                'label' => $label !== '' ? $label : $provider->displayName(),
            ];
        }

        return $providers;
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    public function createProviderPayment(
        string $subjectType,
        string $subjectId,
        string $providerId,
        int $amountMinor,
        string $currency,
        string $idempotencyKey,
        string $scenario = 'success',
        array $metadata = [],
    ): array {
        $subjectType = $this->normalizeSubjectType($subjectType);
        $subjectId = $this->normalizeSubjectId($subjectId);
        $providerId = $this->normalizeProviderId($providerId);
        $currency = $this->normalizeCurrency($currency);
        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);
        $scenario = $this->normalizeProviderScenario($scenario);
        if ($amountMinor <= 0) {
            throw new PaymentException('Payment amount must be positive.');
        }
        if (!$this->providerSupportsCurrency($providerId, $currency)) {
            throw new PaymentException('Payment provider does not support this currency.');
        }

        $provider = $this->provider($providerId, 'payment.create');
        $requestHash = $this->requestHash([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'provider_id' => $providerId,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'scenario' => $scenario,
        ]);
        $alreadyInTransaction = $this->pdo->inTransaction();
        $this->beginImmediate();
        try {
            $existing = $this->payments->paymentByIdempotencyForUpdate($idempotencyKey);
            if ($existing !== null) {
                if ((string) ($existing['request_hash'] ?? '') !== $requestHash) {
                    throw new PaymentException('Payment idempotency key was reused with different content.');
                }
                if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }

                return $existing;
            }

            $result = $provider->createPayment($this->providerCommand($providerId, [
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'idempotency_key' => $idempotencyKey,
                'scenario' => $scenario,
                'metadata' => $metadata,
            ]));
            $this->assertProviderResultEnvelope($result);
            $providerStatus = $this->providerResultString($result, 'status');
            if (!$result->success && !in_array($providerStatus ?? '', ['failed', 'cancelled'], true)) {
                throw new PaymentException('Payment provider rejected the payment request.');
            }
            $this->assertSafeProviderRedirectData($providerId, $result->data);

            $status = $this->normalizePaymentStatus($providerStatus ?? ($result->success ? 'paid' : 'failed'));
            $remoteId = $this->providerResultString($result, 'provider_payment_id') ?? $result->requestId;
            if ($remoteId === '') {
                $remoteId = hash('sha256', $providerId . '|' . $idempotencyKey);
            }
            $remoteId = $this->normalizeRemoteReference($remoteId, 'payment');
            if ($this->payments->paymentByRemote($providerId, $remoteId) !== null) {
                throw new PaymentException('Payment provider returned a duplicate remote payment reference.');
            }

            $paymentId = $this->payments->insertPayment([
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'provider_id' => $providerId,
                'remote_id' => $remoteId,
                'reference' => $result->requestId !== '' ? $result->requestId : $remoteId,
                'status' => $status,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'metadata' => $this->safeMetadata($metadata + $result->data + ['provider_code' => $result->code]),
            ]);
            $this->recordAudit('payment.provider.created', [
                'payment_id' => $paymentId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'provider_id' => $providerId,
                'remote_id' => $remoteId,
                'status' => $status,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'idempotency_key' => $idempotencyKey,
                'provider_code' => $result->code,
            ]);
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }

            $payment = $this->payments->payment($paymentId) ?? [];
            $transientCheckoutUrl = $this->providerCheckoutUrlFromResultData($providerId, $result->data);
            if ($transientCheckoutUrl !== '') {
                $payment['_provider_checkout_url'] = $transientCheckoutUrl;
            }

            return $payment;
        } catch (Throwable $exception) {
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->recordProviderCreateFailure(
                $subjectType,
                $subjectId,
                $providerId,
                $amountMinor,
                $currency,
                $idempotencyKey,
                $exception,
            );
            if ($exception instanceof PaymentProviderDiagnosticException) {
                throw new PaymentException('Payment provider create failed.', 0, $exception);
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    public function captureProviderPayment(int $paymentId, string $idempotencyKey = ''): array
    {
        $idempotencyKey = $this->normalizeOptionalIdempotencyKey($idempotencyKey);
        $payment = $this->existingPayment($paymentId);
        $providerId = $this->existingPaymentProviderId($payment);
        $remoteId = $this->existingPaymentRemoteId($payment);
        $subjectType = $this->existingPaymentSubjectType($payment);
        $subjectId = $this->existingPaymentSubjectId($payment);
        if (!in_array($this->existingPaymentStatus($payment), ['pending', 'authorized'], true)) {
            throw new PaymentException('Payment is not capturable.');
        }
        $amountMinor = $this->existingPaymentAmountMinor($payment);
        $currency = $this->existingPaymentCurrency($payment);

        $provider = $this->provider($providerId, 'payment.capture');
        $result = $provider->capturePayment($this->providerCommand($providerId, [
            'payment_id' => $paymentId,
            'provider_payment_id' => $remoteId,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'idempotency_key' => $idempotencyKey,
        ]));
        $this->assertProviderResultEnvelope($result);
        if (!$result->success) {
            $this->persistRejectedProviderResult($payment, $result, 'payment.provider.capture_rejected', [
                'payment_id' => $paymentId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'provider_id' => $providerId,
                'remote_id' => $remoteId,
                'status' => $this->existingPaymentStatus($payment),
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'idempotency_key' => $idempotencyKey,
                'provider_code' => $result->code,
            ]);
            throw new PaymentException('Payment provider rejected the capture request.');
        }

        $alreadyInTransaction = $this->pdo->inTransaction();
        $this->beginImmediate();
        try {
            $this->updatePaymentFromProviderResult($payment, $result, ['paid']);
            $this->mergePaymentMetadata($payment, $result);
            $updated = $this->payments->payment($paymentId) ?? [];
            $this->recordAudit('payment.provider.captured', [
                'payment_id' => $paymentId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'provider_id' => $providerId,
                'remote_id' => $this->existingPaymentRemoteId($updated !== [] ? $updated : $payment),
                'status' => (string) ($updated['status'] ?? 'paid'),
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'idempotency_key' => $idempotencyKey,
                'provider_code' => $result->code,
            ]);
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return $updated;
    }

    /** @return array<string,mixed> */
    public function settleHostedCheckoutPayment(int $paymentId, string $completionKey = ''): array
    {
        $payment = $this->existingPayment($paymentId);
        if (in_array($this->existingPaymentStatus($payment), ['pending', 'authorized'], true)) {
            $payment = $this->syncProviderPaymentStatus($paymentId);
        }
        if (in_array($this->existingPaymentStatus($payment), ['pending', 'authorized'], true)) {
            try {
                $captureKey = 'hosted-return-' . substr(hash('sha256', $paymentId . '|' . $completionKey), 0, 48);
                $payment = $this->captureProviderPayment($paymentId, $captureKey);
            } catch (PaymentException $exception) {
                if ($exception->getMessage() !== 'Payment provider does not support the requested action.') {
                    throw $exception;
                }
            }
        }

        return $payment;
    }

    /** @return array<string,mixed> */
    public function cancelProviderPayment(int $paymentId, string $idempotencyKey = ''): array
    {
        $idempotencyKey = $this->normalizeOptionalIdempotencyKey($idempotencyKey);
        $payment = $this->existingPayment($paymentId);
        $providerId = $this->existingPaymentProviderId($payment);
        $remoteId = $this->existingPaymentRemoteId($payment);
        $subjectType = $this->existingPaymentSubjectType($payment);
        $subjectId = $this->existingPaymentSubjectId($payment);
        if (!in_array($this->existingPaymentStatus($payment), ['pending', 'authorized'], true)) {
            throw new PaymentException('Payment is not cancellable.');
        }
        $amountMinor = $this->existingPaymentAmountMinor($payment);
        $currency = $this->existingPaymentCurrency($payment);

        $provider = $this->provider($providerId, 'payment.cancel');
        $result = $provider->cancelPayment($this->providerCommand($providerId, [
            'payment_id' => $paymentId,
            'provider_payment_id' => $remoteId,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'idempotency_key' => $idempotencyKey,
            ]));
        $this->assertProviderResultEnvelope($result);
        if (!$result->success) {
            throw new PaymentException('Payment provider rejected the cancel request.');
        }

        $alreadyInTransaction = $this->pdo->inTransaction();
        $this->beginImmediate();
        try {
            $this->updatePaymentFromProviderResult($payment, $result, ['cancelled']);
            $updated = $this->payments->payment($paymentId) ?? [];
            $this->recordAudit('payment.provider.cancelled', [
                'payment_id' => $paymentId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'provider_id' => $providerId,
                'remote_id' => $this->existingPaymentRemoteId($updated !== [] ? $updated : $payment),
                'status' => (string) ($updated['status'] ?? 'cancelled'),
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'idempotency_key' => $idempotencyKey,
                'provider_code' => $result->code,
            ]);
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return $updated;
    }

    /** @return array<string,mixed> */
    public function syncProviderPaymentStatus(int $paymentId, string $expectedStatus = ''): array
    {
        $payment = $this->existingPayment($paymentId);
        $providerId = $this->existingPaymentProviderId($payment);
        $remoteId = $this->existingPaymentRemoteId($payment);
        $subjectType = $this->existingPaymentSubjectType($payment);
        $subjectId = $this->existingPaymentSubjectId($payment);
        $currentStatus = $this->existingPaymentStatus($payment);
        $expectedStatus = $expectedStatus === '' ? $currentStatus : $this->normalizePaymentStatus($expectedStatus);
        $provider = $this->provider($providerId, 'payment.status');
        $result = $provider->getPaymentStatus($this->providerCommand($providerId, [
            'payment_id' => $paymentId,
            'provider_payment_id' => $remoteId,
            'current_status' => $currentStatus,
            'expected_status' => $expectedStatus,
        ]));
        $this->assertProviderResultEnvelope($result);
        if (!$result->success) {
            throw new PaymentException('Payment provider rejected the status request.');
        }

        $alreadyInTransaction = $this->pdo->inTransaction();
        $this->beginImmediate();
        try {
            $this->updatePaymentFromProviderResult($payment, $result);
            $this->mergePaymentMetadata($payment, $result);
            $updated = $this->payments->payment($paymentId) ?? [];
            $this->recordAudit('payment.provider.status_synced', [
                'payment_id' => $paymentId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'provider_id' => $providerId,
                'remote_id' => $this->existingPaymentRemoteId($updated !== [] ? $updated : $payment),
                'previous_status' => $currentStatus,
                'status' => $updated !== [] ? $this->existingPaymentStatus($updated) : '',
                'expected_status' => $expectedStatus,
                'provider_code' => $result->code,
            ]);
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return $updated;
    }

    /** @return array<string,mixed> */
    public function revokePaymentAuthorization(int $paymentId, int $authorizationId): array
    {
        $this->existingPayment($paymentId);
        if ($authorizationId <= 0) {
            throw new PaymentException('Payment authorization id is invalid.');
        }

        $authorization = $this->payments->authorization($authorizationId);
        $authorizationPaymentId = is_array($authorization) ? $this->storedPositiveInt($authorization['payment_id'] ?? null) : null;
        if ($authorization === null || $authorizationPaymentId !== $paymentId) {
            throw new PaymentException('Payment authorization was not found.');
        }
        if ((string) ($authorization['status'] ?? '') !== 'active') {
            throw new PaymentException('Payment authorization is not active.');
        }
        if (!$this->payments->revokeAuthorization($authorizationId)) {
            throw new PaymentException('Payment authorization could not be revoked.');
        }

        return $this->payments->authorization($authorizationId) ?? [];
    }

    public function expirePaymentAuthorizations(int $limit = 100): int
    {
        if ($limit <= 0 || $limit > 1000) {
            throw new PaymentException('Payment authorization expiry limit is invalid.');
        }
        $alreadyInTransaction = $this->pdo->inTransaction();
        $this->beginImmediate();
        try {
            $count = $this->payments->expireExpiredAuthorizations($limit);
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }

            return $count;
        } catch (Throwable $exception) {
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function expirePaymentEntitlements(int $limit = 100): int
    {
        if ($limit <= 0 || $limit > 1000) {
            throw new PaymentException('Payment entitlement expiry limit is invalid.');
        }
        $alreadyInTransaction = $this->pdo->inTransaction();
        $this->beginImmediate();
        try {
            $count = $this->payments->expireExpiredEntitlements($limit);
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }

            return $count;
        } catch (Throwable $exception) {
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    public function refundProviderPayment(int $paymentId, int $amountMinor, string $reason, string $idempotencyKey): array
    {
        if ($paymentId <= 0 || $amountMinor <= 0) {
            throw new PaymentException('Refund request is incomplete.');
        }
        $reason = $this->normalizeRefundReason($reason);
        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);
        $requestHash = $this->requestHash(['payment_id' => $paymentId, 'amount_minor' => $amountMinor, 'reason' => $reason]);
        $alreadyInTransaction = $this->pdo->inTransaction();
        $this->beginImmediate();
        try {
            $existing = $this->payments->refundByIdempotencyForUpdate($idempotencyKey);
            if ($existing !== null) {
                if ((string) ($existing['request_hash'] ?? '') !== $requestHash) {
                    throw new PaymentException('Refund idempotency key was reused with different content.');
                }
                if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }

                return $existing;
            }

            $payment = $this->payments->payment($paymentId);
            if ($payment === null || !in_array($this->existingPaymentStatus($payment), ['paid', 'partially_refunded'], true)) {
                throw new PaymentException('Payment is not refundable.');
            }
            $providerId = $this->existingPaymentProviderId($payment);
            $remoteId = $this->existingPaymentRemoteId($payment);
            $subjectType = $this->existingPaymentSubjectType($payment);
            $subjectId = $this->existingPaymentSubjectId($payment);
            $sourceAmountMinor = $this->existingPaymentAmountMinor($payment);
            $currency = $this->existingPaymentCurrency($payment);
            $completed = $this->payments->refundedMinorForPayment($paymentId);
            if ($completed + $amountMinor > $sourceAmountMinor) {
                throw new PaymentException('Refund exceeds captured payment.');
            }

            $provider = $this->provider($providerId, 'payment.refund');
            $result = $provider->refundPayment($this->providerCommand($providerId, [
                'payment_id' => $paymentId,
                'provider_payment_id' => $remoteId,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
                'metadata' => $this->storedPaymentMetadata($payment),
            ]));
            $this->assertProviderResultEnvelope($result);
            if (!$result->success) {
                throw new PaymentException('Payment provider rejected the refund request.');
            }
            $refundRemoteId = $this->providerResultString($result, 'provider_refund_id') ?? $result->requestId;
            if ($refundRemoteId === '') {
                $refundRemoteId = 'core-refund-' . substr(hash('sha256', $providerId . '|' . $paymentId . '|' . $idempotencyKey), 0, 48);
            }
            $refundRemoteId = $this->normalizeRemoteReference($refundRemoteId, 'refund');
            if ($this->payments->refundByRemote($providerId, $refundRemoteId) !== null) {
                throw new PaymentException('Payment provider returned a duplicate remote refund reference.');
            }

            $refundId = $this->payments->insertRefund([
                'payment_id' => $paymentId,
                'provider_id' => $providerId,
                'remote_id' => $refundRemoteId,
                'status' => $this->normalizeRefundStatus($this->providerResultString($result, 'status') ?? 'completed'),
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'metadata' => $this->safeMetadata($result->data + ['provider_code' => $result->code]),
            ]);
            $refund = $this->payments->refund($refundId) ?? [];
            if ((string) ($refund['status'] ?? '') === 'completed') {
                $refunded = $this->payments->refundedMinorForPayment($paymentId);
                $nextStatus = $refunded >= $sourceAmountMinor ? 'refunded' : 'partially_refunded';
                $accessCounts = $nextStatus === 'refunded' ? $this->activeAccessCountsForPayment($paymentId) : ['authorization_count' => 0, 'entitlement_count' => 0];
                $this->payments->updatePaymentStatus($paymentId, $nextStatus);
                if ($nextStatus === 'refunded') {
                    $this->recordAccessRevokedAfterRefund($paymentId, 'provider_refund', $accessCounts);
                }
            }
            $this->recordAudit('payment.provider.refunded', [
                'payment_id' => $paymentId,
                'refund_id' => $refundId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'provider_id' => $providerId,
                'remote_id' => $remoteId,
                'refund_remote_id' => $refundRemoteId,
                'status' => (string) ($refund['status'] ?? ''),
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
                'provider_code' => $result->code,
            ]);
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }

            return $refund;
        } catch (Throwable $exception) {
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    public function recordWebhookReceipt(string $providerId, string $externalEventId, string $rawPayload, string $status = 'received', array $metadata = []): array
    {
        $providerId = $this->normalizeProviderId($providerId);
        if (
            $externalEventId === ''
            || $externalEventId !== trim($externalEventId)
            || strlen($externalEventId) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $externalEventId) === 1
            || $this->metadataValueContainsSecret($externalEventId)
        ) {
            throw new PaymentException('Payment webhook event id is invalid.');
        }
        if ($status !== 'received') {
            throw new PaymentException('Payment webhook status is invalid.');
        }

        return $this->payments->recordWebhookReceipt($providerId, $externalEventId, hash('sha256', $rawPayload), $status, $this->safeMetadata($this->webhookReceiptMetadata($metadata, strlen($rawPayload))));
    }

    /** @return array<string,mixed>|null */
    public function applyWebhookPaymentStatus(string $providerId, int $receiptId, string $rawPayload): ?array
    {
        $providerId = $this->normalizeProviderId($providerId);
        $receipt = $this->payments->webhookReceiptById($receiptId);
        if ($receipt === null || (string) ($receipt['provider_id'] ?? '') !== $providerId) {
            throw new PaymentException('Payment webhook receipt was not found.');
        }
        if (!hash_equals((string) ($receipt['payload_hash'] ?? ''), hash('sha256', $rawPayload))) {
            throw new PaymentException('Payment webhook payload does not match the recorded receipt.');
        }
        if (in_array((string) ($receipt['status'] ?? ''), ['processed', 'ignored'], true)) {
            $paymentId = $this->storedPositiveInt($receipt['payment_id'] ?? null);
            return $paymentId !== null ? $this->payments->payment($paymentId) : null;
        }

        try {
            $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PaymentException('Payment webhook payload must be a JSON object.');
        }
        if (!is_array($payload)) {
            throw new PaymentException('Payment webhook payload must be a JSON object.');
        }

        $update = $this->webhookPaymentUpdate($payload);
        $refundUpdate = $this->webhookRefundUpdate($payload);
        if ($update === null && $refundUpdate === null) {
            $alreadyInTransaction = $this->pdo->inTransaction();
            $this->beginImmediate();
            try {
                if (!$this->payments->updateWebhookReceiptStatus($receiptId, 'ignored')) {
                    throw new PaymentException('Payment webhook receipt could not be marked ignored.');
                }
                $this->recordAudit('payment.webhook.ignored', [
                    'receipt_id' => $receiptId,
                    'provider_id' => $providerId,
                    'external_event_id' => (string) ($receipt['external_event_id'] ?? ''),
                    'reason' => 'no_payment_or_refund_update',
                ]);
                if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }
            } catch (Throwable $exception) {
                if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }
            return null;
        }

        $remoteId = (string) (($refundUpdate['remote_id'] ?? '') !== '' ? $refundUpdate['remote_id'] : ($update['remote_id'] ?? ''));
        $payment = $this->payments->paymentByRemote($providerId, $remoteId);
        if ($payment === null) {
            throw new PaymentException('Payment webhook target payment was not found.');
        }
        $paymentId = $this->storedPositiveInt($payment['id'] ?? null);
        if ($paymentId === null) {
            throw new PaymentException('Payment webhook target payment id is invalid.');
        }

        $alreadyInTransaction = $this->pdo->inTransaction();
        $this->beginImmediate();
        try {
            if (!$this->payments->attachWebhookReceiptPayment($receiptId, $paymentId)) {
                throw new PaymentException('Payment webhook receipt could not be bound to the target payment.');
            }
            if ($refundUpdate !== null) {
                $this->recordWebhookRefund($payment, $receipt, $refundUpdate);
                $payment = $this->payments->payment($paymentId) ?? $payment;
            }
            if ($update !== null) {
                if (!$this->isStaleRefundWebhookPaymentStatus($payment, $refundUpdate, $update['status'])) {
                    $this->updatePaymentFromProviderResult($payment, new PaymentResult(true, 'webhook_status', 'Webhook status applied.', false, '', [
                        'status' => $update['status'],
                        'provider_payment_id' => $update['remote_id'],
                    ]), ['pending', 'authorized', 'paid', 'partially_refunded', 'refunded', 'failed', 'cancelled']);
                }
            }
            if (!$this->payments->updateWebhookReceiptStatus($receiptId, 'processed')) {
                throw new PaymentException('Payment webhook receipt could not be marked processed.');
            }
            $this->recordAudit('payment.webhook.applied', [
                'payment_id' => $paymentId,
                'receipt_id' => $receiptId,
                'provider_id' => $providerId,
                'external_event_id' => (string) ($receipt['external_event_id'] ?? ''),
                'remote_id' => $remoteId,
                'payment_status' => is_array($update) ? (string) ($update['status'] ?? '') : '',
                'refund_status' => is_array($refundUpdate) ? (string) ($refundUpdate['status'] ?? '') : '',
                'refund_amount_minor' => is_array($refundUpdate) ? (int) ($refundUpdate['amount_minor'] ?? 0) : 0,
            ]);
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return $this->payments->payment($paymentId);
    }

    /** @return array<string,mixed> */
    public function updateWebhookReceiptStatus(int $receiptId, string $status): array
    {
        if ($receiptId <= 0 || !in_array($status, ['processed', 'ignored'], true)) {
            throw new PaymentException('Payment webhook receipt status update is invalid.');
        }

        $receipt = $this->payments->webhookReceiptById($receiptId);
        if ($receipt === null) {
            throw new PaymentException('Payment webhook receipt was not found.');
        }
        if (!$this->payments->updateWebhookReceiptStatus($receiptId, $status)) {
            throw new PaymentException('Payment webhook receipt could not be updated.');
        }

        return $this->payments->webhookReceiptById($receiptId) ?? [];
    }

    /** @return array<string,mixed> */
    public function markWebhookReceiptFailed(int $receiptId, string $error): array
    {
        if ($receiptId <= 0) {
            throw new PaymentException('Payment webhook receipt was not found.');
        }

        $summary = $this->safeWebhookFailureSummary($error);

        if (!$this->payments->markWebhookReceiptFailed($receiptId, [
            'failure_error' => $summary,
            'failed_at' => gmdate('c'),
        ])) {
            throw new PaymentException('Payment webhook receipt could not be marked failed.');
        }

        return $this->payments->webhookReceiptById($receiptId) ?? [];
    }

    private function safeWebhookFailureSummary(string $error): string
    {
        $summary = trim(preg_replace('/\s+/', ' ', $error) ?? '');
        if ($summary === '') {
            return 'Payment webhook application failed.';
        }
        if (strlen($summary) > 240) {
            $summary = substr($summary, 0, 237) . '...';
        }
        $secretPattern = '/(?:bearer\s+|payment_token=|sk_[A-Za-z0-9_=-]+|api[_-]?key=|access[_-]?key=|secret=|signature=)/i';
        $decodedSummary = rawurldecode($summary);
        if (
            $summary !== trim($summary)
            || preg_match('/[\x00-\x1F\x7F]/', $summary) === 1
            || preg_match('/[{}\\[\\]]/', $summary) === 1
            || preg_match($secretPattern, $summary) === 1
            || $decodedSummary !== trim($decodedSummary)
            || preg_match('/[\x00-\x1F\x7F]/', $decodedSummary) === 1
            || preg_match($secretPattern, $decodedSummary) === 1
        ) {
            return 'Payment webhook application failed.';
        }

        return $summary;
    }

    /** @return array<string,int|string> */
    public function trustedStatus(string $subjectType, string $subjectId, string $currency = ''): array
    {
        return $this->payments->trustedStatus(
            $this->normalizeSubjectType($subjectType),
            $this->normalizeSubjectId($subjectId),
            $currency !== '' ? $this->normalizeCurrency($currency) : '',
        );
    }

    private function provider(string $providerId, string $capability): PaymentProviderInterface
    {
        $provider = PaymentProviderRegistry::get($providerId);
        if ($provider === null) {
            throw new PaymentException('Payment provider is not available.');
        }
        if (!$this->providerEnabled($providerId)) {
            throw new PaymentException('Payment provider is not enabled.');
        }
        if (!in_array($capability, $provider->capabilities(), true)) {
            throw new PaymentException('Payment provider does not support the requested action.');
        }

        return $provider;
    }

    private function providerEnabled(string $providerId): bool
    {
        $setting = (new PaymentProviderSettingsRepository($this->pdo, $this->providerSettingsKey))
            ->setting(PaymentProviderRegistry::normalize($providerId));

        return is_array($setting) && (string) ($setting['status'] ?? '') === 'enabled';
    }

    /** @param array<string,mixed> $setting */
    private function providerCheckoutAvailable(string $providerId, array $setting, string $currency = ''): bool
    {
        $provider = PaymentProviderRegistry::get($providerId);
        if ($provider === null || !in_array('payment.create', $provider->capabilities(), true)) {
            return false;
        }
        if ($currency !== '' && !$this->providerSupportsCurrency($providerId, $currency)) {
            return false;
        }
        try {
            $public = $this->providerStoredPublicConfig($setting);
        } catch (PaymentException) {
            return false;
        }
        if ($provider instanceof PaymentProviderConfigurationInterface) {
            try {
                $maskedSecrets = (new PaymentProviderSettingsRepository($this->pdo, $this->providerSettingsKey))->maskedSecrets($providerId);
            } catch (PaymentException) {
                return false;
            }
            return $provider->isConfigured($public, $maskedSecrets);
        }
        if ($providerId !== HostedRedirectPaymentProvider::PROVIDER_ID) {
            return true;
        }

        $checkoutUrl = (string) ($public['checkout_url'] ?? $public['checkout_base_url'] ?? '');
        $returnUrlBase = (string) ($public['return_url_base'] ?? '');

        return $this->isSafeProviderRedirectUrl($checkoutUrl)
            && ($returnUrlBase === '' || $this->isSafeReturnUrlBase($returnUrlBase));
    }

    /** @param array<string,mixed> $setting @return array<string,mixed> */
    private function providerStoredPublicConfig(array $setting): array
    {
        $raw = (string) ($setting['public_config_json'] ?? '{}');
        if ($raw === '') {
            $raw = '{}';
        }
        if ($raw !== trim($raw)) {
            throw new PaymentException('Payment provider public config is invalid.');
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PaymentException('Payment provider public config is invalid.');
        }
        if (!is_array($decoded)) {
            throw new PaymentException('Payment provider public config is invalid.');
        }
        $canonical = $raw === '{}' && $decoded === []
            ? '{}'
            : $this->providerPublicConfigJson($decoded);
        if ($raw !== $canonical) {
            throw new PaymentException('Payment provider public config is invalid.');
        }

        return $this->validatedProviderPublicConfig($decoded);
    }

    /** @param array<string,mixed> $data */
    private function providerCommand(string $providerId, array $data): object
    {
        $setting = (new PaymentProviderSettingsRepository($this->pdo, $this->providerSettingsKey))->setting($providerId);
        $publicConfig = [];
        if (is_array($setting)) {
            $rawPublicConfig = (string) ($setting['public_config_json'] ?? '');
            if ($rawPublicConfig !== trim($rawPublicConfig)) {
                throw new PaymentException('Payment provider public config is invalid.');
            }
            if ($rawPublicConfig !== '') {
                try {
                    $decoded = json_decode($rawPublicConfig, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new PaymentException('Payment provider public config is invalid.');
                }
                if (!is_array($decoded)) {
                    throw new PaymentException('Payment provider public config is invalid.');
                }
                $canonicalPublicConfig = $rawPublicConfig === '{}' && $decoded === []
                    ? '{}'
                    : $this->providerPublicConfigJson($decoded);
                if ($rawPublicConfig !== $canonicalPublicConfig) {
                    throw new PaymentException('Payment provider public config is invalid.');
                }
                $publicConfig = $this->validatedProviderPublicConfig($decoded);
            }
        }

        $secretConfig = [];
        if ($this->providerSettingsKey !== '' && is_array($setting)) {
            $secretConfig = (new PaymentProviderSettingsRepository($this->pdo, $this->providerSettingsKey))->secrets($providerId);
        }

        return (object) ($data + [
            'provider_id' => PaymentProviderRegistry::normalize($providerId),
            'provider_display_name' => $this->providerDisplayName($providerId, $setting),
            'provider_public_config' => $publicConfig,
            'provider_secret_config' => $secretConfig,
        ]);
    }

    /** @param array<mixed,mixed> $config */
    private function providerPublicConfigJson(array $config): string
    {
        try {
            return json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PaymentException('Payment provider public config is invalid.');
        }
    }

    /** @param array<string,mixed>|null $setting */
    private function providerDisplayName(string $providerId, ?array $setting): string
    {
        $stored = is_array($setting) ? (string) ($setting['display_name'] ?? '') : '';
        if ($this->displayNameSafe($stored)) {
            return $stored;
        }

        $provider = PaymentProviderRegistry::get($providerId);
        $fallback = $provider !== null ? $provider->displayName() : '';

        return $this->displayNameSafe($fallback) ? $fallback : '';
    }

    private function displayNameSafe(string $value): bool
    {
        return $value !== ''
            && $value === trim($value)
            && strlen($value) <= 191
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
            && !$this->providerPublicConfigValueContainsSecret($value);
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    private function validatedProviderPublicConfig(array $config): array
    {
        $safe = [];
        foreach ($config as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $key) !== 1) {
                throw new PaymentException('Payment provider public config is invalid.');
            }
            if (preg_match('/password|secret|token|authorization|signature|auth|api[_-]?key|access[_-]?key|private/i', $key) === 1) {
                throw new PaymentException('Payment provider public config is invalid.');
            }
            if (!(is_scalar($value) || $value === null)) {
                throw new PaymentException('Payment provider public config is invalid.');
            }
            if (($key === 'return_url_base' || str_contains(strtolower($key), 'url')) && $value !== null && !is_string($value)) {
                throw new PaymentException('Payment provider public config is invalid.');
            }
            if (is_string($value) && ($value !== trim($value) || strlen($value) > 4096 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1)) {
                throw new PaymentException('Payment provider public config is invalid.');
            }
            if (is_string($value) && $this->providerPublicConfigValueContainsSecret($value)) {
                throw new PaymentException('Payment provider public config is invalid.');
            }
            if ($key === 'return_url_base' && is_string($value) && !$this->isSafeReturnUrlBase($value)) {
                throw new PaymentException('Payment provider public config is invalid.');
            }
            if ($key !== 'return_url_base' && str_contains(strtolower($key), 'url') && is_string($value) && !$this->isSafeProviderConfigUrl($value)) {
                throw new PaymentException('Payment provider public config is invalid.');
            }
            $safe[$key] = $value;
        }

        return $safe;
    }

    private function providerPublicConfigValueContainsSecret(string $value): bool
    {
        $pattern = '/(?:bearer\s+|payment_token=|sk_[A-Za-z0-9_=-]+|api[_-]?key=|access[_-]?key=|secret=|signature=)/i';

        return preg_match($pattern, $value) === 1
            || preg_match($pattern, rawurldecode($value)) === 1;
    }

    private function isSafeProviderConfigUrl(string $url): bool
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
        if ($this->providerPublicConfigValueContainsSecret(rawurldecode((string) ($parts['path'] ?? '')))) {
            return false;
        }
        if (!isset($parts['query'])) {
            return true;
        }
        parse_str((string) $parts['query'], $query);
        foreach ($query as $key => $value) {
            if (preg_match('/token|secret|signature|authorization|auth|key|password|private/i', (string) $key) === 1) {
                return false;
            }
            if (!is_scalar($value)) {
                return false;
            }
            if ($this->providerPublicConfigValueContainsSecret(rawurldecode((string) $value))) {
                return false;
            }
        }

        return true;
    }

    private function isSafeReturnUrlBase(string $url): bool
    {
        if ($url === '') {
            return true;
        }
        if ($url !== trim($url) || strlen($url) > 2048) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && trim((string) ($parts['host'] ?? '')) !== ''
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['fragment'])
            && !isset($parts['query']);
    }

    /** @return array<string,mixed> */
    private function existingPayment(int $paymentId): array
    {
        if ($paymentId <= 0) {
            throw new PaymentException('Payment id is invalid.');
        }

        $payment = $this->payments->payment($paymentId);
        if ($payment === null) {
            throw new PaymentException('Payment was not found.');
        }

        return $payment;
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

    /** @param array<string,mixed> $payment */
    private function existingPaymentAmountMinor(array $payment): int
    {
        $value = $payment['amount_minor'] ?? null;
        if (is_int($value)) {
            $amountMinor = $value;
        } elseif (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,17})$/', $value) === 1) {
            $amountMinor = (int) $value;
        } else {
            throw new PaymentException('Payment ledger amount is invalid.');
        }

        if ($amountMinor <= 0) {
            throw new PaymentException('Payment ledger amount is invalid.');
        }

        return $amountMinor;
    }

    /** @param array<string,mixed> $payment */
    private function existingPaymentCurrency(array $payment): string
    {
        $currency = $payment['currency'] ?? null;
        if (!is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new PaymentException('Payment ledger currency is invalid.');
        }

        return $currency;
    }

    /** @param array<string,mixed> $payment */
    private function existingPaymentProviderId(array $payment): string
    {
        $providerId = $payment['provider_id'] ?? null;
        if (!is_string($providerId)) {
            throw new PaymentException('Payment ledger provider id is invalid.');
        }

        return $this->normalizeProviderId($providerId);
    }

    /** @param array<string,mixed> $payment */
    private function existingPaymentRemoteId(array $payment): string
    {
        $remoteId = $payment['remote_id'] ?? null;
        if (!is_string($remoteId)) {
            throw new PaymentException('Payment ledger remote reference is invalid.');
        }

        return $this->normalizeRemoteReference($remoteId, 'payment');
    }

    /** @param array<string,mixed> $payment */
    private function existingPaymentSubjectType(array $payment): string
    {
        $subjectType = $payment['subject_type'] ?? null;
        if (!is_string($subjectType)) {
            throw new PaymentException('Payment ledger subject type is invalid.');
        }

        return $this->normalizeSubjectType($subjectType);
    }

    /** @param array<string,mixed> $payment */
    private function existingPaymentSubjectId(array $payment): string
    {
        $subjectId = $payment['subject_id'] ?? null;
        if (!is_string($subjectId)) {
            throw new PaymentException('Payment ledger subject id is invalid.');
        }

        return $this->normalizeSubjectId($subjectId);
    }

    /** @param array<string,mixed> $payment */
    private function existingPaymentStatus(array $payment): string
    {
        $status = $payment['status'] ?? null;
        if (!is_string($status)) {
            throw new PaymentException('Payment ledger status is invalid.');
        }

        return $this->normalizePaymentStatus($status);
    }

    /** @param array<string,mixed> $payment @param list<string> $allowedStatuses */
    private function updatePaymentFromProviderResult(array $payment, PaymentResult $result, array $allowedStatuses = []): void
    {
        $this->assertProviderResultEnvelope($result);
        $status = $this->normalizePaymentStatus($this->providerResultString($result, 'status') ?? '');
        if ($allowedStatuses !== [] && !in_array($status, $allowedStatuses, true)) {
            throw new PaymentException('Payment provider returned an unexpected status.');
        }
        $this->assertPaymentStatusTransition($this->existingPaymentStatus($payment), $status);

        $remoteId = $this->providerResultString($result, 'provider_payment_id') ?? $result->requestId;
        if ($remoteId !== '') {
            $remoteId = $this->normalizeRemoteReference($remoteId, 'payment');
            $existing = $this->payments->paymentByRemote($this->existingPaymentProviderId($payment), $remoteId);
            $existingPaymentId = is_array($existing) ? $this->storedPositiveInt($existing['id'] ?? null) : null;
            $paymentId = $this->storedPositiveInt($payment['id'] ?? null);
            if ($paymentId === null || ($existing !== null && $existingPaymentId !== $paymentId)) {
                throw new PaymentException('Payment provider returned a duplicate remote payment reference.');
            }
        }

        $paymentId = $this->storedPositiveInt($payment['id'] ?? null);
        if ($paymentId === null) {
            throw new PaymentException('Payment id is invalid.');
        }
        $this->payments->updatePaymentStatus(
            $paymentId,
            $status,
            $remoteId,
            $result->requestId,
        );
        $this->payments->updatePaymentMetadata(
            $paymentId,
            $this->storedPaymentMetadata($payment) + $this->safeMetadata($result->data + ['provider_code' => $result->code]),
        );
    }

    /** @param array<string,mixed> $payment */
    private function mergePaymentMetadata(array $payment, PaymentResult $result): void
    {
        $paymentId = $this->storedPositiveInt($payment['id'] ?? null);
        if ($paymentId === null) {
            throw new PaymentException('Payment id is invalid.');
        }
        $metadata = array_merge(
            $this->storedPaymentMetadata($payment),
            $result->data,
            ['provider_code' => $result->code],
        );
        $this->payments->updatePaymentMetadata($paymentId, $this->safeMetadata($metadata));
    }

    private function providerResultString(PaymentResult $result, string $key): ?string
    {
        if (!array_key_exists($key, $result->data) || $result->data[$key] === null) {
            return null;
        }
        if (!is_string($result->data[$key])) {
            throw new PaymentException('Payment provider returned invalid result data.');
        }

        return $result->data[$key];
    }

    private function assertProviderResultEnvelope(PaymentResult $result): void
    {
        if (
            $result->code === ''
            || $result->code !== trim($result->code)
            || $result->code !== strtolower($result->code)
            || strlen($result->code) > 96
            || preg_match('/^[a-z0-9][a-z0-9._-]*$/', $result->code) !== 1
            || $this->metadataValueContainsSecret($result->code)
        ) {
            throw new PaymentException('Payment provider returned an invalid result code.');
        }
        if (
            $result->message === ''
            || $result->message !== trim($result->message)
            || strlen($result->message) > 500
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $result->message) === 1
            || $this->metadataValueContainsSecret($result->message)
        ) {
            throw new PaymentException('Payment provider returned an invalid result message.');
        }
        if ($result->requestId !== '') {
            $this->normalizeRemoteReference($result->requestId, 'request');
        }
    }

    /** @param array<string,mixed> $payload @return array{remote_id:string,status:string}|null */
    private function webhookPaymentUpdate(array $payload): ?array
    {
        $payment = is_array($payload['payment'] ?? null) ? $payload['payment'] : [];
        $remoteId = $this->webhookPayloadString(
            $payload['provider_payment_id'] ?? null,
            $payload['remote_id'] ?? null,
            $payment['provider_payment_id'] ?? null,
            $payment['remote_id'] ?? null,
        ) ?? '';
        $isRefundEvent = is_array($payload['refund'] ?? null) || str_contains($this->webhookEventType($payload), 'refund');
        $status = $this->webhookPayloadString(
            $payload['payment_status'] ?? null,
            $payment['status'] ?? null,
            $isRefundEvent ? null : ($payload['status'] ?? null),
        ) ?? '';
        if ($isRefundEvent && $status === '') {
            return null;
        }
        if ($remoteId === '' && $status === '') {
            return null;
        }
        if ($remoteId === '' || $status === '') {
            throw new PaymentException('Payment webhook status payload is incomplete.');
        }
        if (!in_array($status, ['pending', 'authorized', 'paid', 'partially_refunded', 'refunded', 'failed', 'cancelled'], true)) {
            throw new PaymentException('Payment webhook status is invalid.');
        }
        $remoteId = $this->normalizeRemoteReference($remoteId, 'payment');
        return ['remote_id' => $remoteId, 'status' => $status];
    }

    /** @param array<string,mixed> $payload @return array{remote_id:string,refund_remote_id:string,status:string,amount_minor:int,reason:string}|null */
    private function webhookRefundUpdate(array $payload): ?array
    {
        $refund = is_array($payload['refund'] ?? null) ? $payload['refund'] : [];
        $eventType = $this->webhookEventType($payload);
        $hasRefund = $refund !== [] || str_contains($eventType, 'refund');
        if (!$hasRefund) {
            return null;
        }

        $payment = is_array($payload['payment'] ?? null) ? $payload['payment'] : [];
        $remoteId = $this->webhookPayloadString(
            $payload['provider_payment_id'] ?? null,
            $payload['remote_id'] ?? null,
            $payment['provider_payment_id'] ?? null,
            $payment['remote_id'] ?? null,
            $refund['provider_payment_id'] ?? null,
            $refund['payment_remote_id'] ?? null,
        ) ?? '';
        $refundRemoteId = $this->webhookPayloadString(
            $payload['provider_refund_id'] ?? null,
            $refund['provider_refund_id'] ?? null,
            $refund['remote_id'] ?? null,
            $refund['id'] ?? null,
        ) ?? '';
        $status = $this->webhookPayloadString(
            $payload['refund_status'] ?? null,
            $refund['status'] ?? null,
            $payload['status'] ?? null,
        ) ?? '';
        $amountMinor = $this->normalizeWebhookAmountMinor($payload['refund_amount_minor'] ?? $refund['amount_minor'] ?? $refund['amount'] ?? null);
        $reason = $this->normalizeWebhookRefundReason($payload['refund_reason'] ?? $refund['reason'] ?? null);

        if ($remoteId === '' || $refundRemoteId === '' || $status === '') {
            throw new PaymentException('Payment webhook refund payload is incomplete.');
        }
        $remoteId = $this->normalizeRemoteReference($remoteId, 'payment');
        $refundRemoteId = $this->normalizeRemoteReference($refundRemoteId, 'refund');
        if (!in_array($status, ['completed', 'failed', 'cancelled'], true)) {
            throw new PaymentException('Payment webhook refund status is invalid.');
        }

        return [
            'remote_id' => $remoteId,
            'refund_remote_id' => $refundRemoteId,
            'status' => $status,
            'amount_minor' => $amountMinor,
            'reason' => $reason,
        ];
    }

    /** @param array<string,mixed> $payload */
    private function webhookEventType(array $payload): string
    {
        $raw = $payload['event_type'] ?? $payload['type'] ?? null;
        if ($raw === null) {
            return '';
        }
        if (!is_string($raw)) {
            throw new PaymentException('Payment webhook event type is invalid.');
        }

        $eventType = $raw;
        if (
            $eventType === ''
            || $eventType !== trim($eventType)
            || $eventType !== strtolower($eventType)
            || strlen($eventType) > 191
            || preg_match('/^[a-z0-9._:-]+$/', $eventType) !== 1
        ) {
            throw new PaymentException('Payment webhook event type is invalid.');
        }

        return $eventType;
    }

    private function webhookPayloadString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }
            if (!is_string($value)) {
                throw new PaymentException('Payment webhook payload field is invalid.');
            }

            return $value;
        }

        return null;
    }

    /** @param array<string,mixed> $payment @param array<string,mixed> $receipt @param array{remote_id:string,refund_remote_id:string,status:string,amount_minor:int,reason:string} $refund */
    private function recordWebhookRefund(array $payment, array $receipt, array $refund): void
    {
        $paymentId = $this->storedPositiveInt($payment['id'] ?? null);
        if ($paymentId === null) {
            throw new PaymentException('Payment webhook refund target payment id is invalid.');
        }
        $receiptId = $this->storedPositiveInt($receipt['id'] ?? null);
        if ($receiptId === null) {
            throw new PaymentException('Payment webhook refund receipt id is invalid.');
        }
        $status = $this->normalizeRefundStatus($refund['status']);
        $idempotencyKey = 'webhook-refund-' . substr(hash('sha256', (string) ($receipt['provider_id'] ?? '') . '|' . (string) ($receipt['external_event_id'] ?? '')), 0, 48);
        $requestHash = $this->requestHash([
            'payment_id' => $paymentId,
            'amount_minor' => $refund['amount_minor'],
            'reason' => $refund['reason'],
            'remote_id' => $refund['refund_remote_id'],
            'status' => $status,
        ]);

        $existing = $this->payments->refundByIdempotency($idempotencyKey);
        if ($existing !== null) {
            if ((string) ($existing['request_hash'] ?? '') !== $requestHash) {
                throw new PaymentException('Payment webhook refund event was reused with different content.');
            }
            return;
        }
        $existingRemote = $this->payments->refundByRemote((string) ($payment['provider_id'] ?? ''), $refund['refund_remote_id']);
        if ($existingRemote !== null) {
            throw new PaymentException('Payment webhook refund returned a duplicate remote refund reference.');
        }

        if (!in_array((string) ($payment['status'] ?? ''), ['paid', 'partially_refunded', 'refunded'], true)) {
            throw new PaymentException('Payment webhook refund target is not refundable.');
        }
        $sourceAmountMinor = $this->existingPaymentAmountMinor($payment);
        $currency = $this->existingPaymentCurrency($payment);
        if ($status === 'completed') {
            $completed = $this->payments->refundedMinorForPayment($paymentId);
            if ($completed + $refund['amount_minor'] > $sourceAmountMinor) {
                throw new PaymentException('Payment webhook refund exceeds captured payment.');
            }
        }

        $refundId = $this->payments->insertRefund([
            'payment_id' => $paymentId,
            'provider_id' => (string) ($payment['provider_id'] ?? ''),
            'remote_id' => $refund['refund_remote_id'],
            'status' => $status,
            'amount_minor' => $refund['amount_minor'],
            'currency' => $currency,
            'reason' => $refund['reason'],
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $requestHash,
            'metadata' => $this->safeMetadata([
                'provider_code' => 'webhook_refund',
                'webhook_receipt_id' => $receiptId,
                'provider_refund_id' => $refund['refund_remote_id'],
            ]),
        ]);

        if ($refundId > 0 && $status === 'completed') {
            $refunded = $this->payments->refundedMinorForPayment($paymentId);
            $nextStatus = $refunded >= $sourceAmountMinor ? 'refunded' : 'partially_refunded';
            $accessCounts = $nextStatus === 'refunded' ? $this->activeAccessCountsForPayment($paymentId) : ['authorization_count' => 0, 'entitlement_count' => 0];
            $this->payments->updatePaymentStatus($paymentId, $nextStatus);
            if ($nextStatus === 'refunded') {
                $this->recordAccessRevokedAfterRefund($paymentId, 'webhook_refund', $accessCounts);
            }
        }
    }

    /** @return array{authorization_count:int,entitlement_count:int} */
    private function activeAccessCountsForPayment(int $paymentId): array
    {
        $authorizationCount = 0;
        foreach ($this->payments->authorizationsForPayment($paymentId) as $authorization) {
            if ((string) ($authorization['status'] ?? '') === 'active') {
                $authorizationCount++;
            }
        }

        $entitlementCount = 0;
        foreach ($this->payments->entitlementsForPayment($paymentId) as $entitlement) {
            if ((string) ($entitlement['status'] ?? '') === 'active') {
                $entitlementCount++;
            }
        }

        return ['authorization_count' => $authorizationCount, 'entitlement_count' => $entitlementCount];
    }

    /** @param array{authorization_count:int,entitlement_count:int} $accessCounts */
    private function recordAccessRevokedAfterRefund(int $paymentId, string $reason, array $accessCounts): void
    {
        if ($accessCounts['authorization_count'] <= 0 && $accessCounts['entitlement_count'] <= 0) {
            return;
        }

        $this->recordAudit('payment.access.revoked_after_refund', [
            'payment_id' => $paymentId,
            'reason' => $reason,
            'authorization_count' => $accessCounts['authorization_count'],
            'entitlement_count' => $accessCounts['entitlement_count'],
        ]);
    }

    /** @param array<string,mixed>|null $refundUpdate */
    private function isStaleRefundWebhookPaymentStatus(array $payment, ?array $refundUpdate, string $paymentStatus): bool
    {
        if (!is_array($refundUpdate) || (string) ($refundUpdate['status'] ?? '') !== 'completed') {
            return false;
        }
        if (!in_array((string) ($payment['status'] ?? ''), ['partially_refunded', 'refunded'], true)) {
            return false;
        }

        return in_array($this->normalizePaymentStatus($paymentStatus), ['pending', 'authorized', 'paid'], true);
    }

    private function beginImmediate(): void
    {
        if ($this->pdo->inTransaction()) {
            return;
        }
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->exec('BEGIN IMMEDIATE');
            return;
        }
        $this->pdo->beginTransaction();
    }

    private function normalizeSubjectType(string $subjectType): string
    {
        $normalized = strtolower(trim($subjectType));
        if ($subjectType !== $normalized) {
            throw new PaymentException('Payment subject type is invalid.');
        }
        $subjectType = $normalized;
        if (preg_match('/^[a-z0-9][a-z0-9._-]{1,95}[a-z0-9]$/', $subjectType) !== 1) {
            throw new PaymentException('Payment subject type is invalid.');
        }

        return $subjectType;
    }

    private function normalizeSubjectId(string $subjectId): string
    {
        if (
            $subjectId === ''
            || $subjectId !== trim($subjectId)
            || strlen($subjectId) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $subjectId) === 1
            || $this->metadataValueContainsSecret($subjectId)
        ) {
            throw new PaymentException('Payment subject id is invalid.');
        }

        return $subjectId;
    }

    private function normalizeProviderId(string $providerId): string
    {
        try {
            $normalized = PaymentProviderRegistry::normalize($providerId);
        } catch (Throwable) {
            throw new PaymentException('Payment provider id is invalid.');
        }
        if ($providerId !== $normalized) {
            throw new PaymentException('Payment provider id is invalid.');
        }

        return $normalized;
    }

    private function normalizeCurrency(string $currency): string
    {
        if ($currency !== trim($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new PaymentException('Payment currency is invalid.');
        }
        try {
            $code = CurrencyRegistry::normalizeCode($currency);
            CurrencyRegistry::require($code);
            return $code;
        } catch (\InvalidArgumentException) {
            throw new PaymentException('Payment currency is invalid.');
        }
    }

    private function normalizeCurrencyFilter(string $currency): string
    {
        if (trim($currency) === '') {
            return '';
        }

        return $this->normalizeCurrency($currency);
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

    private function normalizeIdempotencyKey(string $idempotencyKey): string
    {
        if (
            $idempotencyKey === ''
            || $idempotencyKey !== trim($idempotencyKey)
            || strlen($idempotencyKey) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $idempotencyKey) === 1
            || $this->metadataValueContainsSecret($idempotencyKey)
        ) {
            throw new PaymentException('Payment idempotency key is invalid.');
        }

        return $idempotencyKey;
    }

    private function normalizeOptionalIdempotencyKey(string $idempotencyKey): string
    {
        if ($idempotencyKey === '') {
            return '';
        }

        return $this->normalizeIdempotencyKey($idempotencyKey);
    }

    private function normalizeProviderScenario(string $scenario): string
    {
        if ($scenario === '') {
            return 'success';
        }
        if ($scenario !== trim($scenario) || strlen($scenario) > 64 || preg_match('/^[a-z0-9._-]+$/', $scenario) !== 1) {
            throw new PaymentException('Payment provider scenario is invalid.');
        }

        return $scenario;
    }

    private function normalizeRefundReason(string $reason): string
    {
        if ($reason !== '' && $reason !== trim($reason)) {
            throw new PaymentException('Refund reason is invalid.');
        }
        if (strlen($reason) > 500 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason) === 1) {
            throw new PaymentException('Refund reason is invalid.');
        }

        return $reason;
    }

    private function normalizeRemoteReference(string $reference, string $kind): string
    {
        if (
            $reference === ''
            || $reference !== trim($reference)
            || strlen($reference) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $reference) === 1
            || $this->metadataValueContainsSecret($reference)
        ) {
            throw new PaymentException('Payment provider remote ' . $kind . ' reference is invalid.');
        }

        return $reference;
    }

    private function normalizeWebhookAmountMinor(mixed $value): int
    {
        if (is_int($value)) {
            $amount = $value;
        } elseif (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/', $value) === 1) {
            $amount = (int) $value;
        } else {
            throw new PaymentException('Payment webhook refund amount is invalid.');
        }

        if ($amount <= 0) {
            throw new PaymentException('Payment webhook refund amount is invalid.');
        }

        return $amount;
    }

    private function normalizeWebhookRefundReason(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'provider webhook refund';
        }
        if (!is_string($value) || $value !== trim($value) || strlen($value) > 500 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new PaymentException('Payment webhook refund reason is invalid.');
        }

        return $value;
    }

    private function normalizePaymentStatus(string $status): string
    {
        if (!in_array($status, ['pending', 'authorized', 'paid', 'partially_refunded', 'refunded', 'failed', 'cancelled'], true)) {
            throw new PaymentException('Payment status is invalid.');
        }

        return $status;
    }

    private function normalizeRefundStatus(string $status): string
    {
        if (!in_array($status, ['pending', 'completed', 'failed', 'cancelled'], true)) {
            throw new PaymentException('Refund status is invalid.');
        }

        return $status;
    }

    private function assertPaymentStatusTransition(string $currentStatus, string $nextStatus): void
    {
        $currentStatus = $this->normalizePaymentStatus($currentStatus);
        $nextStatus = $this->normalizePaymentStatus($nextStatus);
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

    /** @param array<string,mixed> $payload */
    private function requestHash(array $payload): string
    {
        ksort($payload);

        try {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PaymentException('Payment request payload is invalid.');
        }

        return hash('sha256', $json);
    }

    /** @param array<string,mixed> $context */
    private function recordAudit(string $action, array $context): void
    {
        (new AuditLogger($this->pdo))->record('system', null, $action, $this->safeMetadata($context));
    }

    /** @param array<string,mixed> $payment @param array<string,mixed> $auditContext */
    private function persistRejectedProviderResult(array $payment, PaymentResult $result, string $action, array $auditContext): void
    {
        $alreadyInTransaction = $this->pdo->inTransaction();
        $this->beginImmediate();
        try {
            $this->updatePaymentFromProviderResult($payment, $result);
            $this->mergePaymentMetadata($payment, $result);
            $updated = $this->payments->payment((int) ($payment['id'] ?? 0)) ?? $payment;
            $this->recordAudit($action, $auditContext + [
                'persisted_status' => (string) ($updated['status'] ?? $payment['status'] ?? ''),
            ]);
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

    private function recordProviderCreateFailure(
        string $subjectType,
        string $subjectId,
        string $providerId,
        int $amountMinor,
        string $currency,
        string $idempotencyKey,
        Throwable $exception,
    ): void {
        $diagnostic = $exception instanceof PaymentProviderDiagnosticException ? $exception->diagnostic() : [];
        $attemptId = substr(hash('sha256', implode('|', [
            $providerId,
            $subjectType,
            $subjectId,
            $amountMinor,
            $currency,
            $idempotencyKey,
        ])), 0, 32);
        $safeMessage = $this->providerCreateFailureSafeMessage($exception, $diagnostic);

        try {
            (new PaymentAttemptDiagnosticsRepository($this->pdo))->record([
                'attempt_id' => $attemptId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'provider_id' => $providerId,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'stage' => (string) ($diagnostic['stage'] ?? 'provider.create'),
                'status' => 'failed',
                'http_status' => $diagnostic['http_status'] ?? null,
                'provider_error_type' => $diagnostic['provider_error_type'] ?? null,
                'provider_error_code' => $diagnostic['provider_error_code'] ?? null,
                'provider_request_id' => $diagnostic['provider_request_id'] ?? null,
                'safe_error_message' => $safeMessage,
                'metadata' => [
                    'exception_class' => $exception::class,
                    'idempotency_key_hash' => hash('sha256', $idempotencyKey),
                    'checkout_scheme' => $diagnostic['checkout_scheme'] ?? null,
                    'checkout_host' => $diagnostic['checkout_host'] ?? null,
                    'stripe_session_id' => $diagnostic['stripe_session_id'] ?? null,
                ],
            ]);
        } catch (Throwable) {
        }

        try {
            $this->recordAudit('payment.provider.create_failed', [
                'attempt_id' => $attemptId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'provider_id' => $providerId,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'stage' => (string) ($diagnostic['stage'] ?? 'provider.create'),
                'http_status' => $diagnostic['http_status'] ?? null,
                'provider_error_code' => $diagnostic['provider_error_code'] ?? null,
                'provider_request_id' => $diagnostic['provider_request_id'] ?? null,
            ]);
        } catch (Throwable) {
        }
    }

    /** @param array<string,mixed> $diagnostic */
    private function providerCreateFailureSafeMessage(Throwable $exception, array $diagnostic): string
    {
        if ($exception instanceof PaymentProviderDiagnosticException) {
            return $this->sanitizePaymentDiagnosticMessage((string) ($diagnostic['safe_error_message'] ?? $exception->getMessage()));
        }
        if ($exception instanceof PaymentException) {
            return $this->sanitizePaymentDiagnosticMessage($exception->getMessage());
        }

        return '支付 Provider 创建支付失败，失败发生在 Core Payment 持久化之前。';
    }

    private function sanitizePaymentDiagnosticMessage(string $message): string
    {
        $message = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message) ?? '');
        $message = preg_replace('/(sk|pk|whsec)_(test|live)?_[A-Za-z0-9_=-]{6,}/', '$1_$2_[redacted]', $message) ?? $message;
        $message = preg_replace('/Bearer\s+[A-Za-z0-9._=-]+/i', 'Bearer [redacted]', $message) ?? $message;
        $message = preg_replace('/Authorization:\s*[^\s]+/i', 'Authorization: [redacted]', $message) ?? $message;
        if ($message === '') {
            return '支付 Provider 创建支付失败，失败发生在 Core Payment 持久化之前。';
        }

        return function_exists('mb_substr') ? mb_substr($message, 0, 512) : substr($message, 0, 512);
    }

    /** @param array<string,mixed> $data */
    private function assertSafeProviderRedirectData(string $providerId, array $data): void
    {
        foreach (['checkout_url', 'payment_url', 'redirect_url'] as $key) {
            if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                continue;
            }
            if (!is_string($data[$key]) || !$this->isSafeProviderRedirectUrlForProvider($providerId, $data[$key])) {
                throw new PaymentException('Payment provider returned an unsafe checkout URL.');
            }
        }
    }

    /** @param array<string,mixed> $data */
    private function providerCheckoutUrlFromResultData(string $providerId, array $data): string
    {
        foreach (['checkout_url', 'payment_url', 'redirect_url'] as $key) {
            $url = $data[$key] ?? '';
            if (is_string($url) && $url !== '' && $this->isSafeProviderRedirectUrlForProvider($providerId, $url)) {
                return $url;
            }
        }

        return '';
    }

    private function isSafeProviderRedirectUrlForProvider(string $providerId, string $url): bool
    {
        if ($providerId === 'official.payment.stripe' && $this->isSafeStripeCheckoutRedirectUrl($url)) {
            return true;
        }

        return $this->isSafeProviderRedirectUrl($url);
    }

    private function isSafeStripeCheckoutRedirectUrl(string $url): bool
    {
        if ($url === '' || $url !== trim($url) || strlen($url) > 262144 || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'checkout.stripe.com'
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return false;
        }
        if (!str_starts_with((string) ($parts['path'] ?? ''), '/c/pay/')) {
            return false;
        }
        if (preg_match('#cs_(?:test|live)_[A-Za-z0-9_=-]+#', $url) !== 1) {
            return false;
        }
        if (!$this->isSafeStripeOwnedUrlPart((string) ($parts['query'] ?? ''), 65536)
            || !$this->isSafeStripeOwnedUrlPart((string) ($parts['fragment'] ?? ''), 196608)
        ) {
            return false;
        }

        return true;
    }

    private function isSafeStripeOwnedUrlPart(string $value, int $maxLength): bool
    {
        return strlen($value) <= $maxLength
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    private function providerRedirectPartContainsSecret(string $value): bool
    {
        return preg_match('/(?:bearer\s+|sk_(test|live)?_|api[_-]?key=|access[_-]?key=|secret=|authorization=)/i', rawurldecode($value)) === 1;
    }

    private function isSafeProviderRedirectUrl(string $url): bool
    {
        if ($url === '' || $url !== trim($url) || strlen($url) > 2048) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            return false;
        }
        if ($this->providerPublicConfigValueContainsSecret(rawurldecode((string) ($parts['path'] ?? '')))) {
            return false;
        }
        if (!isset($parts['query'])) {
            return true;
        }
        parse_str((string) $parts['query'], $query);
        foreach ($query as $key => $value) {
            $keyName = (string) $key;
            if ($keyName !== 'cms_signature'
                && !$this->isAllowedGatewayTokenQuery($parts, $keyName, $value)
                && preg_match('/token|secret|signature|authorization|auth|key|password|private/i', $keyName) === 1
            ) {
                return false;
            }
            if (!is_scalar($value)) {
                return false;
            }
            if ((string) $key === 'cms_signature' && (!is_string($value) || preg_match('/^[a-f0-9]{64}$/', $value) !== 1)) {
                return false;
            }
            if ($this->providerPublicConfigValueContainsSecret(rawurldecode((string) $value))) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $parts */
    private function isAllowedGatewayTokenQuery(array $parts, string $key, mixed $value): bool
    {
        if ($key !== 'token' || !is_scalar($value)) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($host, ['www.paypal.com', 'www.sandbox.paypal.com', 'www.paypal.test'], true)) {
            return false;
        }

        return is_string($value) && preg_match('/^[A-Za-z0-9._-]{1,191}$/', $value) === 1;
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    private function webhookReceiptMetadata(array $metadata, int $payloadSize): array
    {
        unset($metadata['payload_size']);
        if (array_key_exists('content_type', $metadata)) {
            $contentType = $metadata['content_type'];
            if ($contentType === '') {
                unset($metadata['content_type']);
            } elseif (!is_string($contentType) || !$this->webhookMetadataContentTypeSafe($contentType)) {
                throw new PaymentException('Payment webhook content type trace metadata is invalid.');
            }
        }
        if (array_key_exists('webhook_timestamp', $metadata)) {
            $timestamp = $metadata['webhook_timestamp'];
            if (!is_string($timestamp) || !$this->webhookMetadataTimestampSafe($timestamp)) {
                throw new PaymentException('Payment webhook timestamp trace metadata is invalid.');
            }
        }
        if (array_key_exists('source_ip_hash', $metadata)) {
            $sourceHash = $metadata['source_ip_hash'];
            if (!is_string($sourceHash) || !$this->webhookMetadataSourceHashSafe($sourceHash)) {
                throw new PaymentException('Payment webhook source trace metadata is invalid.');
            }
        }

        $metadata['payload_size'] = $payloadSize;
        return $metadata;
    }

    private function webhookMetadataContentTypeSafe(string $value): bool
    {
        return $value !== ''
            && $value === trim($value)
            && strlen($value) <= 120
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
            && !$this->metadataValueContainsSecret($value);
    }

    private function webhookMetadataTimestampSafe(string $value): bool
    {
        return $value !== ''
            && $value === trim($value)
            && strlen($value) <= 32
            && preg_match('/^[1-9][0-9]{0,11}$/', $value) === 1;
    }

    private function webhookMetadataSourceHashSafe(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $value) === 1;
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

    /** @param array<string,mixed> $payment @return array<string,mixed> */
    private function storedPaymentMetadata(array $payment): array
    {
        $raw = (string) ($payment['metadata_json'] ?? '{}');
        if ($raw === '') {
            return [];
        }
        if ($raw !== trim($raw)) {
            throw new PaymentException('Payment metadata is invalid.');
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PaymentException('Payment metadata is invalid.');
        }
        if (!is_array($decoded)) {
            throw new PaymentException('Payment metadata is invalid.');
        }

        return $this->safeMetadata($decoded);
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
