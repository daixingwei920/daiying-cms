<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

use Cms\Core\Payment\PaymentEntitlementService;
use Cms\Core\Payment\PaymentRepository;
use Cms\Core\Payment\PaymentService;
use Cms\Core\Payment\PaymentTransaction;
use Cms\Core\Support\CurrencyRegistry;
use PDO;
use Throwable;

final class PluginPaymentService
{
    /** @var array<string,callable(string,array<string,mixed>):array<string,mixed>> */
    private array $resolvers = [];

    public function __construct(
        private readonly PluginManifest $manifest,
        private readonly PDO $pdo,
        private readonly string $providerSettingsKey = '',
    ) {
    }

    /** @param callable(string,array<string,mixed>):array<string,mixed> $serverResolver */
    public function registerSubject(string $type, callable $serverResolver): void
    {
        $this->access('payment.subject.register');
        $type = $this->type($type);
        if (isset($this->resolvers[$type])) {
            throw new PluginException('invalid_request');
        }
        $this->resolvers[$type] = $serverResolver;
    }

    /** @return list<array{id:string,label:string}> */
    public function enabledProviders(string $currency): array
    {
        $this->access('payment.checkout');
        $this->currency($currency);
        try {
            return $this->payments()->enabledProviders($currency);
        } catch (Throwable $exception) {
            throw new PluginException('service_unavailable', 0, $exception);
        }
    }

    /** @return array<string,mixed> */
    public function create(string $type, string $localSubjectId, string $providerId, string $idempotencyKey): array
    {
        $this->access('payment.checkout');
        $buyerId = $this->buyer(true);
        $type = $this->type($type);
        $localSubjectId = $this->safeId($localSubjectId, 191);
        $idempotencyKey = $this->safeId($idempotencyKey, 191);
        $providerId = $this->type($providerId);
        $quote = $this->resolve($type, $localSubjectId, 'create', $buyerId);
        $quoteVersion = $this->safeId($quote['quote_version'] ?? null, 96);
        $amount = $quote['amount_minor'] ?? null;
        $duration = $quote['duration_seconds'] ?? null;
        if (!is_int($amount) || $amount <= 0 || !is_int($duration) || $duration <= 0 || $duration > 315360000) {
            throw new PluginException('invalid_request');
        }
        $currency = $this->currency($quote['currency'] ?? null);
        $beneficiary = $quote['beneficiary'] ?? null;
        if (!is_array($beneficiary)) {
            throw new PluginException('invalid_request');
        }
        $beneficiaryType = $this->type($beneficiary['type'] ?? null);
        $beneficiaryId = $this->safeId($beneficiary['id'] ?? null, 191);
        if (!in_array($providerId, array_column($this->enabledProviders($currency), 'id'), true)) {
            throw new PluginException('invalid_request');
        }

        $pluginId = $this->manifest->id;
        $subjectHash = hash('sha256', $pluginId . "\0" . $type . "\0" . $localSubjectId);
        $keyHash = hash('sha256', $idempotencyKey);
        $internalKey = 'plugin-' . hash('sha256', $pluginId . "\0" . $idempotencyKey);
        $requestHash = hash('sha256', json_encode([
            $pluginId, $type, $localSubjectId, $buyerId, $providerId,
            $quoteVersion, $amount, $currency, $beneficiaryType, $beneficiaryId, $duration,
        ], JSON_THROW_ON_ERROR));

        try {
            PaymentTransaction::beginImmediate($this->pdo);
            $this->access('payment.checkout', true);
            $existing = $this->bindingByOrder($type, $localSubjectId, true);
            $sameKey = $this->bindingByKey($keyHash, true);
            if ($existing !== null || $sameKey !== null) {
                $binding = $existing ?? $sameKey;
                if ($existing !== null && $sameKey !== null && (int) $existing['id'] !== (int) $sameKey['id']) {
                    throw new PluginException('idempotency_conflict');
                }
                if (!hash_equals((string) $binding['request_hash'], $requestHash) || (string) $binding['external_key_hash'] !== $keyHash) {
                    throw new PluginException('idempotency_conflict');
                }
                PaymentTransaction::commit($this->pdo);
                return $this->recoverOrReturn($binding);
            }
            $now = gmdate('c');
            $ref = 'pp_' . substr(hash('sha256', $pluginId . "\0" . $type . "\0" . $localSubjectId), 0, 48);
            $this->pdo->prepare('INSERT INTO cms_plugin_payment_bindings
                (plugin_id, subject_type, local_subject_id, core_subject_id, buyer_id, provider_id,
                 external_key_hash, internal_key, quote_version, amount_minor, currency,
                 beneficiary_type, beneficiary_id, duration_seconds, request_hash, payment_ref,
                 state, created_at, updated_at)
                 VALUES (:plugin, :type, :local, :core, :buyer, :provider, :external_key, :internal_key,
                         :quote_version, :amount, :currency, :beneficiary_type, :beneficiary_id,
                         :duration, :request_hash, :payment_ref, :state, :created, :updated)')
                ->execute([
                    ':plugin' => $pluginId, ':type' => $type, ':local' => $localSubjectId,
                    ':core' => $subjectHash, ':buyer' => $buyerId, ':provider' => $providerId,
                    ':external_key' => $keyHash, ':internal_key' => $internalKey,
                    ':quote_version' => $quoteVersion, ':amount' => $amount, ':currency' => $currency,
                    ':beneficiary_type' => $beneficiaryType, ':beneficiary_id' => $beneficiaryId,
                    ':duration' => $duration, ':request_hash' => $requestHash, ':payment_ref' => $ref,
                    ':state' => 'reserved', ':created' => $now, ':updated' => $now,
                ]);
            PaymentTransaction::commit($this->pdo);
            $binding = $this->bindingByOrder($type, $localSubjectId);
            if ($binding === null) {
                throw new PluginException('service_unavailable');
            }
            return $this->startPayment($binding);
        } catch (PluginException $exception) {
            $this->rollback();
            throw $exception;
        } catch (Throwable $exception) {
            $this->rollback();
            throw new PluginException('service_unavailable', 0, $exception);
        }
    }

    /** @return array{payment_ref:string,status:string,amount_minor:int,currency:string} */
    public function status(string $paymentRef): array
    {
        $this->access('payment.checkout');
        $buyer = $this->buyer(false);
        try {
            $binding = $this->bindingByRef($this->safeId($paymentRef, 64));
            if ($binding === null || (int) $binding['buyer_id'] !== $buyer) {
                throw new PluginException('not_found');
            }
            $this->resolve((string) $binding['subject_type'], (string) $binding['local_subject_id'], 'status', $buyer);
            $payment = $this->boundPayment($binding);
            return $this->publicPayment($binding, $payment);
        } catch (PluginException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PluginException('service_unavailable', 0, $exception);
        }
    }

    /** @return array{entitlement_ref:string,status:string,expires_at:string} */
    public function fulfill(string $paymentRef): array
    {
        $this->access('payment.checkout');
        $buyer = $this->buyer(true);
        try {
            PaymentTransaction::beginImmediate($this->pdo);
            $this->access('payment.checkout', true);
            $binding = $this->bindingByRef($this->safeId($paymentRef, 64), true);
            if ($binding === null || (int) $binding['buyer_id'] !== $buyer) {
                throw new PluginException('not_found');
            }
            $this->resolve((string) $binding['subject_type'], (string) $binding['local_subject_id'], 'fulfill', $buyer);
            $payment = $this->boundPayment($binding, true);
            $repo = new PaymentRepository($this->pdo);
            $entitlementId = (int) ($binding['entitlement_id'] ?? 0);
            if ($entitlementId > 0) {
                $entitlement = $repo->entitlement($entitlementId);
                if ($entitlement === null) {
                    throw new PluginException('service_unavailable');
                }
                $result = $this->publicEntitlement($binding, $payment, $entitlement, $repo);
                PaymentTransaction::commit($this->pdo);
                return $result;
            }
            $paidAt = (string) ($binding['trusted_paid_at'] ?? '');
            $expires = $paidAt !== '' ? strtotime($paidAt) + (int) $binding['duration_seconds'] : false;
            if ((string) $payment['status'] !== 'paid'
                || (int) $payment['amount_minor'] !== (int) $binding['amount_minor']
                || (string) $payment['currency'] !== (string) $binding['currency']
                || $repo->refundedMinorForPayment((int) $payment['id']) !== 0
                || $expires === false || $expires <= time()) {
                throw new PluginException('payment_not_fulfillable');
            }
            $expiresAt = gmdate('Y-m-d\TH:i:s+00:00', $expires);
            $entitlement = (new PaymentEntitlementService($this->pdo, $repo))->grantFromPayment(
                (int) $payment['id'], (string) $binding['beneficiary_type'],
                (string) $binding['beneficiary_id'], 0, $expiresAt,
            );
            $id = (int) ($entitlement['id'] ?? 0);
            if ($id <= 0) {
                throw new PluginException('service_unavailable');
            }
            $this->pdo->prepare('UPDATE cms_plugin_payment_bindings SET entitlement_id = :entitlement, expires_at = :expires, updated_at = :updated WHERE id = :id AND entitlement_id IS NULL')
                ->execute([':entitlement' => $id, ':expires' => $expiresAt, ':updated' => gmdate('c'), ':id' => $binding['id']]);
            PaymentTransaction::commit($this->pdo);
            return ['entitlement_ref' => 'pe_' . $id, 'status' => 'active', 'expires_at' => $expiresAt];
        } catch (PluginException $exception) {
            $this->rollback();
            throw $exception;
        } catch (Throwable $exception) {
            $this->rollback();
            throw new PluginException('service_unavailable', 0, $exception);
        }
    }

    public function isEntitled(string $type, string $localSubjectId): bool
    {
        $this->access('payment.entitlement.read');
        $type = $this->type($type);
        $localSubjectId = $this->safeId($localSubjectId, 191);
        try {
            $binding = $this->bindingByOrder($type, $localSubjectId);
            if ($binding === null) {
                return false;
            }
            $this->resolve($type, $localSubjectId, 'entitlement_read', null);
            $id = (int) ($binding['entitlement_id'] ?? 0);
            if ($id <= 0) {
                return false;
            }
            $repo = new PaymentRepository($this->pdo);
            $payment = $this->boundPayment($binding);
            $entitlement = $repo->entitlement($id);
            if ($entitlement === null) {
                throw new PluginException('service_unavailable');
            }
            return $this->publicEntitlement($binding, $payment, $entitlement, $repo)['status'] === 'active';
        } catch (PluginException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PluginException('service_unavailable', 0, $exception);
        }
    }

    private function access(string $capability, bool $lock = false): void
    {
        if (!in_array($capability, $this->manifest->capabilities, true)
            || !in_array('payment.access', $this->manifest->permissions, true)) {
            throw new PluginException('capability_denied');
        }
        try {
            (new PluginPermissionDecisionService($this->pdo))->assertEffective($this->manifest, $lock);
        } catch (PluginException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PluginException('service_unavailable', 0, $exception);
        }
    }

    private function buyer(bool $mutating): int
    {
        $scope = PluginPaymentRequestScope::current();
        if ($scope === null || $scope['plugin_id'] !== $this->manifest->id || $scope['actor_id'] === null
            || ($mutating && ($scope['method'] !== 'POST' || !$scope['csrf']))) {
            throw new PluginException('capability_denied');
        }
        try {
            $stmt = $this->pdo->prepare("SELECT id FROM cms_front_users WHERE id = :id AND status = 'active' LIMIT 1");
            $stmt->execute([':id' => $scope['actor_id']]);
            if (!$stmt->fetchColumn()) {
                throw new PluginException('capability_denied');
            }
            return $scope['actor_id'];
        } catch (PluginException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PluginException('service_unavailable', 0, $exception);
        }
    }

    /** @return array<string,mixed> */
    private function resolve(string $type, string $localId, string $operation, ?int $actorId): array
    {
        $resolver = $this->resolvers[$type] ?? null;
        if ($resolver === null) {
            throw new PluginException('invalid_request');
        }
        try {
            $result = $resolver($localId, ['plugin_id' => $this->manifest->id, 'actor_id' => $actorId, 'operation' => $operation]);
        } catch (Throwable $exception) {
            throw new PluginException('service_unavailable', 0, $exception);
        }
        if (!is_array($result) || ($result['authorized'] ?? false) !== true) {
            throw new PluginException($operation === 'create' ? 'capability_denied' : 'not_found');
        }
        return $result;
    }

    private function type(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-z0-9][a-z0-9._-]{1,94}[a-z0-9]$/', $value) !== 1) {
            throw new PluginException('invalid_request');
        }
        return $value;
    }

    private function safeId(mixed $value, int $max): string
    {
        if (!is_string($value) || $value === '' || $value !== trim($value) || strlen($value) > $max
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new PluginException('invalid_request');
        }
        return $value;
    }

    private function currency(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Z]{3}$/', $value) !== 1) {
            throw new PluginException('invalid_request');
        }
        try {
            CurrencyRegistry::require($value);
        } catch (Throwable $exception) {
            throw new PluginException('invalid_request', 0, $exception);
        }
        return $value;
    }

    /** @return array<string,mixed>|null */
    private function bindingByOrder(string $type, string $localId, bool $lock = false): ?array
    {
        return $this->selectBinding('plugin_id = :plugin AND subject_type = :type AND local_subject_id = :local',
            [':plugin' => $this->manifest->id, ':type' => $type, ':local' => $localId], $lock);
    }

    /** @return array<string,mixed>|null */
    private function bindingByKey(string $keyHash, bool $lock = false): ?array
    {
        return $this->selectBinding('plugin_id = :plugin AND external_key_hash = :key', [':plugin' => $this->manifest->id, ':key' => $keyHash], $lock);
    }

    /** @return array<string,mixed>|null */
    private function bindingByRef(string $ref, bool $lock = false): ?array
    {
        return $this->selectBinding('plugin_id = :plugin AND payment_ref = :ref', [':plugin' => $this->manifest->id, ':ref' => $ref], $lock);
    }

    /** @param array<string,mixed> $args @return array<string,mixed>|null */
    private function selectBinding(string $where, array $args, bool $lock): ?array
    {
        $sql = 'SELECT * FROM cms_plugin_payment_bindings WHERE ' . $where . ' LIMIT 1';
        if ($lock && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $binding @return array<string,mixed> */
    private function recoverOrReturn(array $binding): array
    {
        if ((int) ($binding['payment_id'] ?? 0) > 0) {
            return $this->publicPayment($binding, $this->boundPayment($binding));
        }
        $payment = (new PaymentRepository($this->pdo))->paymentByIdempotency((string) $binding['internal_key']);
        if ($payment !== null) {
            $this->linkPayment($binding, $payment);
            return $this->publicPayment($binding, $payment);
        }
        if ((string) $binding['state'] !== 'reserved') {
            throw new PluginException('payment_reconciliation_required');
        }
        return $this->startPayment($binding);
    }

    /** @param array<string,mixed> $binding @return array<string,mixed> */
    private function startPayment(array $binding): array
    {
        try {
            PaymentTransaction::beginImmediate($this->pdo);
            $this->access('payment.checkout', true);
            $claim = $this->pdo->prepare("UPDATE cms_plugin_payment_bindings SET state = 'creating', updated_at = :now WHERE id = :id AND state = 'reserved'");
            $claim->execute([':now' => gmdate('c'), ':id' => $binding['id']]);
            if ($claim->rowCount() !== 1) {
                throw new PluginException('payment_reconciliation_required');
            }
            PaymentTransaction::commit($this->pdo);
        } catch (Throwable $exception) {
            if (PaymentTransaction::isActive($this->pdo)) {
                PaymentTransaction::rollback($this->pdo);
            }
            throw $exception;
        }
        try {
            $payment = $this->payments()->createProviderPayment(
                'cms_plugin_order', (string) $binding['core_subject_id'], (string) $binding['provider_id'],
                (int) $binding['amount_minor'], (string) $binding['currency'], (string) $binding['internal_key'],
            );
            $this->linkPayment($binding, $payment);
            return $this->publicPayment($binding, $payment);
        } catch (Throwable $exception) {
            $this->pdo->prepare("UPDATE cms_plugin_payment_bindings SET state = 'reconciliation_required', updated_at = :now WHERE id = :id AND state = 'creating'")
                ->execute([':now' => gmdate('c'), ':id' => $binding['id']]);
            throw new PluginException('payment_reconciliation_required', 0, $exception);
        }
    }

    /** @param array<string,mixed> $binding @param array<string,mixed> $payment */
    private function linkPayment(array $binding, array $payment): void
    {
        if ((string) ($payment['idempotency_key'] ?? '') !== (string) $binding['internal_key']
            || (string) ($payment['subject_type'] ?? '') !== 'cms_plugin_order'
            || (string) ($payment['subject_id'] ?? '') !== (string) $binding['core_subject_id']
            || (string) ($payment['provider_id'] ?? '') !== (string) $binding['provider_id']
            || (int) ($payment['amount_minor'] ?? 0) !== (int) $binding['amount_minor']
            || (string) ($payment['currency'] ?? '') !== (string) $binding['currency']) {
            throw new PluginException('service_unavailable');
        }
        $stmt = $this->pdo->prepare("UPDATE cms_plugin_payment_bindings SET payment_id = :payment, state = 'created', updated_at = :now WHERE id = :id AND payment_id IS NULL");
        $stmt->execute([':payment' => $payment['id'], ':now' => gmdate('c'), ':id' => $binding['id']]);
        if ($stmt->rowCount() !== 1) {
            $current = $this->bindingByRef((string) $binding['payment_ref']);
            if ((int) ($current['payment_id'] ?? 0) !== (int) $payment['id']) {
                throw new PluginException('service_unavailable');
            }
        }
    }

    /** @param array<string,mixed> $binding @return array<string,mixed> */
    private function boundPayment(array $binding, bool $lock = false): array
    {
        $id = (int) ($binding['payment_id'] ?? 0);
        if ($id <= 0) {
            throw new PluginException('payment_not_fulfillable');
        }
        $repo = new PaymentRepository($this->pdo);
        $payment = $lock ? $repo->paymentByIdempotencyForUpdate((string) $binding['internal_key']) : $repo->payment($id);
        if ($payment === null || (int) $payment['id'] !== $id || (string) $payment['idempotency_key'] !== (string) $binding['internal_key']) {
            throw new PluginException('service_unavailable');
        }
        return $payment;
    }

    /** @param array<string,mixed> $binding @param array<string,mixed> $payment @return array<string,mixed> */
    private function publicPayment(array $binding, array $payment): array
    {
        $result = [
            'payment_ref' => (string) $binding['payment_ref'], 'status' => (string) $payment['status'],
            'amount_minor' => (int) $binding['amount_minor'], 'currency' => (string) $binding['currency'],
        ];
        if (isset($payment['_provider_checkout_url']) && is_string($payment['_provider_checkout_url'])) {
            $result['checkout_url'] = $payment['_provider_checkout_url'];
        }
        return $result;
    }

    /** @param array<string,mixed> $binding @param array<string,mixed> $payment @param array<string,mixed> $entitlement */
    private function publicEntitlement(array $binding, array $payment, array $entitlement, PaymentRepository $repo): array
    {
        if ((int) $entitlement['source_payment_id'] !== (int) $payment['id']
            || (string) $entitlement['principal_type'] !== (string) $binding['beneficiary_type']
            || (string) $entitlement['principal_id'] !== (string) $binding['beneficiary_id']
            || (string) $entitlement['expires_at'] !== (string) $binding['expires_at']) {
            throw new PluginException('service_unavailable');
        }
        $active = (string) $entitlement['status'] === 'active'
            && (string) $binding['expires_at'] > gmdate('c')
            && in_array((string) $payment['status'], ['paid', 'partially_refunded'], true)
            && $repo->refundedMinorForPayment((int) $payment['id']) < (int) $binding['amount_minor'];
        return ['entitlement_ref' => 'pe_' . $entitlement['id'], 'status' => $active ? 'active' : 'inactive', 'expires_at' => (string) $binding['expires_at']];
    }

    private function payments(): PaymentService
    {
        return new PaymentService($this->pdo, new PaymentRepository($this->pdo), $this->providerSettingsKey);
    }

    private function rollback(): void
    {
        if (PaymentTransaction::isActive($this->pdo)) {
            PaymentTransaction::rollback($this->pdo);
        }
    }
}
