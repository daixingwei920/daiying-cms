<?php

declare(strict_types=1);

namespace Cms\Core\Payment;

use PDO;
use Throwable;

final class PaymentAttemptDiagnosticsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,mixed> $data */
    public function record(array $data): void
    {
        if (!$this->tableAvailable()) {
            return;
        }

        $now = gmdate('c');
        $metadata = $data['metadata'] ?? [];
        if (!is_array($metadata)) {
            $metadata = [];
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_payment_attempt_diagnostics (
                attempt_id, subject_type, subject_id, provider_id, amount_minor, currency,
                stage, status, http_status, provider_error_type, provider_error_code,
                provider_request_id, safe_error_message, metadata_json, created_at
            ) VALUES (
                :attempt_id, :subject_type, :subject_id, :provider_id, :amount_minor, :currency,
                :stage, :status, :http_status, :provider_error_type, :provider_error_code,
                :provider_request_id, :safe_error_message, :metadata_json, :created_at
            )'
        );
        $stmt->execute([
            ':attempt_id' => $this->safeId((string) ($data['attempt_id'] ?? hash('sha256', random_bytes(16))), 64),
            ':subject_type' => $this->safeToken((string) ($data['subject_type'] ?? ''), 96),
            ':subject_id' => $this->safeText((string) ($data['subject_id'] ?? ''), 191),
            ':provider_id' => $this->safeToken((string) ($data['provider_id'] ?? ''), 96),
            ':amount_minor' => max(0, (int) ($data['amount_minor'] ?? 0)),
            ':currency' => $this->safeCurrency((string) ($data['currency'] ?? '')),
            ':stage' => $this->safeToken((string) ($data['stage'] ?? 'provider.create'), 64),
            ':status' => $this->safeToken((string) ($data['status'] ?? 'failed'), 32),
            ':http_status' => isset($data['http_status']) ? max(0, (int) $data['http_status']) : null,
            ':provider_error_type' => $this->nullableSafeText($data['provider_error_type'] ?? null, 96),
            ':provider_error_code' => $this->nullableSafeText($data['provider_error_code'] ?? null, 96),
            ':provider_request_id' => $this->nullableSafeText($data['provider_request_id'] ?? null, 191),
            ':safe_error_message' => $this->safeText((string) ($data['safe_error_message'] ?? 'Payment provider request failed.'), 512),
            ':metadata_json' => json_encode($this->safeMetadata($metadata), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':created_at' => $now,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function recentFailures(int $limit = 10): array
    {
        if (!$this->tableAvailable()) {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $stmt = $this->pdo->query(
            'SELECT * FROM cms_payment_attempt_diagnostics ORDER BY id DESC LIMIT ' . $limit
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        return is_array($rows) ? $rows : [];
    }

    private function tableAvailable(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM cms_payment_attempt_diagnostics LIMIT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function safeId(string $value, int $maxLength): string
    {
        $value = trim($value);
        if (preg_match('/^[A-Za-z0-9._:-]{1,' . $maxLength . '}$/', $value) === 1) {
            return $value;
        }

        return substr(hash('sha256', $value), 0, min(64, $maxLength));
    }

    private function safeToken(string $value, int $maxLength): string
    {
        $value = trim($value);
        if (preg_match('/^[A-Za-z0-9._-]{1,' . $maxLength . '}$/', $value) === 1) {
            return $value;
        }

        return 'unknown';
    }

    private function safeCurrency(string $value): string
    {
        $value = strtoupper(trim($value));
        return preg_match('/^[A-Z]{3}$/', $value) === 1 ? $value : 'USD';
    }

    private function nullableSafeText(mixed $value, int $maxLength): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->safeText((string) $value, $maxLength);
    }

    private function safeText(string $value, int $maxLength): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '');
        $value = preg_replace('/(sk|pk|whsec)_(test|live)?_[A-Za-z0-9_=-]{6,}/', '$1_$2_[redacted]', $value) ?? $value;
        $value = preg_replace('/Bearer\s+[A-Za-z0-9._=-]+/i', 'Bearer [redacted]', $value) ?? $value;
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }

        return substr($value, 0, $maxLength);
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    private function safeMetadata(array $metadata): array
    {
        $safe = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || preg_match('/^[A-Za-z0-9._-]{1,64}$/', $key) !== 1) {
                continue;
            }
            $name = strtolower($key);
            if (preg_match('/secret|token|authorization|password|api[_-]?key|private|signature/', $name) === 1) {
                $safe[$key] = '[redacted]';
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $safe[$key] = is_string($value) ? $this->safeText($value, 191) : $value;
            }
        }

        return $safe;
    }
}
