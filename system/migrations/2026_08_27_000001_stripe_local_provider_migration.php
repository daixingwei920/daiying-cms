<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    private const OLD_ID = 'daiying.payment.stripe.local';
    private const NEW_ID = 'official.payment.stripe';

    public function id(): string
    {
        return '2026_08_27_000001_stripe_local_provider_migration';
    }

    public function up(\PDO $pdo): void
    {
        $now = gmdate('c');

        if ($this->tableExists($pdo, 'cms_payment_provider_settings')) {
            $old = $this->paymentProviderSetting($pdo, self::OLD_ID);
            $new = $this->paymentProviderSetting($pdo, self::NEW_ID);

            if ($old !== null && $new === null) {
                $stmt = $pdo->prepare(
                    'INSERT INTO cms_payment_provider_settings
                        (provider_id, display_name, status, public_config_json, secret_config_ciphertext, created_at, updated_at)
                     VALUES
                        (:provider_id, :display_name, :status, :public_config_json, :secret_config_ciphertext, :created_at, :updated_at)'
                );
                $stmt->execute([
                    ':provider_id' => self::NEW_ID,
                    ':display_name' => 'Stripe 官方支付插件',
                    ':status' => (string) ($old['status'] ?? 'disabled'),
                    ':public_config_json' => (string) ($old['public_config_json'] ?? '{}'),
                    ':secret_config_ciphertext' => (string) ($old['secret_config_ciphertext'] ?? ''),
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);

                $pdo->prepare('UPDATE cms_payment_provider_settings SET status = :status, updated_at = :updated_at WHERE provider_id = :provider_id')
                    ->execute([':provider_id' => self::OLD_ID, ':status' => 'disabled', ':updated_at' => $now]);

                $this->audit($pdo, 'payment.stripe_local_provider.migrated', [
                    'from_provider_id' => self::OLD_ID,
                    'to_provider_id' => self::NEW_ID,
                    'secrets_migrated' => (string) ($old['secret_config_ciphertext'] ?? '') !== '',
                    'secret_values_redacted' => true,
                ]);
            } elseif ($old !== null) {
                $this->audit($pdo, 'payment.stripe_local_provider.skipped', [
                    'from_provider_id' => self::OLD_ID,
                    'to_provider_id' => self::NEW_ID,
                    'reason' => 'official_provider_settings_already_exist',
                    'secret_values_redacted' => true,
                ]);
            }
        }

        if ($this->tableExists($pdo, 'cms_plugins')) {
            $columns = $this->columns($pdo, 'cms_plugins');
            $assignments = ['status = :status', 'updated_at = :updated_at'];
            $params = [
                ':plugin_id' => self::OLD_ID,
                ':status' => 'Disabled',
                ':updated_at' => $now,
            ];
            if (in_array('last_error', $columns, true)) {
                $assignments[] = 'last_error = :last_error';
                $params[':last_error'] = '已迁移到官方支付插件 official.payment.stripe；旧插件代码未删除，数据已保留。';
            }
            if (in_array('source', $columns, true)) {
                $assignments[] = 'source = :source';
                $params[':source'] = 'local_legacy';
            }
            $pdo->prepare('UPDATE cms_plugins SET ' . implode(', ', $assignments) . ' WHERE plugin_id = :plugin_id')
                ->execute($params);
        }
    }

    /** @return array<string,mixed>|null */
    private function paymentProviderSetting(\PDO $pdo, string $providerId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM cms_payment_provider_settings WHERE provider_id = :provider_id ORDER BY updated_at DESC, id DESC LIMIT 1');
        $stmt->execute([':provider_id' => $providerId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $context */
    private function audit(\PDO $pdo, string $action, array $context): void
    {
        if (!$this->tableExists($pdo, 'cms_audit_logs')) {
            return;
        }
        $columns = $this->columns($pdo, 'cms_audit_logs');
        if (!in_array('actor_type', $columns, true) || !in_array('action', $columns, true) || !in_array('context_json', $columns, true)) {
            return;
        }
        $now = gmdate('c');
        $insertColumns = ['actor_type', 'actor_id', 'action', 'context_json', 'created_at'];
        $values = [':actor_type', ':actor_id', ':action', ':context_json', ':created_at'];
        $params = [
            ':actor_type' => 'system',
            ':actor_id' => 0,
            ':action' => $action,
            ':context_json' => json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':created_at' => $now,
        ];
        if (in_array('ip', $columns, true)) {
            $insertColumns[] = 'ip';
            $values[] = ':ip';
            $params[':ip'] = '';
        }
        $pdo->prepare('INSERT INTO cms_audit_logs (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $values) . ')')
            ->execute($params);
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table");
            $stmt->execute([':table' => $table]);
            return $stmt->fetchColumn() !== false;
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
        $stmt->execute([':table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** @return list<string> */
    private function columns(\PDO $pdo, string $table): array
    {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return array_map(static fn (array $row): string => (string) $row['name'], $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(\PDO::FETCH_ASSOC));
        }

        return array_map(static fn (array $row): string => (string) ($row['Field'] ?? ''), $pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll(\PDO::FETCH_ASSOC));
    }
};
