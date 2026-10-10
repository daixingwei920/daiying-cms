<?php

declare(strict_types=1);

namespace Cms\Core\Migration;

use PDO;
use ReflectionClass;
use RuntimeException;

/** Exact, frozen Core migration adapters. No SQL rewriting or unknown migration fallback. */
final class MysqlHistoricalMigrationDialect
{
    private const CHECKSUMS = [
        '2026_10_03_000003_seo_search_engine_p3' => '8880ff45d607b92f43cf1592d777a5c8e562869cb208816b1f5d8c426ff5be49',
        '2026_09_08_000006_ai_foundation_v1' => 'bc37b3fc993c0095885fee21066c5fa59b8cd4034020e92b9a35ad9a5caa1783',
        '2026_08_30_000001_cms_site_license_client_schema' => 'b5e8b1eade5d8663235991820506aa71b242b8a3cb5b4481ac5a2cc50cc18a8b',
        '2026_08_30_000002_review_submission_client_schema' => 'e1415d9cffdfc7f08ebf5a0437308a61d048c685f3139ad16f7324d6768b74e5',
    ];

    public static function run(PDO $pdo, MigrationInterface $migration): bool
    {
        $id = $migration->id();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || !isset(self::CHECKSUMS[$id])) {
            return false;
        }
        $file = (new ReflectionClass($migration))->getFileName();
        if (!$file || basename($file) !== $id . '.php' || !hash_equals(self::CHECKSUMS[$id], hash_file('sha256', $file) ?: '')) {
            throw new RuntimeException('Frozen MySQL migration source checksum mismatch: ' . $id);
        }
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        if (stripos($version, 'MariaDB') !== false) {
            return false; // Preserve the existing MariaDB path; this adapter is MySQL-only.
        }
        if (version_compare($version, '8.0.13', '<')) {
            throw new RuntimeException('Historical MySQL dialect requires MySQL >= 8.0.13.');
        }
        if ($id === '2026_10_03_000003_seo_search_engine_p3') {
            self::seoMetrics($pdo);
        } elseif ($id === '2026_09_08_000006_ai_foundation_v1') {
            self::aiFoundation($pdo);
        } elseif ($id === '2026_08_30_000001_cms_site_license_client_schema') {
            self::table($pdo, 'cms_site_licenses', [
                'id' => 'INTEGER PRIMARY KEY AUTO_INCREMENT',
                'product_id' => 'VARCHAR(191) NOT NULL UNIQUE',
                'license_key_hash' => 'VARCHAR(64) NOT NULL',
                'license_key_mask' => 'VARCHAR(64) NOT NULL',
                'license_key_credential' => "VARCHAR(191) NOT NULL DEFAULT ''",
                'status' => 'VARCHAR(32) NOT NULL',
                'update_until' => 'VARCHAR(64) NULL',
                'activation_payload_json' => 'LONGTEXT NOT NULL',
                'activated_at' => 'VARCHAR(64) NOT NULL',
                'updated_at' => 'VARCHAR(64) NOT NULL',
            ]);
        } else {
            self::table($pdo, 'cms_review_submissions', [
                'id' => 'INTEGER PRIMARY KEY AUTO_INCREMENT',
                'submission_id' => 'VARCHAR(191) NOT NULL UNIQUE',
                'product_id' => 'VARCHAR(191) NOT NULL',
                'package_type' => 'VARCHAR(32) NOT NULL',
                'version' => 'VARCHAR(64) NOT NULL',
                'developer_name' => 'TEXT NOT NULL',
                'developer_email' => 'TEXT NOT NULL',
                'developer_url' => "TEXT NOT NULL DEFAULT ('')",
                'purchase_url' => "TEXT NOT NULL DEFAULT ('')",
                'support_url' => "TEXT NOT NULL DEFAULT ('')",
                'description' => "TEXT NOT NULL DEFAULT ('')",
                'previous_submission_id' => "VARCHAR(191) NOT NULL DEFAULT ''",
                'status' => 'VARCHAR(32) NOT NULL',
                'remote_report_json' => "TEXT NOT NULL DEFAULT ('{}')",
                'created_at' => 'VARCHAR(64) NOT NULL',
                'updated_at' => 'VARCHAR(64) NOT NULL',
            ]);
            self::index($pdo, 'cms_review_submissions', 'idx_cms_review_submissions_status', 'status');
            self::index($pdo, 'cms_review_submissions', 'idx_cms_review_submissions_product', 'product_id');
        }
        return true;
    }

    private static function seoMetrics(PDO $pdo): void
    {
        $stmt = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_seo_keyword_metrics'");
        if ((int) $stmt->fetchColumn() === 0) {
            return;
        }
        $columns = $pdo->query('SHOW COLUMNS FROM cms_seo_keyword_metrics')->fetchAll(PDO::FETCH_ASSOC);
        $url = array_values(array_filter($columns, static fn (array $row): bool => $row['Field'] === 'url_path'));
        if ($url === []) {
            $pdo->exec("ALTER TABLE cms_seo_keyword_metrics ADD COLUMN url_path VARCHAR(512) NOT NULL DEFAULT ''");
        } elseif ($url[0]['Type'] !== 'varchar(512)' || $url[0]['Null'] !== 'NO') {
            throw new RuntimeException('Existing MySQL SEO url_path is incompatible.');
        }
        // A non-unique prefix index preserves the full 512-character value and exact SQL filtering.
        $rows = $pdo->query("SELECT COLUMN_NAME, SUB_PART, NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_seo_keyword_metrics' AND INDEX_NAME = 'cms_seo_keyword_metrics_p3_lookup_idx' ORDER BY SEQ_IN_INDEX")->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            $pdo->exec('CREATE INDEX cms_seo_keyword_metrics_p3_lookup_idx ON cms_seo_keyword_metrics (keyword, url_path(191), search_engine, period_start, period_end, source)');
        } elseif (array_column($rows, 'COLUMN_NAME') !== ['keyword', 'url_path', 'search_engine', 'period_start', 'period_end', 'source']
            || array_column($rows, 'SUB_PART') !== [null, 191, null, null, null, null]
            || array_filter($rows, static fn (array $row): bool => (int) $row['NON_UNIQUE'] !== 1) !== []) {
            throw new RuntimeException('Existing MySQL SEO lookup index is incompatible.');
        }
    }

    private static function aiFoundation(PDO $pdo): void
    {
        // Explicit MySQL implementation of the checksum-pinned migration.

        self::table($pdo, 'cms_ai_usage_ledger', [
            'id' => 'INTEGER PRIMARY KEY AUTO_INCREMENT',
            'request_id' => 'VARCHAR(64) NOT NULL',
            'provider' => 'VARCHAR(64) NOT NULL',
            'model' => 'VARCHAR(191) NOT NULL',
            'plugin_id' => 'VARCHAR(96) NOT NULL DEFAULT "core"',
            'operation' => 'VARCHAR(96) NOT NULL DEFAULT "chat"',
            'input_tokens' => 'INTEGER NOT NULL DEFAULT 0',
            'output_tokens' => 'INTEGER NOT NULL DEFAULT 0',
            'request_count' => 'INTEGER NOT NULL DEFAULT 1',
            'estimated_cost' => 'DECIMAL(12,6) NOT NULL DEFAULT 0',
            'status' => 'VARCHAR(32) NOT NULL DEFAULT "success"',
            'error_reason' => 'VARCHAR(191) NOT NULL DEFAULT ""',
            'created_at' => 'VARCHAR(64) NOT NULL',
        ]);
        self::table($pdo, 'cms_ai_quota_policies', [
            'id' => 'INTEGER PRIMARY KEY AUTO_INCREMENT',
            'scope_type' => 'VARCHAR(32) NOT NULL DEFAULT "global"',
            'scope_id' => 'VARCHAR(96) NOT NULL DEFAULT ""',
            'provider' => 'VARCHAR(64) NOT NULL DEFAULT ""',
            'model' => 'VARCHAR(191) NOT NULL DEFAULT ""',
            'operation' => 'VARCHAR(96) NOT NULL DEFAULT ""',
            'daily_request_limit' => 'INTEGER NOT NULL DEFAULT 0',
            'monthly_request_limit' => 'INTEGER NOT NULL DEFAULT 0',
            'daily_token_limit' => 'INTEGER NOT NULL DEFAULT 0',
            'monthly_token_limit' => 'INTEGER NOT NULL DEFAULT 0',
            'status' => 'VARCHAR(32) NOT NULL DEFAULT "disabled"',
            'created_at' => 'VARCHAR(64) NOT NULL',
            'updated_at' => 'VARCHAR(64) NOT NULL',
        ]);
        self::table($pdo, 'cms_ai_jobs', [
            'id' => 'INTEGER PRIMARY KEY AUTO_INCREMENT',
            'job_id' => 'VARCHAR(64) NOT NULL',
            'queue_job_id' => 'INTEGER NOT NULL DEFAULT 0',
            'owner_plugin' => 'VARCHAR(96) NOT NULL DEFAULT "core"',
            'operation' => 'VARCHAR(96) NOT NULL DEFAULT "chat"',
            'status' => 'VARCHAR(32) NOT NULL DEFAULT "pending"',
            'progress_percent' => 'INTEGER NOT NULL DEFAULT 0',
            'last_error' => 'LONGTEXT NULL',
            'completed_at' => 'VARCHAR(64) NULL',
            'created_at' => 'VARCHAR(64) NOT NULL',
            'updated_at' => 'VARCHAR(64) NOT NULL',
        ]);
        self::table($pdo, 'cms_ai_prompts', [
            'id' => 'INTEGER PRIMARY KEY AUTO_INCREMENT',
            'prompt_id' => 'VARCHAR(191) NOT NULL',
            'version' => 'VARCHAR(32) NOT NULL DEFAULT "1.0"',
            'owner_plugin' => 'VARCHAR(96) NOT NULL DEFAULT "core"',
            'language' => 'VARCHAR(32) NOT NULL DEFAULT "default"',
            'variables_json' => 'LONGTEXT NOT NULL',
            'template' => 'LONGTEXT NOT NULL',
            'status' => 'VARCHAR(32) NOT NULL DEFAULT "enabled"',
            'created_at' => 'VARCHAR(64) NOT NULL',
            'updated_at' => 'VARCHAR(64) NOT NULL',
        ]);
        self::table($pdo, 'cms_ai_audit_events', [
            'id' => 'INTEGER PRIMARY KEY AUTO_INCREMENT',
            'request_id' => 'VARCHAR(64) NOT NULL DEFAULT ""',
            'agent_id' => 'VARCHAR(191) NOT NULL DEFAULT ""',
            'provider' => 'VARCHAR(64) NOT NULL DEFAULT ""',
            'model' => 'VARCHAR(191) NOT NULL DEFAULT ""',
            'tool_id' => 'VARCHAR(191) NOT NULL DEFAULT ""',
            'plugin_id' => 'VARCHAR(96) NOT NULL DEFAULT "core"',
            'action' => 'VARCHAR(191) NOT NULL',
            'target_type' => 'VARCHAR(96) NOT NULL DEFAULT ""',
            'target_id' => 'VARCHAR(96) NOT NULL DEFAULT ""',
            'result' => 'VARCHAR(32) NOT NULL DEFAULT ""',
            'user_id' => 'INTEGER NULL',
            'risk_level' => 'VARCHAR(32) NOT NULL DEFAULT ""',
            'before_json' => 'LONGTEXT NULL',
            'after_json' => 'LONGTEXT NULL',
            'created_at' => 'VARCHAR(64) NOT NULL',
        ]);

        self::index($pdo, 'cms_ai_usage_ledger', 'idx_ai_usage_request', 'request_id');
        self::index($pdo, 'cms_ai_usage_ledger', 'idx_ai_usage_plugin_day', 'plugin_id, created_at');
        self::index($pdo, 'cms_ai_jobs', 'idx_ai_jobs_job_id', 'job_id');
        self::index($pdo, 'cms_ai_prompts', 'idx_ai_prompts_lookup', 'prompt_id, version, language');
        self::index($pdo, 'cms_ai_audit_events', 'idx_ai_audit_request', 'request_id');
    }

    /** @param array<string,string> $columns Static trusted definitions only. */
    private static function table(PDO $pdo, string $table, array $columns): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . $table . ' (' . implode(', ', array_map(
            static fn (string $name, string $definition): string => $name . ' ' . $definition,
            array_keys($columns), array_values($columns)
        )) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $stmt = $pdo->prepare('SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
        $stmt->execute([':table' => $table]);
        if (strtoupper((string) $stmt->fetchColumn()) !== 'INNODB') {
            throw new RuntimeException('MySQL migration table must use InnoDB: ' . $table);
        }
        // IF NOT EXISTS is not evidence that an interrupted/foreign schema is compatible.
        $actual = [];
        foreach ($pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $actual[$row['Field']] = $row;
        }
        foreach ($columns as $name => $definition) {
            $row = $actual[$name] ?? null;
            $type = strtolower(strtok($definition, ' '));
            $type = $type === 'integer' ? 'int' : $type;
            $nullable = str_contains($definition, 'NOT NULL') || str_contains($definition, 'PRIMARY KEY') ? 'NO' : 'YES';
            if (!$row || strtolower($row['Type']) !== $type || $row['Null'] !== $nullable
                || (str_contains($definition, 'PRIMARY KEY') && $row['Key'] !== 'PRI')
                || (str_contains($definition, 'UNIQUE') && $row['Key'] !== 'UNI')
                || (str_contains($definition, 'AUTO_INCREMENT') && !str_contains($row['Extra'], 'auto_increment'))) {
                throw new RuntimeException('Existing MySQL migration schema is incompatible: ' . $table . '.' . $name);
            }
        }
    }

    private static function index(PDO $pdo, string $table, string $name, string $column): void
    {
        $stmt = $pdo->prepare('SELECT COLUMN_NAME, NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :name ORDER BY SEQ_IN_INDEX');
        $stmt->execute([':table' => $table, ':name' => $name]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            $pdo->exec('CREATE INDEX ' . $name . ' ON ' . $table . ' (' . $column . ')');
        } elseif (array_column($rows, 'COLUMN_NAME') !== array_map('trim', explode(',', $column))
            || array_filter($rows, static fn (array $row): bool => (int) $row['NON_UNIQUE'] !== 1) !== []) {
            throw new RuntimeException('Existing MySQL migration index is incompatible: ' . $name);
        }
    }
}
