<?php

declare(strict_types=1);

namespace Cms\Core\Export;

use PDO;
use PDOException;

final class CorePaymentLedgerImporter
{
    private const MAX_WEBHOOK_PAYLOAD_BYTES = 1048576;

    /** @var array<string,array{table:string,columns:list<string>}> */
    private const SECTIONS = [
        'provider_settings' => [
            'table' => 'cms_payment_provider_settings',
            'columns' => ['provider_id', 'display_name', 'status', 'public_config_json', 'secret_config_ciphertext', 'created_at', 'updated_at'],
        ],
        'payments' => [
            'table' => 'cms_payments',
            'columns' => ['id', 'subject_type', 'subject_id', 'provider_id', 'remote_id', 'reference', 'status', 'amount_minor', 'currency', 'idempotency_key', 'request_hash', 'metadata_json', 'authorized_at', 'paid_at', 'failed_at', 'cancelled_at', 'created_at', 'updated_at'],
        ],
        'refunds' => [
            'table' => 'cms_payment_refunds',
            'columns' => ['id', 'payment_id', 'provider_id', 'remote_id', 'status', 'amount_minor', 'currency', 'reason', 'idempotency_key', 'request_hash', 'metadata_json', 'completed_at', 'failed_at', 'cancelled_at', 'created_at', 'updated_at'],
        ],
        'webhook_receipts' => [
            'table' => 'cms_payment_webhook_receipts',
            'columns' => ['id', 'payment_id', 'provider_id', 'external_event_id', 'payload_hash', 'status', 'metadata_json', 'received_at', 'processed_at', 'created_at', 'updated_at'],
        ],
        'authorizations' => [
            'table' => 'cms_payment_authorizations',
            'columns' => ['id', 'payment_id', 'subject_type', 'subject_id', 'token_hash', 'status', 'max_uses', 'used_count', 'expires_at', 'revoked_at', 'last_used_at', 'metadata_json', 'created_at', 'updated_at'],
        ],
        'authorization_events' => [
            'table' => 'cms_payment_authorization_events',
            'columns' => ['id', 'authorization_id', 'payment_id', 'event_type', 'metadata_json', 'created_at'],
        ],
        'entitlements' => [
            'table' => 'cms_payment_entitlements',
            'columns' => ['id', 'principal_type', 'principal_id', 'subject_type', 'subject_id', 'source_payment_id', 'source_authorization_id', 'status', 'expires_at', 'revoked_at', 'metadata_json', 'created_at', 'updated_at'],
        ],
    ];

    /** @var array<string,list<string>> */
    private const RESTORE_KEYS = [
        'cms_payment_provider_settings' => ['provider_id'],
        'cms_payments' => ['id'],
        'cms_payment_refunds' => ['id'],
        'cms_payment_webhook_receipts' => ['id'],
        'cms_payment_authorizations' => ['id'],
        'cms_payment_authorization_events' => ['id'],
        'cms_payment_entitlements' => ['id'],
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ExportPackageReader $reader = new ExportPackageReader(),
    ) {
    }

    /** @return array<string,array{imported:int,skipped:int}> */
    public function importPackage(string $zipPath): array
    {
        return $this->importLedger($this->reader->paymentLedger($zipPath));
    }

    /** @param array<string,mixed> $ledger @return array<string,array{imported:int,skipped:int}> */
    public function importLedger(array $ledger): array
    {
        $started = !$this->pdo->inTransaction();
        if ($started) {
            $this->pdo->beginTransaction();
        }

        try {
            $result = [];
            foreach (self::SECTIONS as $section => $definition) {
                try {
                    $result[$section] = $this->importSection(
                        $definition['table'],
                        $definition['columns'],
                        $this->ledgerSectionRows($ledger, $section),
                    );
                } catch (\Throwable $exception) {
                    throw new ExportException('Unable to import Core payment ledger section ' . $this->safeDiagnosticToken($section) . ': ' . $this->safeExceptionMessage($exception));
                }
            }
            $this->validateLedgerRelations($ledger);

            if ($started) {
                $this->pdo->commit();
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception instanceof ExportException) {
                throw $exception;
            }

            throw new ExportException('Unable to import Core payment ledger: ' . $this->safeExceptionMessage($exception));
        }
    }

    /** @param array<string,mixed> $ledger @return array<int,array<string,mixed>> */
    private function ledgerSectionRows(array $ledger, string $section): array
    {
        if (!array_key_exists($section, $ledger) || !is_array($ledger[$section])) {
            throw new ExportException('Core payment ledger section is missing or invalid.');
        }

        return $ledger[$section];
    }

    /** @param list<string> $columns @param array<int,array<string,mixed>> $rows @return array{imported:int,skipped:int} */
    private function importSection(string $table, array $columns, array $rows): array
    {
        $columnSql = implode(', ', array_map([$this, 'quoteIdentifier'], $columns));
        $parameterSql = implode(', ', array_fill(0, count($columns), '?'));
        $sql = 'INSERT INTO ' . $this->quoteIdentifier($table) . ' (' . $columnSql . ') VALUES (' . $parameterSql . ')';

        $imported = 0;
        $skipped = 0;
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new ExportException('Core payment ledger row is invalid.');
            }
            $this->validateRowShape($table, $columns, $row);

            try {
                $stmt = $this->pdo->prepare($sql);
                $values = $this->bindRow($table, $columns, $row);
                foreach ($values as $position => $value) {
                    $stmt->bindValue($position + 1, $value, $this->pdoType($value));
                }
                $stmt->execute();
                $stmt->closeCursor();
                $imported++;
            } catch (PDOException $exception) {
                if ($this->isDuplicate($exception)) {
                    if (!$this->existingRowMatches($table, $columns, $row)) {
                        throw new ExportException(
                            $this->safeRowContext($table, $index, $row)
                            . ' conflicts with existing Core payment ledger data'
                        );
                    }
                    $skipped++;
                    continue;
                }

                throw new ExportException(
                    $this->safeRowContext($table, $index, $row)
                    . ' failed with value types ' . implode(',', array_map(static fn (mixed $value): string => get_debug_type($value), $values ?? []))
                    . ': ' . $this->safeExceptionMessage($exception)
                );
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /** @param list<string> $columns @param array<string,mixed> $row */
    private function validateRowShape(string $table, array $columns, array $row): void
    {
        $allowed = array_fill_keys($columns, true);
        foreach ($row as $column => $_value) {
            if (!is_string($column) || !isset($allowed[$column])) {
                throw new ExportException('Core payment ledger row contains an unexpected column for ' . $table . '.');
            }
        }
        foreach ($columns as $column) {
            if (!array_key_exists($column, $row)) {
                throw new ExportException('Core payment ledger row is missing a required column for ' . $table . ': ' . $column);
            }
        }
    }

    /** @param array<string,mixed> $ledger */
    private function validateLedgerRelations(array $ledger): void
    {
        $this->validateUniqueProviderSettings($ledger);
        foreach (is_array($ledger['provider_settings'] ?? null) ? $ledger['provider_settings'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $this->validateCreatedUpdatedTimestampOrder('cms_payment_provider_settings', $row);
        }
        $this->validateProviderDefaultMarkers($ledger);

        foreach (is_array($ledger['payments'] ?? null) ? $ledger['payments'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $this->validatePaymentStatusTimestamps($row);
            $this->validatePaymentTimestampOrder($row);
        }

        foreach (is_array($ledger['refunds'] ?? null) ? $ledger['refunds'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $this->validateRefundStatusTimestamps($row);
            $this->validateRefundTimestampOrder($row);
            $payment = $this->paymentForRelation(
                $this->normalizeIntegerValue('cms_payment_refunds', 'payment_id', $row['payment_id'] ?? null),
                'Core payment ledger refund references a missing payment.'
            );
            if ((string) ($payment['provider_id'] ?? '') !== (string) ($row['provider_id'] ?? '')
                || (string) ($payment['currency'] ?? '') !== (string) ($row['currency'] ?? '')
            ) {
                throw new ExportException('Core payment ledger refund provider or currency does not match its source payment.');
            }
        }
        $this->validateCompletedRefundTotals();

        foreach (is_array($ledger['webhook_receipts'] ?? null) ? $ledger['webhook_receipts'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $this->validateWebhookReceiptStatusTimestamp($row);
            $this->validateWebhookReceiptTimestampOrder($row);
            if (($row['payment_id'] ?? null) === null || ($row['payment_id'] ?? null) === '') {
                continue;
            }
            $payment = $this->paymentForRelation(
                $this->normalizeIntegerValue('cms_payment_webhook_receipts', 'payment_id', $row['payment_id']),
                'Core payment ledger webhook receipt references a missing payment.'
            );
            if ((string) ($payment['provider_id'] ?? '') !== (string) ($row['provider_id'] ?? '')) {
                throw new ExportException('Core payment ledger webhook receipt provider does not match its bound payment.');
            }
        }

        foreach (is_array($ledger['authorizations'] ?? null) ? $ledger['authorizations'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $this->validateAccessRevocationState(
                'authorization',
                (string) ($row['status'] ?? ''),
                $row['revoked_at'] ?? null
            );
            $this->validateAuthorizationUseState($row);
            $this->validateAuthorizationTimestampOrder($row);
            $this->validateActiveAccessExpiryState('authorization', (string) ($row['status'] ?? ''), $row['expires_at'] ?? null, false);
            $payment = $this->paymentForRelation(
                $this->normalizeIntegerValue('cms_payment_authorizations', 'payment_id', $row['payment_id'] ?? null),
                'Core payment ledger authorization references a missing payment.'
            );
            if ((string) ($payment['subject_type'] ?? '') !== (string) ($row['subject_type'] ?? '')
                || (string) ($payment['subject_id'] ?? '') !== (string) ($row['subject_id'] ?? '')
            ) {
                throw new ExportException('Core payment ledger authorization subject does not match its source payment.');
            }
            $this->validateActiveAccessPaymentState('authorization', (string) ($row['status'] ?? ''), $payment);
            $this->validateAuthorizationEventState($row);
        }

        foreach (is_array($ledger['authorization_events'] ?? null) ? $ledger['authorization_events'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $authorization = $this->authorizationForRelation(
                $this->normalizeIntegerValue('cms_payment_authorization_events', 'authorization_id', $row['authorization_id'] ?? null),
                'Core payment ledger authorization event references a missing authorization.'
            );
            $paymentId = $this->normalizeIntegerValue('cms_payment_authorization_events', 'payment_id', $row['payment_id'] ?? null);
            $authorizationPaymentId = $this->normalizeIntegerValue('cms_payment_authorizations', 'payment_id', $authorization['payment_id'] ?? null);
            if ($paymentId === null || $authorizationPaymentId === null || $authorizationPaymentId !== $paymentId) {
                throw new ExportException('Core payment ledger authorization event payment does not match its authorization.');
            }
            $this->validateAuthorizationEventTimestampOrder($row, $authorization);
        }

        foreach (is_array($ledger['entitlements'] ?? null) ? $ledger['entitlements'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $this->validateAccessRevocationState(
                'entitlement',
                (string) ($row['status'] ?? ''),
                $row['revoked_at'] ?? null
            );
            $this->validateEntitlementTimestampOrder($row);
            $this->validateActiveAccessExpiryState('entitlement', (string) ($row['status'] ?? ''), $row['expires_at'] ?? null, true);
            $payment = $this->paymentForRelation(
                $this->normalizeIntegerValue('cms_payment_entitlements', 'source_payment_id', $row['source_payment_id'] ?? null),
                'Core payment ledger entitlement references a missing source payment.'
            );
            if ((string) ($payment['subject_type'] ?? '') !== (string) ($row['subject_type'] ?? '')
                || (string) ($payment['subject_id'] ?? '') !== (string) ($row['subject_id'] ?? '')
            ) {
                throw new ExportException('Core payment ledger entitlement subject does not match its source payment.');
            }
            $this->validateActiveAccessPaymentState('entitlement', (string) ($row['status'] ?? ''), $payment);
            if (($row['source_authorization_id'] ?? null) === null || ($row['source_authorization_id'] ?? null) === '') {
                continue;
            }
            $authorization = $this->authorizationForRelation(
                $this->normalizeIntegerValue('cms_payment_entitlements', 'source_authorization_id', $row['source_authorization_id']),
                'Core payment ledger entitlement references a missing source authorization.'
            );
            $authorizationPaymentId = $this->normalizeIntegerValue('cms_payment_authorizations', 'payment_id', $authorization['payment_id'] ?? null);
            $paymentId = $this->normalizeIntegerValue('cms_payments', 'id', $payment['id'] ?? null);
            if ($authorizationPaymentId === null || $paymentId === null || $authorizationPaymentId !== $paymentId
                || (string) ($authorization['subject_type'] ?? '') !== (string) ($row['subject_type'] ?? '')
                || (string) ($authorization['subject_id'] ?? '') !== (string) ($row['subject_id'] ?? '')
            ) {
                throw new ExportException('Core payment ledger entitlement source authorization does not match its source payment.');
            }
            if ((string) ($row['status'] ?? '') === 'active' && (string) ($authorization['status'] ?? '') !== 'active') {
                throw new ExportException('Core payment ledger active entitlement references an inactive source authorization.');
            }
            if ((string) ($row['status'] ?? '') === 'active') {
                $this->validateActiveAccessExpiryState('source authorization', 'active', $authorization['expires_at'] ?? null, false);
            }
        }

        $this->validateUniquePaymentAuthorizations();
        $this->validateUniquePaymentEntitlements();
    }

    /** @param array<string,mixed> $ledger */
    private function validateUniqueProviderSettings(array $ledger): void
    {
        $seen = [];
        foreach (is_array($ledger['provider_settings'] ?? null) ? $ledger['provider_settings'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $providerId = (string) ($row['provider_id'] ?? '');
            if ($providerId === '') {
                continue;
            }
            if (isset($seen[$providerId])) {
                throw new ExportException('Core payment ledger contains duplicate Provider settings.');
            }
            $seen[$providerId] = true;
        }
    }

    private function validateUniquePaymentAuthorizations(): void
    {
        $stmt = $this->pdo->query(
            'SELECT payment_id, subject_type, subject_id
             FROM cms_payment_authorizations
             GROUP BY payment_id, subject_type, subject_id
             HAVING COUNT(*) > 1
             LIMIT 1'
        );
        if ($stmt === false) {
            throw new ExportException('Core payment ledger authorization uniqueness could not be validated.');
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (is_array($row)) {
            throw new ExportException('Core payment ledger contains duplicate authorizations for one payment subject.');
        }
    }

    private function validateUniquePaymentEntitlements(): void
    {
        $stmt = $this->pdo->query(
            'SELECT source_payment_id, principal_type, principal_id, subject_type, subject_id
             FROM cms_payment_entitlements
             GROUP BY source_payment_id, principal_type, principal_id, subject_type, subject_id
             HAVING COUNT(*) > 1
             LIMIT 1'
        );
        if ($stmt === false) {
            throw new ExportException('Core payment ledger entitlement uniqueness could not be validated.');
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (is_array($row)) {
            throw new ExportException('Core payment ledger contains duplicate entitlements for one payment principal and subject.');
        }
    }

    /** @param array<string,mixed> $ledger */
    private function validateProviderDefaultMarkers(array $ledger): void
    {
        $defaultCount = 0;
        foreach (is_array($ledger['provider_settings'] ?? null) ? $ledger['provider_settings'] : [] as $row) {
            if (!is_array($row) || (string) ($row['status'] ?? '') !== 'enabled') {
                continue;
            }
            $publicJson = $row['public_config_json'] ?? '{}';
            if (!is_string($publicJson)) {
                throw new ExportException('Core payment Provider public config is invalid.');
            }
            if ($publicJson === '') {
                continue;
            }
            $public = $this->decodeCanonicalJsonColumn(
                'public_config_json',
                $publicJson
            );
            $this->validateProviderPublicConfig($public);
            if (($public['default_provider'] ?? null) === true) {
                $defaultCount++;
            }
        }
        if ($defaultCount > 1) {
            throw new ExportException('Core payment ledger Provider defaults are ambiguous.');
        }
    }

    /** @param array<string,mixed> $row */
    private function validateAuthorizationUseState(array $row): void
    {
        $maxUses = $this->normalizeIntegerValue('cms_payment_authorizations', 'max_uses', $row['max_uses'] ?? null);
        $usedCount = $this->normalizeIntegerValue('cms_payment_authorizations', 'used_count', $row['used_count'] ?? null);
        if ($maxUses === null || $usedCount === null) {
            throw new ExportException('Core payment ledger authorization counters are invalid.');
        }
        if ($maxUses > 0 && $usedCount > $maxUses) {
            throw new ExportException('Core payment ledger authorization used count exceeds max uses.');
        }

        $hasLastUsedAt = ($row['last_used_at'] ?? null) !== null && ($row['last_used_at'] ?? null) !== '';
        if ($usedCount > 0 && !$hasLastUsedAt) {
            throw new ExportException('Core payment ledger used authorization is missing last_used_at.');
        }
        if ($usedCount === 0 && $hasLastUsedAt) {
            throw new ExportException('Core payment ledger unused authorization has last_used_at.');
        }
    }

    /** @param array<string,mixed> $row */
    private function validateAuthorizationEventState(array $row): void
    {
        $authorizationId = $this->normalizeIntegerValue('cms_payment_authorizations', 'id', $row['id'] ?? null);
        if ($authorizationId === null) {
            throw new ExportException('Core payment ledger authorization event state is invalid.');
        }

        $counts = $this->authorizationEventCounts($authorizationId);
        $created = $counts['created'] ?? 0;
        $consumed = $counts['consumed'] ?? 0;
        $revoked = $counts['revoked'] ?? 0;
        $expired = $counts['expired'] ?? 0;
        $usedCount = $this->normalizeIntegerValue('cms_payment_authorizations', 'used_count', $row['used_count'] ?? null);
        $status = (string) ($row['status'] ?? '');

        if ($created !== 1) {
            throw new ExportException('Core payment ledger authorization must have exactly one created event.');
        }
        if ($usedCount === null || $consumed !== $usedCount) {
            throw new ExportException('Core payment ledger authorization consumed events do not match used_count.');
        }
        if ($status === 'active' && ($revoked > 0 || $expired > 0)) {
            throw new ExportException('Core payment ledger active authorization has terminal events.');
        }
        if ($status === 'revoked' && ($revoked !== 1 || $expired > 0)) {
            throw new ExportException('Core payment ledger revoked authorization event state is invalid.');
        }
        if ($status === 'expired' && ($expired !== 1 || $revoked > 0)) {
            throw new ExportException('Core payment ledger expired authorization event state is invalid.');
        }
    }

    /** @return array<string,int> */
    private function authorizationEventCounts(int $authorizationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT event_type, COUNT(*) AS event_count
             FROM cms_payment_authorization_events
             WHERE authorization_id = ?
             GROUP BY event_type'
        );
        $stmt->bindValue(1, $authorizationId, PDO::PARAM_INT);
        $stmt->execute();

        $counts = [];
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        foreach ($payments as $row) {
            if (!is_array($row)) {
                continue;
            }
            $counts[(string) ($row['event_type'] ?? '')] = (int) ($row['event_count'] ?? 0);
        }
        $stmt->closeCursor();

        return $counts;
    }

    /** @param array<string,mixed> $payment */
    private function validateActiveAccessPaymentState(string $kind, string $status, array $payment): void
    {
        if ($status !== 'active') {
            return;
        }
        if (!in_array((string) ($payment['status'] ?? ''), ['paid', 'partially_refunded'], true)) {
            throw new ExportException('Core payment ledger active ' . $kind . ' references an untrusted source payment.');
        }
    }

    private function validateActiveAccessExpiryState(string $kind, string $status, mixed $expiresAt, bool $nullable): void
    {
        if ($status !== 'active') {
            return;
        }
        if (($expiresAt === null || $expiresAt === '') && $nullable) {
            return;
        }

        $expiry = $this->canonicalTimestampEpoch($expiresAt);
        if ($expiry === null || $expiry <= time()) {
            throw new ExportException('Core payment ledger active ' . $kind . ' is expired.');
        }
    }

    private function validateCompletedRefundTotals(): void
    {
        $stmt = $this->pdo->query('SELECT id, status, amount_minor FROM cms_payments ORDER BY id ASC');
        if ($stmt === false) {
            throw new ExportException('Core payment ledger refund totals could not be validated.');
        }

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row)) {
                continue;
            }
            $status = (string) ($row['status'] ?? '');
            $paymentId = $this->normalizeIntegerValue('cms_payments', 'id', $row['id'] ?? null);
            $amountMinor = $this->normalizeIntegerValue('cms_payments', 'amount_minor', $row['amount_minor'] ?? null);
            if ($paymentId === null || $amountMinor === null) {
                throw new ExportException('Core payment ledger refund totals could not be validated.');
            }
            $completedRefundMinor = $this->completedRefundMinorForImportedPayment($paymentId);
            if ($completedRefundMinor > $amountMinor) {
                throw new ExportException('Core payment ledger completed refunds exceed the source payment amount.');
            }
            if ($completedRefundMinor > 0 && !in_array($status, ['partially_refunded', 'refunded'], true)) {
                throw new ExportException('Core payment ledger completed refunds do not match the source payment status.');
            }
            if ($status === 'partially_refunded' && ($completedRefundMinor <= 0 || $completedRefundMinor >= $amountMinor)) {
                throw new ExportException('Core payment ledger partially refunded payment total is invalid.');
            }
            if ($status === 'refunded' && $completedRefundMinor !== $amountMinor) {
                throw new ExportException('Core payment ledger refunded payment total is invalid.');
            }
        }
    }

    private function completedRefundMinorForImportedPayment(int $paymentId): int
    {
        $stmt = $this->pdo->prepare("SELECT amount_minor FROM cms_payment_refunds WHERE payment_id = :payment_id AND status = 'completed' ORDER BY id ASC");
        $stmt->execute([':payment_id' => $paymentId]);

        $total = 0;
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row)) {
                continue;
            }
            $amountMinor = $this->normalizeIntegerValue('cms_payment_refunds', 'amount_minor', $row['amount_minor'] ?? null);
            if ($amountMinor === null) {
                throw new ExportException('Core payment ledger refund totals could not be validated.');
            }
            $total += $amountMinor;
        }
        $stmt->closeCursor();

        return $total;
    }

    /** @param array<string,mixed> $row */
    private function validatePaymentStatusTimestamps(array $row): void
    {
        $status = (string) ($row['status'] ?? '');
        $hasAuthorizedAt = ($row['authorized_at'] ?? null) !== null && ($row['authorized_at'] ?? null) !== '';
        $hasPaidAt = ($row['paid_at'] ?? null) !== null && ($row['paid_at'] ?? null) !== '';
        $hasFailedAt = ($row['failed_at'] ?? null) !== null && ($row['failed_at'] ?? null) !== '';
        $hasCancelledAt = ($row['cancelled_at'] ?? null) !== null && ($row['cancelled_at'] ?? null) !== '';

        if ($status === 'pending' && ($hasAuthorizedAt || $hasPaidAt || $hasFailedAt || $hasCancelledAt)) {
            throw new ExportException('Core payment ledger pending payment has lifecycle timestamps.');
        }
        if ($status === 'authorized' && (!$hasAuthorizedAt || $hasPaidAt || $hasFailedAt || $hasCancelledAt)) {
            throw new ExportException('Core payment ledger authorized payment timestamp state is invalid.');
        }
        if (in_array($status, ['paid', 'partially_refunded', 'refunded'], true) && (!$hasPaidAt || $hasFailedAt || $hasCancelledAt)) {
            throw new ExportException('Core payment ledger paid payment timestamp state is invalid.');
        }
        if ($status === 'failed' && (!$hasFailedAt || $hasPaidAt || $hasCancelledAt)) {
            throw new ExportException('Core payment ledger failed payment timestamp state is invalid.');
        }
        if ($status === 'cancelled' && (!$hasCancelledAt || $hasPaidAt || $hasFailedAt)) {
            throw new ExportException('Core payment ledger cancelled payment timestamp state is invalid.');
        }
    }

    /** @param array<string,mixed> $row */
    private function validateRefundStatusTimestamps(array $row): void
    {
        $status = (string) ($row['status'] ?? '');
        $columns = [
            'completed' => 'completed_at',
            'failed' => 'failed_at',
            'cancelled' => 'cancelled_at',
        ];
        foreach ($columns as $terminalStatus => $column) {
            $hasTimestamp = ($row[$column] ?? null) !== null && ($row[$column] ?? null) !== '';
            if ($status === $terminalStatus && !$hasTimestamp) {
                throw new ExportException('Core payment ledger ' . $terminalStatus . ' refund is missing ' . $column . '.');
            }
            if ($status !== $terminalStatus && $hasTimestamp) {
                throw new ExportException('Core payment ledger refund status does not match ' . $column . '.');
            }
        }
    }

    /** @param array<string,mixed> $row */
    private function validateWebhookReceiptStatusTimestamp(array $row): void
    {
        $status = (string) ($row['status'] ?? '');
        $hasProcessedAt = ($row['processed_at'] ?? null) !== null && ($row['processed_at'] ?? null) !== '';
        if (in_array($status, ['processed', 'ignored'], true) && !$hasProcessedAt) {
            throw new ExportException('Core payment ledger terminal webhook receipt is missing processed_at.');
        }
        if (in_array($status, ['received', 'failed'], true) && $hasProcessedAt) {
            throw new ExportException('Core payment ledger non-terminal webhook receipt has processed_at.');
        }
    }

    /** @param array<string,mixed> $row */
    private function validatePaymentTimestampOrder(array $row): void
    {
        $table = 'cms_payments';
        $this->validateCreatedUpdatedTimestampOrder($table, $row);
        foreach (['authorized_at', 'paid_at', 'failed_at', 'cancelled_at'] as $column) {
            $this->validateTimestampOrder($table, $row, 'created_at', $column);
            $this->validateTimestampOrder($table, $row, $column, 'updated_at');
        }
        $this->validateTimestampOrder($table, $row, 'authorized_at', 'paid_at');
        $this->validateTimestampOrder($table, $row, 'authorized_at', 'failed_at');
        $this->validateTimestampOrder($table, $row, 'authorized_at', 'cancelled_at');
    }

    /** @param array<string,mixed> $row */
    private function validateRefundTimestampOrder(array $row): void
    {
        $table = 'cms_payment_refunds';
        $this->validateCreatedUpdatedTimestampOrder($table, $row);
        foreach (['completed_at', 'failed_at', 'cancelled_at'] as $column) {
            $this->validateTimestampOrder($table, $row, 'created_at', $column);
            $this->validateTimestampOrder($table, $row, $column, 'updated_at');
        }
    }

    /** @param array<string,mixed> $row */
    private function validateWebhookReceiptTimestampOrder(array $row): void
    {
        $table = 'cms_payment_webhook_receipts';
        $this->validateCreatedUpdatedTimestampOrder($table, $row);
        $this->validateTimestampOrder($table, $row, 'created_at', 'received_at');
        $this->validateTimestampOrder($table, $row, 'received_at', 'processed_at');
        $this->validateTimestampOrder($table, $row, 'received_at', 'updated_at');
        $this->validateTimestampOrder($table, $row, 'processed_at', 'updated_at');
    }

    /** @param array<string,mixed> $row */
    private function validateAuthorizationTimestampOrder(array $row): void
    {
        $table = 'cms_payment_authorizations';
        $this->validateCreatedUpdatedTimestampOrder($table, $row);
        foreach (['expires_at', 'revoked_at', 'last_used_at'] as $column) {
            $this->validateTimestampOrder($table, $row, 'created_at', $column);
        }
        foreach (['revoked_at', 'last_used_at'] as $column) {
            $this->validateTimestampOrder($table, $row, $column, 'updated_at');
        }
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $authorization */
    private function validateAuthorizationEventTimestampOrder(array $row, array $authorization): void
    {
        $eventCreatedAt = $this->canonicalTimestampEpoch($row['created_at'] ?? null);
        $authorizationCreatedAt = $this->canonicalTimestampEpoch($authorization['created_at'] ?? null);
        $authorizationUpdatedAt = $this->canonicalTimestampEpoch($authorization['updated_at'] ?? null);
        if ($eventCreatedAt !== null && $authorizationCreatedAt !== null && $eventCreatedAt < $authorizationCreatedAt) {
            throw new ExportException('Core payment ledger authorization event timestamp predates its authorization.');
        }
        if ($eventCreatedAt !== null && $authorizationUpdatedAt !== null && $eventCreatedAt > $authorizationUpdatedAt) {
            throw new ExportException('Core payment ledger authorization event timestamp exceeds its authorization update time.');
        }
    }

    /** @param array<string,mixed> $row */
    private function validateEntitlementTimestampOrder(array $row): void
    {
        $table = 'cms_payment_entitlements';
        $this->validateCreatedUpdatedTimestampOrder($table, $row);
        $this->validateTimestampOrder($table, $row, 'created_at', 'expires_at');
        $this->validateTimestampOrder($table, $row, 'created_at', 'revoked_at');
        $this->validateTimestampOrder($table, $row, 'revoked_at', 'updated_at');
    }

    /** @param array<string,mixed> $row */
    private function validateCreatedUpdatedTimestampOrder(string $table, array $row): void
    {
        $this->validateTimestampOrder($table, $row, 'created_at', 'updated_at');
    }

    /** @param array<string,mixed> $row */
    private function validateTimestampOrder(string $table, array $row, string $earlierColumn, string $laterColumn): void
    {
        $earlier = $this->canonicalTimestampEpoch($row[$earlierColumn] ?? null);
        $later = $this->canonicalTimestampEpoch($row[$laterColumn] ?? null);
        if ($earlier !== null && $later !== null && $later < $earlier) {
            throw new ExportException(
                'Core payment ledger timestamp order is invalid for '
                . $table . ': ' . $laterColumn . ' predates ' . $earlierColumn . '.'
            );
        }
    }

    private function canonicalTimestampEpoch(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $timestamp = (string) $value;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})\+00:00$/', $timestamp, $matches) !== 1) {
            throw new ExportException('Core payment ledger timestamp column is invalid.');
        }

        $epoch = gmmktime(
            (int) $matches[4],
            (int) $matches[5],
            (int) $matches[6],
            (int) $matches[2],
            (int) $matches[3],
            (int) $matches[1],
        );
        if ($epoch === false) {
            throw new ExportException('Core payment ledger timestamp column is invalid.');
        }

        return $epoch;
    }

    private function validateAccessRevocationState(string $kind, string $status, mixed $revokedAt): void
    {
        if ($status === 'revoked' && ($revokedAt === null || $revokedAt === '')) {
            throw new ExportException('Core payment ledger revoked ' . $kind . ' is missing revoked_at.');
        }
        if ($status !== 'revoked' && $revokedAt !== null && $revokedAt !== '') {
            throw new ExportException('Core payment ledger non-revoked ' . $kind . ' has revoked_at.');
        }
    }

    /** @param list<string> $columns @param array<string,mixed> $row @return list<mixed> */
    private function bindRow(string $table, array $columns, array $row): array
    {
        $bound = [];
        foreach ($columns as $column) {
            $bound[] = $this->normalizeValue($table, $column, array_key_exists($column, $row) ? $row[$column] : null);
        }

        return $bound;
    }

    private function normalizeValue(string $table, string $column, mixed $value): mixed
    {
        if ($value === '' && $this->emptyStringMeansNull($table, $column)) {
            $value = null;
        }
        if (is_array($value) || is_object($value)) {
            throw new ExportException('Core payment ledger column is not scalar: ' . $column);
        }
        if ($this->isIntegerColumn($table, $column)) {
            $value = $this->normalizeIntegerValue($table, $column, $value);
        } elseif ($value !== null && $this->isStringColumn($table, $column) && !is_string($value)) {
            throw new ExportException('Core payment ledger column is not a string: ' . $column);
        }
        $this->validateValue($table, $column, $value);
        if ($column === 'status' && $value !== null) {
            return (string) $value;
        }
        if ($this->isTypeColumn($table, $column) && $value !== null) {
            return strtolower(trim((string) $value));
        }
        if ($column === 'currency' && $value !== null) {
            return (string) $value;
        }
        if ($this->isHashColumn($table, $column) && $value !== null) {
            return (string) $value;
        }
        if ($table === 'cms_payment_provider_settings' && $column === 'display_name' && $value !== null) {
            return (string) $value;
        }
        if ($table === 'cms_payment_provider_settings' && $column === 'secret_config_ciphertext' && $value !== null) {
            return (string) $value;
        }
        if (is_string($value) && $column !== 'metadata_json' && !str_ends_with($column, '_json')) {
            return trim($value);
        }

        return $value;
    }

    private function validateValue(string $table, string $column, mixed $value): void
    {
        if ($column === 'status') {
            $rawStatus = (string) $value;
            $status = strtolower(trim($rawStatus));
            $allowed = match ($table) {
                'cms_payment_provider_settings' => ['enabled', 'disabled'],
                'cms_payments' => ['pending', 'authorized', 'paid', 'partially_refunded', 'refunded', 'failed', 'cancelled'],
                'cms_payment_refunds' => ['pending', 'completed', 'failed', 'cancelled'],
                'cms_payment_webhook_receipts' => ['received', 'processed', 'ignored', 'failed'],
                'cms_payment_authorizations' => ['active', 'revoked', 'expired'],
                'cms_payment_entitlements' => ['active', 'revoked', 'expired'],
                default => [],
            };
            if ($allowed !== [] && $rawStatus !== $status) {
                throw new ExportException('Core payment ledger status is not canonical for ' . $table . ': ' . (string) $value);
            }
            if ($allowed !== [] && !in_array($status, $allowed, true)) {
                throw new ExportException('Core payment ledger status is invalid for ' . $table . ': ' . (string) $value);
            }
        }

        if ($column === 'metadata_json' || str_ends_with($column, '_json')) {
            $json = (string) $value;
            if ($json === '') {
                return;
            }
            $decoded = $this->decodeCanonicalJsonColumn($column, $json);
            if ($table === 'cms_payment_provider_settings' && $column === 'public_config_json') {
                $this->validateProviderPublicConfig($decoded);
            }
            if ($column === 'metadata_json') {
                $this->validateSafeMetadata($decoded);
            }
        }

        if ($column === 'currency' && $value !== null) {
            $currency = (string) $value;
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                throw new ExportException('Core payment ledger currency is invalid: ' . $column);
            }
        }

        if ($this->isProviderIdColumn($table, $column) && $value !== null) {
            $providerId = (string) $value;
            if ($providerId !== strtolower(trim($providerId))) {
                throw new ExportException('Core payment ledger Provider id is not canonical: ' . $column);
            }
        }

        if ($table === 'cms_payment_provider_settings' && $column === 'display_name') {
            $displayName = (string) $value;
            if (
                $displayName === ''
                || $displayName !== trim($displayName)
                || strlen($displayName) > 191
                || preg_match('/[\x00-\x1F\x7F]/', $displayName) === 1
                || $this->providerPublicConfigValueContainsSecret($displayName)
            ) {
                throw new ExportException('Core payment Provider display name is invalid.');
            }
        }

        if ($this->isTypeColumn($table, $column) && $value !== null) {
            $rawType = (string) $value;
            $type = strtolower(trim($rawType));
            if ($rawType !== $type) {
                throw new ExportException('Core payment ledger type column is not canonical: ' . $column);
            }
            if (preg_match('/^[a-z0-9][a-z0-9._-]{1,95}[a-z0-9]$/', $type) !== 1) {
                throw new ExportException('Core payment ledger type column is invalid: ' . $column);
            }
        }

        if ($this->isReferenceColumn($table, $column) && $value !== null) {
            $rawReference = (string) $value;
            $reference = trim($rawReference);
            if ($rawReference !== $reference) {
                throw new ExportException('Core payment ledger reference column is not canonical: ' . $column);
            }
            if (
                $reference === ''
                || strlen($reference) > 191
                || preg_match('/[\x00-\x1F\x7F]/', $reference) === 1
                || $this->metadataValueContainsSecret($reference)
            ) {
                throw new ExportException('Core payment ledger reference column is invalid: ' . $column);
            }
        }

        if ($this->isHashColumn($table, $column) && $value !== null) {
            $rawHash = (string) $value;
            $hash = trim($rawHash);
            if ($rawHash !== $hash) {
                throw new ExportException('Core payment ledger hash column is not canonical: ' . $column);
            }
            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new ExportException('Core payment ledger hash column is invalid: ' . $column);
            }
        }

        if ($table === 'cms_payment_refunds' && $column === 'reason' && $value !== null) {
            $rawReason = (string) $value;
            $reason = trim($rawReason);
            if ($rawReason !== $reason || strlen($reason) > 500 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason) === 1) {
                throw new ExportException('Core payment ledger refund reason is invalid.');
            }
        }

        if ($table === 'cms_payment_authorization_events' && $column === 'event_type') {
            $eventType = (string) $value;
            if (!in_array($eventType, ['created', 'consumed', 'revoked', 'expired'], true)) {
                throw new ExportException('Core payment authorization event type is invalid.');
            }
        }

        if ($table === 'cms_payment_provider_settings' && $column === 'secret_config_ciphertext') {
            $ciphertext = (string) $value;
            if ($ciphertext === '') {
                return;
            }
            $decoded = base64_decode($ciphertext, true);
            if ($ciphertext !== trim($ciphertext) || preg_match('/[\x00-\x1F\x7F]/', $ciphertext) === 1 || !is_string($decoded) || strlen($decoded) < 28) {
                throw new ExportException('Core payment Provider secret ciphertext is invalid.');
            }
        }

        if ($this->isTimestampColumn($table, $column)) {
            if ($value === null) {
                if (!$this->emptyStringMeansNull($table, $column)) {
                    throw new ExportException('Core payment ledger timestamp column is invalid: ' . $column);
                }
                return;
            }
            $timestamp = (string) $value;
            if (!$this->isCanonicalUtcTimestamp($timestamp)) {
                throw new ExportException('Core payment ledger timestamp column is invalid: ' . $column);
            }
        }
    }

    private function isCanonicalUtcTimestamp(string $timestamp): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})\+00:00$/', $timestamp, $matches) !== 1) {
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

    private function isTypeColumn(string $table, string $column): bool
    {
        return in_array($table . '.' . $column, [
            'cms_payment_provider_settings.provider_id',
            'cms_payments.subject_type',
            'cms_payments.provider_id',
            'cms_payment_refunds.provider_id',
            'cms_payment_webhook_receipts.provider_id',
            'cms_payment_authorizations.subject_type',
            'cms_payment_entitlements.principal_type',
            'cms_payment_entitlements.subject_type',
        ], true);
    }

    private function isProviderIdColumn(string $table, string $column): bool
    {
        return in_array($table . '.' . $column, [
            'cms_payment_provider_settings.provider_id',
            'cms_payments.provider_id',
            'cms_payment_refunds.provider_id',
            'cms_payment_webhook_receipts.provider_id',
        ], true);
    }

    private function isReferenceColumn(string $table, string $column): bool
    {
        return in_array($table . '.' . $column, [
            'cms_payments.subject_id',
            'cms_payments.remote_id',
            'cms_payments.reference',
            'cms_payments.idempotency_key',
            'cms_payment_refunds.remote_id',
            'cms_payment_refunds.idempotency_key',
            'cms_payment_webhook_receipts.external_event_id',
            'cms_payment_authorizations.subject_id',
            'cms_payment_authorization_events.event_type',
            'cms_payment_entitlements.principal_id',
            'cms_payment_entitlements.subject_id',
        ], true);
    }

    private function isHashColumn(string $table, string $column): bool
    {
        return in_array($table . '.' . $column, [
            'cms_payments.request_hash',
            'cms_payment_refunds.request_hash',
            'cms_payment_webhook_receipts.payload_hash',
            'cms_payment_authorizations.token_hash',
        ], true);
    }

    private function isTimestampColumn(string $table, string $column): bool
    {
        return in_array($table . '.' . $column, [
            'cms_payment_provider_settings.created_at',
            'cms_payment_provider_settings.updated_at',
            'cms_payments.authorized_at',
            'cms_payments.paid_at',
            'cms_payments.failed_at',
            'cms_payments.cancelled_at',
            'cms_payments.created_at',
            'cms_payments.updated_at',
            'cms_payment_refunds.completed_at',
            'cms_payment_refunds.failed_at',
            'cms_payment_refunds.cancelled_at',
            'cms_payment_refunds.created_at',
            'cms_payment_refunds.updated_at',
            'cms_payment_webhook_receipts.received_at',
            'cms_payment_webhook_receipts.processed_at',
            'cms_payment_webhook_receipts.created_at',
            'cms_payment_webhook_receipts.updated_at',
            'cms_payment_authorizations.expires_at',
            'cms_payment_authorizations.revoked_at',
            'cms_payment_authorizations.last_used_at',
            'cms_payment_authorizations.created_at',
            'cms_payment_authorizations.updated_at',
            'cms_payment_authorization_events.created_at',
            'cms_payment_entitlements.expires_at',
            'cms_payment_entitlements.revoked_at',
            'cms_payment_entitlements.created_at',
            'cms_payment_entitlements.updated_at',
        ], true);
    }

    /** @return array<mixed,mixed> */
    private function decodeCanonicalJsonColumn(string $column, string $json): array
    {
        if ($json !== trim($json)) {
            throw new ExportException('Core payment ledger JSON column is not canonical: ' . $column);
        }
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ExportException('Core payment ledger JSON column is invalid: ' . $column);
        }
        if (!is_array($decoded)) {
            throw new ExportException('Core payment ledger JSON column is invalid: ' . $column);
        }
        try {
            $canonicalJson = $json === '{}' && $decoded === []
                ? '{}'
                : json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ExportException('Core payment ledger JSON column is invalid: ' . $column);
        }
        if ($json !== $canonicalJson) {
            throw new ExportException('Core payment ledger JSON column is not canonical: ' . $column);
        }

        return $decoded;
    }

    /** @param array<mixed,mixed>|null $config */
    private function validateProviderPublicConfig(?array $config): void
    {
        if ($config === null) {
            throw new ExportException('Core payment Provider public config is invalid.');
        }
        foreach ($config as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $key) !== 1) {
                throw new ExportException('Core payment Provider public config key is invalid.');
            }
            if (preg_match('/password|secret|token|authorization|signature|auth|api[_-]?key|access[_-]?key|private/i', $key) === 1) {
                throw new ExportException('Core payment Provider public config cannot contain secrets.');
            }
            if ($key === 'default_provider' && !is_bool($value) && $value !== null) {
                throw new ExportException('Core payment Provider default marker is invalid.');
            }
            if (!(is_scalar($value) || $value === null)) {
                throw new ExportException('Core payment Provider public config value is invalid.');
            }
            if (($key === 'return_url_base' || str_contains(strtolower($key), 'url')) && $value !== null && !is_string($value)) {
                throw new ExportException('Core payment Provider public config URL is invalid.');
            }
            if (is_string($value) && ($value !== trim($value) || strlen($value) > 4096 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1)) {
                throw new ExportException('Core payment Provider public config value is invalid.');
            }
            if (is_string($value) && $this->providerPublicConfigValueContainsSecret($value)) {
                throw new ExportException('Core payment Provider public config cannot contain secrets.');
            }
            if (str_contains(strtolower($key), 'url') && is_string($value) && !$this->providerPublicConfigUrlSafe($value)) {
                throw new ExportException('Core payment Provider public config URL is invalid.');
            }
        }
    }

    private function providerPublicConfigUrlSafe(string $url): bool
    {
        if ($url === '') {
            return true;
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

    private function providerPublicConfigValueContainsSecret(string $value): bool
    {
        $pattern = '/(?:bearer\s+|payment_token=|sk_[A-Za-z0-9_=-]+|api[_-]?key=|access[_-]?key=|secret=|signature=)/i';

        return preg_match($pattern, $value) === 1
            || preg_match($pattern, rawurldecode($value)) === 1;
    }

    private function metadataValueContainsSecret(string $value): bool
    {
        $pattern = '/(?:bearer\s+|payment_token=|sk_[A-Za-z0-9_=-]+|api[_-]?key=|access[_-]?key=|secret=|signature=)/i';

        return preg_match($pattern, $value) === 1
            || preg_match($pattern, rawurldecode($value)) === 1;
    }

    /** @param array<mixed,mixed>|null $metadata */
    private function validateSafeMetadata(?array $metadata): void
    {
        if ($metadata === null) {
            throw new ExportException('Core payment metadata is invalid.');
        }
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || strlen($key) > 64 || preg_match('/^[a-zA-Z0-9._-]+$/', $key) !== 1) {
                throw new ExportException('Core payment metadata key is invalid.');
            }
            if (!(is_scalar($value) || $value === null)) {
                throw new ExportException('Core payment metadata value is invalid.');
            }
            $name = strtolower($key);
            if (preg_match('/password|secret|token|authorization|signature|auth|api[_-]?key|access[_-]?key|private|email|phone|address/', $name) === 1
                && $value !== '[redacted]'
            ) {
                throw new ExportException('Core payment metadata contains unredacted sensitive fields.');
            }
            if (is_string($value) && (strlen($value) > 4096 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1)) {
                throw new ExportException('Core payment metadata value is invalid.');
            }
            if ($key === 'payload_size' && (!is_int($value) || $value < 0 || $value > self::MAX_WEBHOOK_PAYLOAD_BYTES)) {
                throw new ExportException('Core payment webhook payload-size metadata is invalid.');
            }
            if ($key === 'content_type' && (!is_string($value) || !$this->webhookMetadataContentTypeSafe($value))) {
                throw new ExportException('Core payment webhook content-type metadata is invalid.');
            }
            if ($key === 'webhook_timestamp' && (!is_string($value) || !$this->webhookMetadataTimestampSafe($value))) {
                throw new ExportException('Core payment webhook timestamp metadata is invalid.');
            }
            if ($key === 'source_ip_hash' && (!is_string($value) || !$this->webhookMetadataSourceHashSafe($value))) {
                throw new ExportException('Core payment webhook source metadata is invalid.');
            }
            if (str_contains($name, 'url') && is_string($value)) {
                if (!$this->metadataUrlSafe($value)) {
                    throw new ExportException('Core payment metadata URL is not redacted.');
                }
                continue;
            }
            if (is_string($value) && $this->metadataValueContainsSecret($value)) {
                throw new ExportException('Core payment metadata contains unredacted sensitive values.');
            }
            if ($key === 'failure_error') {
                if (
                    !is_string($value)
                    || $value === ''
                    || $value !== trim($value)
                    || strlen($value) > 240
                    || $this->metadataValueContainsSecret($value)
                ) {
                    throw new ExportException('Core payment webhook failure metadata is invalid.');
                }
            }
            if ($key === 'failed_at') {
                if (!is_string($value) || !$this->isCanonicalUtcTimestamp($value)) {
                    throw new ExportException('Core payment webhook failure timestamp metadata is invalid.');
                }
            }
            if ($key === 'manual_instructions') {
                if (!is_string($value) || !$this->manualMetadataInstructionsSafe($value)) {
                    throw new ExportException('Core manual payment instructions metadata is invalid.');
                }
            }
            if ($key === 'manual_reference') {
                if (!is_string($value) || !$this->manualMetadataReferenceSafe($value)) {
                    throw new ExportException('Core manual payment reference metadata is invalid.');
                }
            }
        }
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

    private function manualMetadataInstructionsSafe(string $value): bool
    {
        return $value !== ''
            && $value === trim($value)
            && strlen($value) <= 4096
            && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) !== 1;
    }

    private function manualMetadataReferenceSafe(string $value): bool
    {
        return $value !== ''
            && $value === trim($value)
            && strlen($value) <= 191
            && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) !== 1;
    }

    private function metadataUrlSafe(string $url): bool
    {
        if ($url === '' || $url === '[redacted]') {
            return true;
        }
        if ($url !== trim($url)) {
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
        if ($this->metadataValueContainsSecret((string) ($parts['path'] ?? ''))) {
            return false;
        }
        if (!isset($parts['query'])) {
            return true;
        }

        parse_str((string) $parts['query'], $query);
        foreach ($query as $key => $value) {
            if (!is_scalar($value)) {
                return false;
            }
            if (preg_match('/claim|token|signature|secret|authorization|auth|key|password|private/i', (string) $key) === 1 && $value !== '[redacted]') {
                return false;
            }
            if ($this->metadataValueContainsSecret((string) $value)) {
                return false;
            }
        }

        return true;
    }

    private function isIntegerColumn(string $table, string $column): bool
    {
        return in_array($table . '.' . $column, [
            'cms_payments.id',
            'cms_payments.amount_minor',
            'cms_payment_refunds.id',
            'cms_payment_refunds.payment_id',
            'cms_payment_refunds.amount_minor',
            'cms_payment_webhook_receipts.id',
            'cms_payment_webhook_receipts.payment_id',
            'cms_payment_authorizations.id',
            'cms_payment_authorizations.payment_id',
            'cms_payment_authorizations.max_uses',
            'cms_payment_authorizations.used_count',
            'cms_payment_authorization_events.id',
            'cms_payment_authorization_events.authorization_id',
            'cms_payment_authorization_events.payment_id',
            'cms_payment_entitlements.id',
            'cms_payment_entitlements.source_payment_id',
            'cms_payment_entitlements.source_authorization_id',
        ], true);
    }

    private function isStringColumn(string $table, string $column): bool
    {
        return in_array($column, [
            'provider_id',
            'display_name',
            'status',
            'public_config_json',
            'secret_config_ciphertext',
            'subject_type',
            'subject_id',
            'remote_id',
            'reference',
            'currency',
            'idempotency_key',
            'request_hash',
            'metadata_json',
            'reason',
            'external_event_id',
            'payload_hash',
            'token_hash',
            'event_type',
            'principal_type',
            'principal_id',
        ], true) || $this->isTimestampColumn($table, $column);
    }

    private function normalizeIntegerValue(string $table, string $column, mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $qualified = $table . '.' . $column;
        $allowsZero = in_array($qualified, [
            'cms_payment_authorizations.max_uses',
            'cms_payment_authorizations.used_count',
        ], true);
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match($allowsZero ? '/^(0|[1-9][0-9]{0,17})$/' : '/^[1-9][0-9]{0,17}$/', $value) === 1) {
            $integer = (int) $value;
        } else {
            throw new ExportException('Core payment ledger integer column is invalid: ' . $column);
        }

        if ($integer < ($allowsZero ? 0 : 1)) {
            throw new ExportException('Core payment ledger integer column is out of range: ' . $column);
        }

        return $integer;
    }

    private function isDuplicate(PDOException $exception): bool
    {
        $code = (string) $exception->getCode();

        return $code === '23000' || $code === '23505' || str_contains(strtolower($exception->getMessage()), 'unique');
    }

    /** @param array<string,mixed> $row */
    private function safeRowContext(string $table, int $index, array $row): string
    {
        $parts = ['table=' . $this->safeDiagnosticToken($table), 'row=' . (string) $index];
        foreach (self::RESTORE_KEYS[$table] ?? [] as $key) {
            $parts[] = $this->safeDiagnosticToken($key) . '=' . $this->safeDiagnosticValue($row[$key] ?? null);
        }

        return implode(' ', $parts);
    }

    private function safeExceptionMessage(\Throwable $exception): string
    {
        if ($exception instanceof ExportException) {
            return $this->safeDiagnosticText($exception->getMessage(), 500);
        }

        return $this->safeDiagnosticToken(get_class($exception));
    }

    private function safeDiagnosticToken(string $value): string
    {
        return preg_match('/^[A-Za-z0-9_.:-]{1,191}$/', $value) === 1 ? $value : '[invalid]';
    }

    private function safeDiagnosticValue(mixed $value): string
    {
        if ($value === null) {
            return '[null]';
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return $this->safeDiagnosticToken((string) $value);
        }
        if (!is_string($value)) {
            return '[' . get_debug_type($value) . ']';
        }

        return $this->safeDiagnosticText($value, 191);
    }

    private function safeDiagnosticText(string $value, int $maxBytes): string
    {
        $pattern = '/(?:bearer\s+|payment_token=|sk_[A-Za-z0-9_=-]+|api[_-]?key=|access[_-]?key=|secret=|signature=)/i';
        $decodedValue = rawurldecode($value);
        if (
            $value === ''
            || $value !== trim($value)
            || strlen($value) > $maxBytes
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            || preg_match($pattern, $value) === 1
            || $decodedValue !== trim($decodedValue)
            || preg_match('/[\x00-\x1F\x7F]/', $decodedValue) === 1
            || preg_match($pattern, $decodedValue) === 1
        ) {
            return '[invalid]';
        }

        return $value;
    }

    private function recordExists(string $table, string $column, int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM ' . $this->quoteIdentifier($table)
            . ' WHERE ' . $this->quoteIdentifier($column) . ' = ? LIMIT 1'
        );
        $stmt->bindValue(1, $id, PDO::PARAM_INT);
        $stmt->execute();
        $exists = $stmt->fetchColumn() !== false;
        $stmt->closeCursor();

        return $exists;
    }

    /** @return array<string,mixed> */
    private function paymentForRelation(?int $paymentId, string $message): array
    {
        if ($paymentId === null) {
            throw new ExportException($message);
        }
        $stmt = $this->pdo->prepare('SELECT * FROM cms_payments WHERE id = ? LIMIT 1');
        $stmt->bindValue(1, $paymentId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (!is_array($row)) {
            throw new ExportException($message);
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private function authorizationForRelation(?int $authorizationId, string $message): array
    {
        if ($authorizationId === null) {
            throw new ExportException($message);
        }
        $stmt = $this->pdo->prepare('SELECT * FROM cms_payment_authorizations WHERE id = ? LIMIT 1');
        $stmt->bindValue(1, $authorizationId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (!is_array($row)) {
            throw new ExportException($message);
        }

        return $row;
    }

    /** @param list<string> $columns @param array<string,mixed> $row */
    private function existingRowMatches(string $table, array $columns, array $row): bool
    {
        $keys = self::RESTORE_KEYS[$table] ?? [];
        if ($keys === []) {
            return false;
        }

        $where = [];
        $params = [];
        foreach ($keys as $key) {
            $where[] = $this->quoteIdentifier($key) . ' = ?';
            $params[] = $this->normalizeValue($table, $key, array_key_exists($key, $row) ? $row[$key] : null);
        }

        $stmt = $this->pdo->prepare(
            'SELECT ' . implode(', ', array_map([$this, 'quoteIdentifier'], $columns))
            . ' FROM ' . $this->quoteIdentifier($table)
            . ' WHERE ' . implode(' AND ', $where)
            . ' LIMIT 1'
        );
        foreach ($params as $position => $value) {
            $stmt->bindValue($position + 1, $value, $this->pdoType($value));
        }
        $stmt->execute();
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (!is_array($existing)) {
            return false;
        }

        foreach ($columns as $column) {
            $incoming = $this->normalizeValue($table, $column, array_key_exists($column, $row) ? $row[$column] : null);
            $current = $this->normalizeValue($table, $column, $existing[$column] ?? null);
            if ($incoming === null || $current === null) {
                if ($incoming !== $current) {
                    return false;
                }
                continue;
            }
            if ((string) $incoming !== (string) $current) {
                return false;
            }
        }

        return true;
    }

    private function pdoType(mixed $value): int
    {
        if ($value === null) {
            return PDO::PARAM_NULL;
        }
        if (is_int($value)) {
            return PDO::PARAM_INT;
        }

        return PDO::PARAM_STR;
    }

    private function quoteIdentifier(string $identifier): string
    {
        if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            return '`' . str_replace('`', '``', $identifier) . '`';
        }

        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function emptyStringMeansNull(string $table, string $column): bool
    {
        return in_array($table . '.' . $column, [
            'cms_payments.remote_id',
            'cms_payments.reference',
            'cms_payments.authorized_at',
            'cms_payments.paid_at',
            'cms_payments.failed_at',
            'cms_payments.cancelled_at',
            'cms_payment_refunds.remote_id',
            'cms_payment_refunds.completed_at',
            'cms_payment_refunds.failed_at',
            'cms_payment_refunds.cancelled_at',
            'cms_payment_webhook_receipts.payment_id',
            'cms_payment_webhook_receipts.processed_at',
            'cms_payment_authorizations.revoked_at',
            'cms_payment_authorizations.last_used_at',
            'cms_payment_entitlements.source_authorization_id',
            'cms_payment_entitlements.expires_at',
            'cms_payment_entitlements.revoked_at',
        ], true);
    }
}
