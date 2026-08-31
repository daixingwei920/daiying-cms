<?php

declare(strict_types=1);

namespace Cms\Core\Payment;

use PDO;
final class PaymentProviderSettingsRepository
{
    public const CLEAR_SECRET_VALUE = '__CMS_CLEAR_PAYMENT_PROVIDER_SECRET__';

    private bool $storageReady = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $masterKey,
    ) {
    }

    /** @param array<string,mixed> $publicConfig @param array<string,mixed> $secrets @return array<string,mixed> */
    public function save(string $providerId, string $displayName, string $status, array $publicConfig = [], array $secrets = []): array
    {
        $this->ensureStorage();
        $providerId = $this->normalizeProviderId($providerId);
        $this->prepareProviderRowsForSave($providerId);
        $displayName = $displayName !== '' ? $displayName : $providerId;
        if ($displayName !== trim($displayName) || strlen($displayName) > 191 || preg_match('/[\x00-\x1F\x7F]/', $displayName) === 1 || $this->publicConfigValueContainsSecret($displayName)) {
            throw new PaymentException('Payment provider display name is invalid.');
        }
        $status = $this->normalizedStatus($status);
        if ($status !== 'enabled') {
            unset($publicConfig['default_provider']);
        }
        if (
            $providerId === HostedRedirectPaymentProvider::PROVIDER_ID
            && $status === 'enabled'
            && ($publicConfig['default_provider'] ?? null) === true
        ) {
            $this->assertHostedRedirectCheckoutAvailable($publicConfig);
        }

        $existing = $this->setting($providerId);
        $ciphertext = $secrets !== []
            ? $this->mergedSecretCiphertext($existing, $secrets)
            : $this->preservedSecretCiphertext($existing);

        $now = gmdate('c');
        $publicJson = $this->json($this->safePublicConfig($publicConfig), 'Payment provider public config JSON is invalid.');
        $payload = [
            ':provider_id' => $providerId,
            ':display_name' => $displayName,
            ':status' => $status,
            ':public_config_json' => $publicJson,
            ':secret_config_ciphertext' => $ciphertext,
            ':updated_at' => $now,
        ];
        $legacyWritePayload = $this->legacyWritePayload($status, $publicConfig, $publicJson);

        if ($existing === null) {
            $columns = [
                'provider_id',
                'display_name',
                'status',
                'public_config_json',
                'secret_config_ciphertext',
                'created_at',
                'updated_at',
            ];
            $values = [
                ':provider_id',
                ':display_name',
                ':status',
                ':public_config_json',
                ':secret_config_ciphertext',
                ':created_at',
                ':updated_at',
            ];
            foreach ($legacyWritePayload as $column => $_value) {
                $columns[] = $column;
                $values[] = ':legacy_' . $column;
            }
            $stmt = $this->pdo->prepare(
                'INSERT INTO cms_payment_provider_settings
                    (' . implode(', ', $columns) . ')
                 VALUES
                    (' . implode(', ', $values) . ')'
            );
            $stmt->execute($payload + [':created_at' => $now] + $this->legacySqlParams($legacyWritePayload));
        } else {
            $assignments = [
                'display_name = :display_name',
                'status = :status',
                'public_config_json = :public_config_json',
                'secret_config_ciphertext = :secret_config_ciphertext',
                'updated_at = :updated_at',
            ];
            foreach ($legacyWritePayload as $column => $_value) {
                $assignments[] = $column . ' = :legacy_' . $column;
            }
            $stmt = $this->pdo->prepare(
                'UPDATE cms_payment_provider_settings
                 SET ' . implode(', ', $assignments) . '
                 WHERE provider_id = :provider_id'
            );
            $stmt->execute($payload + $this->legacySqlParams($legacyWritePayload));
        }
        $this->deleteDuplicateRows($providerId);

        return $this->setting($providerId) ?? [];
    }

    private function prepareProviderRowsForSave(string $providerId): void
    {
        $this->deleteDuplicateRows($providerId);
        $this->syncLegacyStorageMirrors($this->tableColumns('cms_payment_provider_settings'));
    }

    private function normalizedStatus(string $status): string
    {
        if (!in_array($status, ['enabled', 'disabled'], true)) {
            throw new PaymentException('Payment provider setting status is invalid.');
        }

        return $status;
    }

    /** @return array<string,mixed> */
    public function setDefaultProvider(string $providerId): array
    {
        $this->ensureStorage();
        $providerId = $this->normalizeProviderId($providerId);
        $target = $this->setting($providerId);
        if ($target === null || (string) ($target['status'] ?? '') !== 'enabled') {
            throw new PaymentException('Default payment provider must be enabled.');
        }
        $provider = PaymentProviderRegistry::get($providerId);
        if ($provider === null || !in_array('payment.create', $provider->capabilities(), true)) {
            throw new PaymentException('Default payment provider must support checkout.');
        }
        $targetPublic = $this->storedPublicConfig((string) ($target['public_config_json'] ?? '{}'));
        if ($providerId === HostedRedirectPaymentProvider::PROVIDER_ID) {
            $this->assertHostedRedirectCheckoutAvailable($targetPublic);
        }

        foreach ($this->all() as $setting) {
            try {
                $currentProviderId = $this->normalizeProviderId((string) ($setting['provider_id'] ?? ''));
            } catch (PaymentException) {
                continue;
            }
            try {
                $public = $this->storedPublicConfig((string) ($setting['public_config_json'] ?? '{}'));
            } catch (PaymentException) {
                continue;
            }
            if ($currentProviderId === $providerId) {
                $public['default_provider'] = true;
            } else {
                unset($public['default_provider']);
            }

            $publicJson = $this->json($this->safePublicConfig($public), 'Payment provider public config JSON is invalid.');
            $legacyWritePayload = $this->legacyWritePayload(
                (string) ($setting['status'] ?? 'disabled'),
                $public,
                $publicJson,
            );
            $assignments = [
                'public_config_json = :public_config_json',
                'updated_at = :updated_at',
            ];
            foreach ($legacyWritePayload as $column => $_value) {
                $assignments[] = $column . ' = :legacy_' . $column;
            }
            $stmt = $this->pdo->prepare(
                'UPDATE cms_payment_provider_settings
                 SET ' . implode(', ', $assignments) . '
                 WHERE provider_id = :provider_id'
            );
            $stmt->execute([
                ':provider_id' => $currentProviderId,
                ':public_config_json' => $publicJson,
                ':updated_at' => gmdate('c'),
            ] + $this->legacySqlParams($legacyWritePayload));
        }

        return $this->setting($providerId) ?? [];
    }

    /** @return array<string,mixed>|null */
    public function setting(string $providerId): ?array
    {
        $this->ensureStorage();
        $providerId = $this->normalizeProviderId($providerId);
        $stmt = $this->pdo->prepare('SELECT * FROM cms_payment_provider_settings WHERE provider_id = :provider_id ORDER BY updated_at DESC, id DESC LIMIT 1');
        $stmt->execute([':provider_id' => $providerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function normalizeProviderId(string $providerId): string
    {
        try {
            $normalized = PaymentProviderRegistry::normalize($providerId);
        } catch (\Throwable) {
            throw new PaymentException('Payment provider id is invalid.');
        }
        if ($providerId !== $normalized) {
            throw new PaymentException('Payment provider id is invalid.');
        }

        return $normalized;
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $this->ensureStorage();
        $stmt = $this->pdo->query('SELECT * FROM cms_payment_provider_settings ORDER BY provider_id ASC, updated_at ASC, id ASC');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $deduped = [];
        foreach ($rows as $row) {
            try {
                $providerId = $this->normalizeProviderId((string) ($row['provider_id'] ?? ''));
            } catch (PaymentException) {
                continue;
            }
            $row['provider_id'] = $providerId;
            $deduped[$providerId] = $row;
        }

        ksort($deduped);

        return array_values($deduped);
    }

    /** @return array<string,string> */
    public function secrets(string $providerId): array
    {
        $this->ensureStorage();
        $setting = $this->setting($providerId);
        if ($setting === null) {
            return [];
        }

        return $this->decryptSecrets((string) $setting['secret_config_ciphertext']);
    }

    /** @return array<string,string> */
    public function maskedSecrets(string $providerId): array
    {
        $masked = [];
        foreach ($this->secrets($providerId) as $key => $_value) {
            $masked[$key] = '[configured]';
        }

        return $masked;
    }

    /** @return array{row_count:int,duplicate_provider_ids:list<string>,legacy_columns:list<string>} */
    public function storageDiagnostics(): array
    {
        $this->ensureStorage();
        $count = $this->pdo->query('SELECT COUNT(*) FROM cms_payment_provider_settings');
        $duplicates = $this->pdo->query(
            'SELECT provider_id FROM cms_payment_provider_settings
             GROUP BY provider_id HAVING COUNT(*) > 1
             ORDER BY provider_id ASC'
        );
        $legacyColumns = array_values(array_intersect($this->tableColumns('cms_payment_provider_settings'), [
            'enabled',
            'is_enabled',
            'is_default',
            'default_provider',
            'config_json',
            'public_config',
            'settings_json',
            'public_settings_json',
            'instructions',
            'payment_instructions',
        ]));

        return [
            'row_count' => $count ? (int) $count->fetchColumn() : 0,
            'duplicate_provider_ids' => $duplicates ? array_values(array_map('strval', $duplicates->fetchAll(PDO::FETCH_COLUMN))) : [],
            'legacy_columns' => $legacyColumns,
        ];
    }

    private function ensureStorage(): void
    {
        if ($this->storageReady) {
            return;
        }

        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $longText = $driver === 'mysql' ? 'LONGTEXT' : 'TEXT';
        $idColumn = $driver === 'mysql' ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_payment_provider_settings (
                id ' . $idColumn . ',
                provider_id VARCHAR(96) NOT NULL,
                display_name VARCHAR(191) NOT NULL,
                status VARCHAR(32) NOT NULL,
                public_config_json ' . $longText . ' NOT NULL,
                secret_config_ciphertext ' . $longText . ' NOT NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );
        $columns = $this->tableColumns('cms_payment_provider_settings');
        $this->ensureRowIdentityColumn($driver, $columns);
        $columns = $this->tableColumns('cms_payment_provider_settings');
        $required = [
            'provider_id' => 'VARCHAR(96) NOT NULL',
            'display_name' => "VARCHAR(191) NOT NULL DEFAULT ''",
            'status' => "VARCHAR(32) NOT NULL DEFAULT 'disabled'",
            'public_config_json' => $longText . ' NULL',
            'secret_config_ciphertext' => $longText . ' NULL',
            'created_at' => "VARCHAR(64) NOT NULL DEFAULT ''",
            'updated_at' => "VARCHAR(64) NOT NULL DEFAULT ''",
        ];
        foreach ($required as $column => $definition) {
            if (!in_array($column, $columns, true)) {
                $this->pdo->exec('ALTER TABLE cms_payment_provider_settings ADD COLUMN ' . $column . ' ' . $definition);
            }
        }
        $this->migrateLegacyProviderStorage($columns);
        $this->backfillStorageDefaults();
        $this->deleteAllDuplicateRows();
        $this->syncLegacyStorageMirrors($this->tableColumns('cms_payment_provider_settings'));
        $this->createIndexIfMissing('cms_payment_provider_settings_provider_unique', 'CREATE UNIQUE INDEX cms_payment_provider_settings_provider_unique ON cms_payment_provider_settings (provider_id)');
        $this->createIndexIfMissing('cms_payment_provider_settings_status_idx', 'CREATE INDEX cms_payment_provider_settings_status_idx ON cms_payment_provider_settings (status)');
        $this->storageReady = true;
    }

    /** @return list<string> */
    private function tableColumns(string $table): array
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $stmt = $this->pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
            $stmt->execute([':table' => $table]);

            return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        $rows = $this->pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn (array $row): string => (string) ($row['name'] ?? ''), $rows);
    }

    private function backfillStorageDefaults(): void
    {
        $now = gmdate('c');
        $this->backfillRowIdentity();
        $this->pdo->exec("UPDATE cms_payment_provider_settings SET display_name = provider_id WHERE display_name IS NULL OR display_name = ''");
        $this->pdo->exec("UPDATE cms_payment_provider_settings SET status = 'disabled' WHERE status IS NULL OR status NOT IN ('enabled', 'disabled')");
        $this->pdo->exec("UPDATE cms_payment_provider_settings SET public_config_json = '{}' WHERE public_config_json IS NULL OR public_config_json = ''");
        $this->pdo->exec("UPDATE cms_payment_provider_settings SET secret_config_ciphertext = '' WHERE secret_config_ciphertext IS NULL");
        $this->pdo->prepare("UPDATE cms_payment_provider_settings SET created_at = :now WHERE created_at IS NULL OR created_at = ''")->execute([':now' => $now]);
        $this->pdo->prepare("UPDATE cms_payment_provider_settings SET updated_at = :now WHERE updated_at IS NULL OR updated_at = ''")->execute([':now' => $now]);
    }

    /** @param list<string> $columns */
    private function ensureRowIdentityColumn(string $driver, array $columns): void
    {
        if (in_array('id', $columns, true)) {
            return;
        }

        try {
            if ($driver === 'mysql') {
                $this->pdo->exec('ALTER TABLE cms_payment_provider_settings ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST');
                return;
            }
            $this->pdo->exec('ALTER TABLE cms_payment_provider_settings ADD COLUMN id INTEGER');
        } catch (\Throwable) {
            if ($driver === 'mysql') {
                $this->pdo->exec('ALTER TABLE cms_payment_provider_settings ADD COLUMN id BIGINT UNSIGNED NULL FIRST');
                return;
            }
            throw new PaymentException('Payment provider settings storage is invalid.');
        }
    }

    private function backfillRowIdentity(): void
    {
        if (!in_array('id', $this->tableColumns('cms_payment_provider_settings'), true)) {
            throw new PaymentException('Payment provider settings storage is invalid.');
        }
        $max = $this->pdo->query('SELECT MAX(id) FROM cms_payment_provider_settings');
        $next = max(0, $max ? (int) $max->fetchColumn() : 0) + 1;
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->pdo->query('SELECT rowid FROM cms_payment_provider_settings WHERE id IS NULL OR id <= 0 ORDER BY rowid ASC');
            $rowIds = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
            foreach ($rowIds as $rowId) {
                $update = $this->pdo->prepare('UPDATE cms_payment_provider_settings SET id = :id WHERE rowid = :rowid');
                $update->execute([':id' => $next++, ':rowid' => (int) $rowId]);
            }
            return;
        }

        if ($driver === 'mysql') {
            try {
                $this->pdo->exec('SET @cms_payment_provider_settings_row_id := ' . ($next - 1));
                $this->pdo->exec('UPDATE cms_payment_provider_settings SET id = (@cms_payment_provider_settings_row_id := @cms_payment_provider_settings_row_id + 1) WHERE id IS NULL OR id <= 0');
                return;
            } catch (\Throwable) {
                throw new PaymentException('Payment provider settings storage is invalid.');
            }
        }

        $stmt = $this->pdo->query('SELECT provider_id, updated_at FROM cms_payment_provider_settings WHERE id IS NULL OR id <= 0 ORDER BY provider_id ASC, updated_at ASC');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            $update = $this->pdo->prepare('UPDATE cms_payment_provider_settings SET id = :id WHERE provider_id = :provider_id AND updated_at = :updated_at AND (id IS NULL OR id <= 0)');
            $update->execute([':id' => $next++, ':provider_id' => (string) ($row['provider_id'] ?? ''), ':updated_at' => (string) ($row['updated_at'] ?? '')]);
        }
    }

    /** @param list<string> $columns */
    private function migrateLegacyProviderStorage(array $columns): void
    {
        $legacyColumns = array_values(array_intersect($columns, [
            'enabled',
            'is_enabled',
            'is_default',
            'default_provider',
            'config_json',
            'public_config',
            'settings_json',
            'public_settings_json',
            'instructions',
            'payment_instructions',
            'name',
            'title',
        ]));
        if ($legacyColumns === []) {
            return;
        }

        $stmt = $this->pdo->query('SELECT * FROM cms_payment_provider_settings');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            $id = $this->legacyRowId($row['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $providerId = (string) ($row['provider_id'] ?? '');
            $displayName = (string) ($row['display_name'] ?? '');
            if ($displayName === '') {
                $displayName = $this->legacyDisplayName($row, $providerId);
            }
            $status = (string) ($row['status'] ?? '');
            if ($this->legacyTruthy($row['enabled'] ?? null) || $this->legacyTruthy($row['is_enabled'] ?? null)) {
                $status = 'enabled';
            } elseif (!in_array($status, ['enabled', 'disabled'], true)) {
                $status = 'disabled';
            }

            $public = $this->legacyPublicConfig((string) ($row['public_config_json'] ?? '{}'));
            foreach (['public_config', 'config_json', 'settings_json', 'public_settings_json'] as $column) {
                if (array_key_exists($column, $row) && is_string($row[$column]) && trim($row[$column]) !== '') {
                    $public = array_replace($public, $this->legacyPublicConfig((string) $row[$column]));
                }
            }
            $instructions = (string) ($row['payment_instructions'] ?? $row['instructions'] ?? '');
            if ($instructions !== '' && $instructions === trim($instructions) && !array_key_exists('instructions', $public)) {
                $public['instructions'] = $instructions;
            }
            if ($status === 'enabled' && ($this->legacyTruthy($row['is_default'] ?? null) || $this->legacyTruthy($row['default_provider'] ?? null))) {
                $public['default_provider'] = true;
            } elseif ($status !== 'enabled') {
                unset($public['default_provider']);
            }

            $update = $this->pdo->prepare(
                'UPDATE cms_payment_provider_settings
                 SET display_name = :display_name, status = :status, public_config_json = :public_config_json
                 WHERE id = :id'
            );
            $update->execute([
                ':id' => $id,
                ':display_name' => $displayName !== '' ? $displayName : $providerId,
                ':status' => $status,
                ':public_config_json' => $this->json($this->safePublicConfig($public), 'Payment provider public config JSON is invalid.'),
            ]);
        }
    }

    /** @param array<string,mixed> $row */
    private function legacyDisplayName(array $row, string $providerId): string
    {
        foreach (['name', 'title'] as $column) {
            $value = (string) ($row[$column] ?? '');
            if ($value !== '' && $value === trim($value) && strlen($value) <= 191 && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1 && !$this->publicConfigValueContainsSecret($value)) {
                return $value;
            }
        }

        return $providerId;
    }

    private function legacyRowId(mixed $id): ?int
    {
        if (is_int($id)) {
            return $id > 0 ? $id : null;
        }
        if (is_string($id) && preg_match('/^[1-9][0-9]*$/', $id) === 1) {
            return (int) $id;
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function legacyPublicConfig(string $json): array
    {
        $json = trim($json);
        if ($json === '') {
            return [];
        }
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            return [];
        }
        try {
            return $this->safePublicConfig($decoded);
        } catch (PaymentException) {
            return [];
        }
    }

    private function legacyTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }
        if (!is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'on', 'true', 'enabled', 'yes', 'y'], true);
    }

    private function createIndexIfMissing(string $name, string $sql): void
    {
        try {
            $this->pdo->exec($sql);
        } catch (\Throwable) {
        }
    }

    /** @param list<string> $columns */
    private function syncLegacyStorageMirrors(array $columns): void
    {
        $legacyColumns = array_values(array_intersect($columns, [
            'enabled',
            'is_enabled',
            'is_default',
            'default_provider',
            'config_json',
            'public_config',
            'settings_json',
            'public_settings_json',
            'instructions',
            'payment_instructions',
        ]));
        if ($legacyColumns === []) {
            return;
        }
        $stmt = $this->pdo->query('SELECT * FROM cms_payment_provider_settings');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            $id = $this->legacyRowId($row['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $status = in_array((string) ($row['status'] ?? ''), ['enabled', 'disabled'], true)
                ? (string) $row['status']
                : 'disabled';
            try {
                $public = $this->storedPublicConfig((string) ($row['public_config_json'] ?? '{}'));
            } catch (PaymentException) {
                $public = [];
            }
            if ($status !== 'enabled') {
                unset($public['default_provider']);
            }
            $publicJson = $this->json($this->safePublicConfig($public), 'Payment provider public config JSON is invalid.');
            $payload = $this->legacyWritePayloadForColumns($columns, $status, $public, $publicJson);
            if ($payload === []) {
                continue;
            }
            $assignments = [];
            foreach ($payload as $column => $_value) {
                $assignments[] = $column . ' = :legacy_' . $column;
            }
            $update = $this->pdo->prepare('UPDATE cms_payment_provider_settings SET ' . implode(', ', $assignments) . ' WHERE id = :id');
            $update->execute($this->legacySqlParams($payload) + [':id' => $id]);
        }
    }

    /** @param array<string,mixed> $publicConfig @return array<string,int|string> */
    private function legacyWritePayload(string $status, array $publicConfig, string $publicJson): array
    {
        return $this->legacyWritePayloadForColumns($this->tableColumns('cms_payment_provider_settings'), $status, $publicConfig, $publicJson);
    }

    /** @param list<string> $columns @param array<string,mixed> $publicConfig @return array<string,int|string> */
    private function legacyWritePayloadForColumns(array $columns, string $status, array $publicConfig, string $publicJson): array
    {
        $values = [];
        $enabled = $status === 'enabled' ? 1 : 0;
        $default = $enabled === 1 && ($publicConfig['default_provider'] ?? null) === true ? 1 : 0;
        foreach (['enabled', 'is_enabled'] as $column) {
            if (in_array($column, $columns, true)) {
                $values[$column] = $enabled;
            }
        }
        foreach (['is_default', 'default_provider'] as $column) {
            if (in_array($column, $columns, true)) {
                $values[$column] = $default;
            }
        }
        foreach (['config_json', 'public_config', 'settings_json', 'public_settings_json'] as $column) {
            if (in_array($column, $columns, true)) {
                $values[$column] = $publicJson;
            }
        }
        foreach (['instructions', 'payment_instructions'] as $column) {
            if (in_array($column, $columns, true)) {
                $instructions = $publicConfig['instructions'] ?? '';
                $values[$column] = is_string($instructions) ? $instructions : '';
            }
        }

        return $values;
    }

    /** @param array<string,int|string> $payload @return array<string,int|string> */
    private function legacySqlParams(array $payload): array
    {
        $params = [];
        foreach ($payload as $column => $value) {
            $params[':legacy_' . $column] = $value;
        }

        return $params;
    }

    private function deleteAllDuplicateRows(): void
    {
        $stmt = $this->pdo->query(
            'SELECT provider_id FROM cms_payment_provider_settings
             GROUP BY provider_id HAVING COUNT(*) > 1'
        );
        $providerIds = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        foreach ($providerIds as $providerId) {
            if (is_string($providerId) && $providerId !== '') {
                $this->deleteDuplicateRows($providerId);
            }
        }
    }

    private function deleteDuplicateRows(string $providerId): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM cms_payment_provider_settings WHERE provider_id = :provider_id ORDER BY updated_at DESC, id DESC');
        $stmt->execute([':provider_id' => $providerId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $keep = array_shift($ids);
        if ($keep === null || $ids === []) {
            return;
        }
        foreach ($ids as $id) {
            if ($id <= 0 || $id === $keep) {
                continue;
            }
            $delete = $this->pdo->prepare('DELETE FROM cms_payment_provider_settings WHERE id = :id AND provider_id = :provider_id');
            $delete->execute([':id' => $id, ':provider_id' => $providerId]);
        }
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    private function safePublicConfig(array $config): array
    {
        $safe = [];
        foreach ($config as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $key) !== 1) {
                throw new PaymentException('Payment provider public config key is invalid.');
            }
            if (preg_match('/password|secret|token|authorization|signature|auth|api[_-]?key|access[_-]?key|private/i', $key) === 1) {
                throw new PaymentException('Payment provider public config cannot contain secrets.');
            }
            if ($key === 'default_provider' && !is_bool($value) && $value !== null) {
                throw new PaymentException('Payment provider default marker is invalid.');
            }
            if (!(is_scalar($value) || $value === null)) {
                throw new PaymentException('Payment provider public config value is invalid.');
            }
            if (($key === 'return_url_base' || str_contains(strtolower($key), 'url')) && $value !== null && !is_string($value)) {
                throw new PaymentException('Payment provider public config URL is invalid.');
            }
            if (is_string($value) && ($value !== trim($value) || strlen($value) > 4096 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1)) {
                throw new PaymentException('Payment provider public config value is invalid.');
            }
            if (is_string($value) && $this->publicConfigValueContainsSecret($value)) {
                throw new PaymentException('Payment provider public config cannot contain secrets.');
            }
            if ($key === 'return_url_base' && is_string($value) && !$this->safeReturnUrlBase($value)) {
                throw new PaymentException('Payment provider return URL base is invalid.');
            }
            if ($key !== 'return_url_base' && str_contains(strtolower($key), 'url') && is_string($value) && !$this->safePublicUrl($value)) {
                throw new PaymentException('Payment provider public config URL is invalid.');
            }
            $safe[$key] = $value;
        }

        return $safe;
    }

    /** @return array<string,mixed> */
    private function storedPublicConfig(string $json): array
    {
        if ($json === '' || $json !== trim($json)) {
            throw new PaymentException('Payment provider public config JSON is invalid.');
        }
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PaymentException('Payment provider public config JSON is invalid.');
        }
        if (!is_array($decoded)) {
            throw new PaymentException('Payment provider public config JSON is invalid.');
        }
        $canonicalJson = $json === '{}' && $decoded === []
            ? '{}'
            : $this->json($decoded, 'Payment provider public config JSON is invalid.');
        if ($json !== $canonicalJson) {
            throw new PaymentException('Payment provider public config JSON is invalid.');
        }

        return $this->safePublicConfig($decoded);
    }

    /** @param array<string,mixed> $public */
    private function assertHostedRedirectCheckoutAvailable(array $public): void
    {
        $checkoutUrl = (string) ($public['checkout_url'] ?? $public['checkout_base_url'] ?? '');
        if (!$this->isHttpsUrl($checkoutUrl)) {
            throw new PaymentException('Default hosted payment provider checkout URL must use HTTPS.');
        }
        if ($this->hasSensitiveQuery($checkoutUrl)) {
            throw new PaymentException('Default hosted payment provider checkout URL cannot contain sensitive query parameters.');
        }
        $returnUrlBase = (string) ($public['return_url_base'] ?? '');
        if ($returnUrlBase !== '' && !$this->isHttpsUrl($returnUrlBase)) {
            throw new PaymentException('Default hosted payment provider return URL base must use HTTPS.');
        }
        if ($returnUrlBase !== '' && $this->hasQuery($returnUrlBase)) {
            throw new PaymentException('Default hosted payment provider return URL base cannot contain query parameters.');
        }
        if ($returnUrlBase !== '' && $this->hasSensitiveQuery($returnUrlBase)) {
            throw new PaymentException('Default hosted payment provider return URL base cannot contain sensitive query parameters.');
        }
    }

    private function isHttpsUrl(string $url): bool
    {
        if ($url === '' || $url !== trim($url) || strlen($url) > 2048) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && trim((string) ($parts['host'] ?? '')) !== ''
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['fragment']);
    }

    private function safePublicUrl(string $url): bool
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
        if ($this->publicConfigValueContainsSecret(rawurldecode((string) ($parts['path'] ?? '')))) {
            return false;
        }

        return !$this->hasSensitiveQuery($url);
    }

    private function safeReturnUrlBase(string $url): bool
    {
        return $url === '' || ($this->isHttpsUrl($url) && !$this->hasQuery($url));
    }

    private function publicConfigValueContainsSecret(string $value): bool
    {
        $pattern = '/(?:bearer\s+|payment_token=|sk_[A-Za-z0-9_=-]+|api[_-]?key=|access[_-]?key=|secret=|signature=)/i';

        return preg_match($pattern, $value) === 1
            || preg_match($pattern, rawurldecode($value)) === 1;
    }

    private function hasSensitiveQuery(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['query'])) {
            return false;
        }
        parse_str((string) $parts['query'], $query);
        foreach ($query as $key => $value) {
            if (preg_match('/token|secret|signature|authorization|auth|key|password|private/i', (string) $key) === 1) {
                return true;
            }
            if (!is_scalar($value)) {
                return true;
            }
            if ($this->publicConfigValueContainsSecret(rawurldecode((string) $value))) {
                return true;
            }
        }

        return false;
    }

    private function hasQuery(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && isset($parts['query']);
    }

    /** @param array<string,mixed> $secrets */
    private function encryptSecrets(array $secrets): string
    {
        $masterKey = $this->requireMasterKey();
        $clean = [];
        foreach ($secrets as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new PaymentException('Payment provider secret payload is invalid.');
            }
            if ($key === '') {
                throw new PaymentException('Payment provider secret key is invalid.');
            }
            if ($value === '') {
                throw new PaymentException('Payment provider secret value is invalid.');
            }
            $this->assertSecretEntry($key, $value);
            $clean[$key] = $value;
        }

        $nonce = random_bytes(12);
        $plain = $this->json($clean, 'Payment provider secret payload is invalid.');
        $cipher = openssl_encrypt((string) $plain, 'aes-256-gcm', $this->keyBytes($masterKey), OPENSSL_RAW_DATA, $nonce, $tag);
        if (!is_string($cipher)) {
            throw new PaymentException('Unable to encrypt payment provider secrets.');
        }

        return base64_encode($nonce . $tag . $cipher);
    }

    /** @param array<string,mixed>|null $existing */
    private function preservedSecretCiphertext(?array $existing): string
    {
        if ($existing === null) {
            return '';
        }

        $ciphertext = (string) ($existing['secret_config_ciphertext'] ?? '');
        if ($ciphertext === '') {
            return '';
        }
        $this->decryptSecrets($ciphertext);

        return $ciphertext;
    }

    /** @param array<string,mixed>|null $existing @param array<string,mixed> $updates */
    private function mergedSecretCiphertext(?array $existing, array $updates): string
    {
        $merged = [];
        if ($existing !== null) {
            $ciphertext = (string) ($existing['secret_config_ciphertext'] ?? '');
            if ($ciphertext !== '') {
                try {
                    $merged = $this->decryptSecrets($ciphertext);
                } catch (PaymentException) {
                    $merged = [];
                }
            }
        }

        foreach ($updates as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new PaymentException('Payment provider secret payload is invalid.');
            }
            if ($value === self::CLEAR_SECRET_VALUE) {
                unset($merged[$key]);
                continue;
            }
            $merged[$key] = $value;
        }

        return $merged !== [] ? $this->encryptSecrets($merged) : '';
    }

    /** @return array<string,string> */
    private function decryptSecrets(string $payload): array
    {
        if ($payload === '') {
            return [];
        }
        $masterKey = $this->requireMasterKey();
        $raw = base64_decode($payload, true);
        if (!is_string($raw) || strlen($raw) < 28) {
            throw new PaymentException('Payment provider secret ciphertext is invalid.');
        }
        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->keyBytes($masterKey), OPENSSL_RAW_DATA, $nonce, $tag);
        if (!is_string($plain)) {
            throw new PaymentException('Unable to decrypt payment provider secrets.');
        }
        try {
            $decoded = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PaymentException('Payment provider secret payload is invalid.');
        }
        if (!is_array($decoded)) {
            throw new PaymentException('Payment provider secret payload is invalid.');
        }

        $secrets = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new PaymentException('Payment provider secret payload is invalid.');
            }
            if ($key === '' || $value === '') {
                throw new PaymentException('Payment provider secret payload is invalid.');
            }
            $this->assertSecretEntry($key, $value);
            $secrets[$key] = $value;
        }

        return $secrets;
    }

    /** @param array<mixed,mixed> $payload */
    private function json(array $payload, string $failureMessage): string
    {
        try {
            return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PaymentException($failureMessage);
        }
    }

    private function assertSecretEntry(string $key, string $value): void
    {
        if (preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $key) !== 1) {
            throw new PaymentException('Payment provider secret key is invalid.');
        }
        if ($value !== trim($value)) {
            throw new PaymentException('Payment provider secret value is invalid.');
        }
        if (in_array(strtolower($key), ['webhook_secret', 'checkout_secret'], true) && strlen($value) < 16) {
            throw new PaymentException('Payment provider signing secret is too short.');
        }
        if (strlen($value) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new PaymentException('Payment provider secret value is invalid.');
        }
    }

    private function requireMasterKey(): string
    {
        if (
            $this->masterKey === ''
            || $this->masterKey !== trim($this->masterKey)
            || strlen($this->masterKey) < 16
            || preg_match('/[\x00-\x1F\x7F]/', $this->masterKey) === 1
        ) {
            throw new PaymentException('Payment provider secret encryption key is not configured.');
        }

        return $this->masterKey;
    }

    private function keyBytes(string $masterKey): string
    {
        return hash('sha256', $masterKey, true);
    }
}
