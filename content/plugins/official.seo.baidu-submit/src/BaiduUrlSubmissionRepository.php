<?php

declare(strict_types=1);

namespace Official\Seo\BaiduSubmit;

use Cms\Core\Plugin\PluginSecretStore;
use Cms\Core\Security\SecretRedactor;
use PDO;

final class BaiduUrlSubmissionRepository
{
    public const PLUGIN_ID = 'official.seo.baidu-submit';
    private const TOKEN_KEY = 'baidu_url_submit_token';

    public function __construct(
        private readonly PDO $pdo,
        private readonly PluginSecretStore $secrets,
    ) {
    }

    /** @return array{site_url:string,enabled:bool,dedupe_window_seconds:int} */
    public function settings(): array
    {
        $row = $this->pdo->query('SELECT * FROM baidu_url_submission_settings ORDER BY id ASC LIMIT 1')->fetch();
        if (!is_array($row)) {
            return ['site_url' => '', 'enabled' => false, 'dedupe_window_seconds' => 1800];
        }

        return [
            'site_url' => (string) ($row['site_url'] ?? ''),
            'enabled' => (int) ($row['enabled'] ?? 0) === 1,
            'dedupe_window_seconds' => max(60, min(86400, (int) ($row['dedupe_window_seconds'] ?? 1800))),
        ];
    }

    public function saveSettings(string $siteUrl, bool $enabled, int $dedupeWindowSeconds, ?string $token): void
    {
        $now = gmdate('c');
        $existing = $this->pdo->query('SELECT id FROM baidu_url_submission_settings ORDER BY id ASC LIMIT 1')->fetch();
        if (is_array($existing)) {
            $this->pdo->prepare('UPDATE baidu_url_submission_settings SET site_url = :site_url, enabled = :enabled, dedupe_window_seconds = :dedupe, updated_at = :updated_at WHERE id = :id')
                ->execute([
                    ':id' => (int) $existing['id'],
                    ':site_url' => $siteUrl,
                    ':enabled' => $enabled ? 1 : 0,
                    ':dedupe' => max(60, min(86400, $dedupeWindowSeconds)),
                    ':updated_at' => $now,
                ]);
        } else {
            $this->pdo->prepare('INSERT INTO baidu_url_submission_settings (site_url, enabled, dedupe_window_seconds, created_at, updated_at) VALUES (:site_url, :enabled, :dedupe, :created_at, :updated_at)')
                ->execute([
                    ':site_url' => $siteUrl,
                    ':enabled' => $enabled ? 1 : 0,
                    ':dedupe' => max(60, min(86400, $dedupeWindowSeconds)),
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
        }

        if ($token !== null && $token !== '') {
            $this->secrets->set(self::PLUGIN_ID, self::TOKEN_KEY, $token);
        }
    }

    public function token(): ?string
    {
        return $this->secrets->get(self::PLUGIN_ID, self::TOKEN_KEY);
    }

    public function maskedToken(): ?string
    {
        return $this->secrets->masked(self::PLUGIN_ID, self::TOKEN_KEY);
    }

    public function recentlySubmitted(string $url, int $windowSeconds): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM baidu_url_submission_logs WHERE url_hash = :hash AND status IN ('success','submitted','deduped','quota_exhausted') AND created_at >= :since");
        $stmt->execute([
            ':hash' => hash('sha256', $url),
            ':since' => gmdate('c', time() - max(60, $windowSeconds)),
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** @param array<string,mixed>|null $response @param list<string> $notSameSite @param list<string> $notValid */
    public function record(
        string $url,
        string $trigger,
        string $status,
        ?int $httpStatus = null,
        ?int $success = null,
        ?int $remain = null,
        array $notSameSite = [],
        array $notValid = [],
        ?array $response = null,
        ?string $errorSummary = null,
        ?int $contentId = null,
        ?string $contentType = null,
    ): void {
        $this->pdo->prepare('INSERT INTO baidu_url_submission_logs
            (url, url_hash, trigger_type, content_id, content_type, status, http_status, baidu_success, baidu_remain, not_same_site_json, not_valid_json, response_json, error_summary, created_at)
            VALUES
            (:url, :url_hash, :trigger_type, :content_id, :content_type, :status, :http_status, :baidu_success, :baidu_remain, :not_same_site_json, :not_valid_json, :response_json, :error_summary, :created_at)')
            ->execute([
                ':url' => $url,
                ':url_hash' => hash('sha256', $url),
                ':trigger_type' => $trigger,
                ':content_id' => $contentId,
                ':content_type' => $contentType,
                ':status' => $status,
                ':http_status' => $httpStatus,
                ':baidu_success' => $success,
                ':baidu_remain' => $remain,
                ':not_same_site_json' => $this->json($notSameSite),
                ':not_valid_json' => $this->json($notValid),
                ':response_json' => $response === null ? null : $this->json(SecretRedactor::redact($response)),
                ':error_summary' => $errorSummary === null ? null : substr((string) SecretRedactor::redact($errorSummary), 0, 500),
                ':created_at' => gmdate('c'),
            ]);
    }

    /** @return list<array<string,mixed>> */
    public function recentLogs(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM baidu_url_submission_logs ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array{total:int,today:int,last_time:?string} */
    public function stats(): array
    {
        $total = (int) $this->pdo->query("SELECT COUNT(*) FROM baidu_url_submission_logs WHERE status IN ('success','submitted','quota_exhausted')")->fetchColumn();
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM baidu_url_submission_logs WHERE status IN ('success','submitted','quota_exhausted') AND created_at >= :since");
        $stmt->execute([':since' => gmdate('Y-m-d\T00:00:00P')]);
        $today = (int) $stmt->fetchColumn();
        $last = $this->pdo->query("SELECT created_at FROM baidu_url_submission_logs WHERE status IN ('success','submitted','quota_exhausted') ORDER BY id DESC LIMIT 1")->fetchColumn();

        return ['total' => $total, 'today' => $today, 'last_time' => is_string($last) ? $last : null];
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
    }
}
