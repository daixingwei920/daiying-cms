<?php

declare(strict_types=1);

namespace Cms\Core\Export;

use Cms\Core\Payment\PaymentProviderSettingsRepository;
use PDO;
use ZipArchive;

final class ExportPackageBuilder
{
    public const EXPORT_SCHEMA_VERSION = '1.0.0';
    public const PAYMENT_LEDGER_SCHEMA_VERSION = '1.0.0';

    public function __construct(
        private readonly string $rootPath,
        private readonly PDO $pdo,
        private readonly string $coreVersion,
    ) {
    }

    public function build(string $reason = 'manual'): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ExportException('ZipArchive extension is required for export packages.');
        }

        $dir = $this->rootPath . '/storage/exports';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $id = gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
        $zipPath = $dir . '/cms-export-' . $id . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new ExportException('Unable to create export package.');
        }

        $paymentLedger = $this->payments();
        $payloads = [
            'content/content.json' => $this->json($this->contents()),
            'users/admins.json' => $this->json($this->admins()),
            'media/media.json' => $this->json($this->media()),
            'url-map/url-map.json' => $this->json($this->urlMap()),
            'extensions/extensions.json' => $this->json($this->extensions()),
            'payments/payment-ledger.json' => $this->json($paymentLedger),
        ];

        $checksums = [];
        foreach ($payloads as $path => $json) {
            $zip->addFromString($path, $json);
            $checksums[$path] = hash('sha256', $json);
        }
        $mediaFiles = $this->addMediaFiles($zip, $checksums);

        $manifest = [
            'platform_id' => 'php-cms',
            'export_schema_version' => self::EXPORT_SCHEMA_VERSION,
            'core_version' => $this->coreVersion,
            'content_schema_version' => '1.0.0',
            'created_at' => gmdate('c'),
            'reason' => $reason,
            'payloads' => [
                'payments/payment-ledger.json' => [
                    'type' => 'core_payment_ledger',
                    'schema_version' => (string) ($paymentLedger['schema_version'] ?? ''),
                    'exported_at' => (string) ($paymentLedger['exported_at'] ?? ''),
                    'counts' => $paymentLedger['counts'] ?? [],
                ],
            ],
            'media_files' => [
                'count' => count($mediaFiles),
                'files' => $mediaFiles,
            ],
            'checksums' => $checksums,
        ];
        $manifestJson = $this->json($manifest);
        $zip->addFromString('manifest.json', $manifestJson);
        $zip->close();

        return $zipPath;
    }

    /** @return list<array<string, mixed>> */
    private function contents(): array
    {
        return $this->fetchAll('SELECT * FROM cms_contents ORDER BY id');
    }

    /** @return list<array<string, mixed>> */
    private function admins(): array
    {
        $rows = $this->fetchAll('SELECT id, email, display_name, created_at, updated_at FROM cms_admin_users ORDER BY id');

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function media(): array
    {
        return $this->fetchAll('SELECT * FROM cms_media ORDER BY id');
    }

    /** @param array<string,string> $checksums @return list<array{id:int,path:string,sha256:string,byte_size:int}> */
    private function addMediaFiles(ZipArchive $zip, array &$checksums): array
    {
        $rows = $this->fetchAll("SELECT id, storage_provider, relative_path, storage_key, byte_size, sha256_hash, status FROM cms_media WHERE storage_provider = 'local' AND status <> 'Deleted' ORDER BY id");
        $files = [];
        foreach ($rows as $row) {
            $id = $this->positiveInt($row['id'] ?? null);
            $storageKey = $this->safeMediaStorageKey((string) ($row['storage_key'] ?: $row['relative_path'] ?? ''));
            if ($id <= 0 || $storageKey === '') {
                throw new ExportException('Core export media file path is invalid.');
            }
            $absolute = $this->rootPath . '/content/uploads/' . $storageKey;
            if (!is_file($absolute) || is_link($absolute)) {
                throw new ExportException('Core export media file is missing.');
            }
            $hash = hash_file('sha256', $absolute);
            if (!is_string($hash) || $hash === '' || ($row['sha256_hash'] ?? '') !== $hash) {
                throw new ExportException('Core export media file checksum mismatch.');
            }
            $byteSize = filesize($absolute);
            if (!is_int($byteSize) || $byteSize < 0 || (string) $byteSize !== (string) ($row['byte_size'] ?? '')) {
                throw new ExportException('Core export media file size mismatch.');
            }
            $zipPath = 'media/files/' . $storageKey;
            if (!$zip->addFile($absolute, $zipPath)) {
                throw new ExportException('Unable to add media file to export package.');
            }
            $checksums[$zipPath] = $hash;
            $files[] = [
                'id' => $id,
                'path' => $zipPath,
                'sha256' => $hash,
                'byte_size' => $byteSize,
            ];
        }

        return $files;
    }

    /** @return list<array<string, mixed>> */
    private function urlMap(): array
    {
        return $this->fetchAll('SELECT * FROM cms_url_mappings ORDER BY id');
    }

    /** @return array<string, mixed> */
    private function extensions(): array
    {
        return [
            'plugins' => $this->fetchAll('SELECT plugin_id, name, version, status, trust_level, capabilities_json FROM cms_plugins ORDER BY plugin_id'),
            'market_sources' => $this->fetchAll('SELECT extension_id, extension_type, source, market_id, version, installed_at, metadata_json FROM cms_extension_sources ORDER BY id'),
            'market_install_logs' => $this->fetchAll('SELECT market_id, extension_id, extension_type, status, plan_json, created_at FROM cms_market_install_logs ORDER BY id'),
            'dormant_data' => $this->fetchAll('SELECT extension_id, data_type, data_key, payload, status, created_at, updated_at FROM cms_core_extension_data ORDER BY id'),
        ];
    }

    /** @return array<string, mixed> */
    private function payments(): array
    {
        $sections = [
            'payments' => $this->paymentLedgerRows('SELECT * FROM cms_payments ORDER BY id'),
            'refunds' => $this->paymentLedgerRows('SELECT * FROM cms_payment_refunds ORDER BY id'),
            'webhook_receipts' => $this->paymentFetchAll('SELECT * FROM cms_payment_webhook_receipts ORDER BY id'),
            'authorizations' => $this->paymentLedgerRows('SELECT * FROM cms_payment_authorizations ORDER BY id'),
            'authorization_events' => $this->paymentLedgerRows('SELECT * FROM cms_payment_authorization_events ORDER BY id'),
            'provider_settings' => $this->paymentProviderSettingsRows(),
            'entitlements' => $this->paymentLedgerRows('SELECT * FROM cms_payment_entitlements ORDER BY id'),
        ];

        return [
            'schema_version' => self::PAYMENT_LEDGER_SCHEMA_VERSION,
            'exported_at' => gmdate('c'),
            'counts' => array_map(static fn (array $rows): int => count($rows), $sections),
        ] + $sections;
    }

    /** @return list<array<string, mixed>> */
    private function paymentLedgerRows(string $sql): array
    {
        $rows = $this->paymentFetchAll($sql);
        foreach ($rows as &$row) {
            if (array_key_exists('metadata_json', $row)) {
                $row['metadata_json'] = $this->redactedPaymentMetadataJson($row['metadata_json']);
            }
        }
        unset($row);

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function paymentProviderSettingsRows(): array
    {
        $rows = [];
        foreach ((new PaymentProviderSettingsRepository($this->pdo, ''))->all() as $setting) {
            $rows[] = [
                'provider_id' => (string) ($setting['provider_id'] ?? ''),
                'display_name' => (string) ($setting['display_name'] ?? ''),
                'status' => (string) ($setting['status'] ?? ''),
                'public_config_json' => (string) ($setting['public_config_json'] ?? '{}'),
                'secret_config_ciphertext' => (string) ($setting['secret_config_ciphertext'] ?? ''),
                'created_at' => (string) ($setting['created_at'] ?? ''),
                'updated_at' => (string) ($setting['updated_at'] ?? ''),
            ];
        }
        foreach ($rows as &$row) {
            $row['public_config_json'] = $this->redactedProviderPublicConfigJson($row['public_config_json'] ?? '{}');
        }
        unset($row);

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function fetchAll(string $sql): array
    {
        try {
            $stmt = $this->pdo->query($sql);
            return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<array<string, mixed>> */
    private function paymentFetchAll(string $sql): array
    {
        try {
            $stmt = $this->pdo->query($sql);
            if (!$stmt) {
                throw new ExportException('Core payment ledger export query failed.');
            }

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (ExportException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new ExportException('Core payment ledger export query failed.');
        }
    }

    private function positiveInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/', $value) === 1) {
            return (int) $value;
        }

        return 0;
    }

    private function safeMediaStorageKey(string $key): string
    {
        $key = str_replace('\\', '/', $key);
        if (
            $key === ''
            || $key !== trim($key, '/')
            || strlen($key) > 240
            || str_contains($key, '..')
            || preg_match('/[\x00-\x1F\x7F]/', $key) === 1
        ) {
            return '';
        }
        $parts = explode('/', $key);
        foreach ($parts as $part) {
            if ($part === '' || $part[0] === '.' || preg_match('/^[A-Za-z0-9._-]{1,96}$/', $part) !== 1) {
                return '';
            }
        }

        return $key;
    }

    /** @param mixed $metadataJson */
    private function redactedPaymentMetadataJson(mixed $metadataJson): string
    {
        if (!is_string($metadataJson)) {
            throw new ExportException('Core payment ledger metadata JSON is invalid.');
        }
        try {
            $metadata = json_decode($metadataJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ExportException('Core payment ledger metadata JSON is invalid.');
        }
        if (!is_array($metadata) || ($metadata !== [] && array_is_list($metadata))) {
            throw new ExportException('Core payment ledger metadata JSON is invalid.');
        }

        return $this->compactJson($this->redactedPaymentMetadata($metadata), 'Core payment ledger metadata JSON is invalid.');
    }

    /** @param mixed $publicConfigJson */
    private function redactedProviderPublicConfigJson(mixed $publicConfigJson): string
    {
        if (!is_string($publicConfigJson)) {
            throw new ExportException('Core payment Provider public config JSON is invalid.');
        }
        try {
            $config = json_decode($publicConfigJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ExportException('Core payment Provider public config JSON is invalid.');
        }
        if (!is_array($config) || ($config !== [] && array_is_list($config))) {
            throw new ExportException('Core payment Provider public config JSON is invalid.');
        }

        return $this->compactJson($this->redactedProviderPublicConfig($config), 'Core payment Provider public config JSON is invalid.');
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    private function redactedProviderPublicConfig(array $config): array
    {
        $safe = [];
        foreach ($config as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $key) !== 1) {
                continue;
            }
            if (preg_match('/password|secret|token|authorization|signature|auth|api[_-]?key|access[_-]?key|private/i', $key) === 1) {
                continue;
            }
            if ($key === 'default_provider') {
                if (is_bool($value)) {
                    $safe[$key] = $value;
                }
                continue;
            }
            if (!(is_scalar($value) || $value === null)) {
                continue;
            }
            if (($key === 'return_url_base' || str_contains(strtolower($key), 'url')) && $value !== null && !is_string($value)) {
                continue;
            }
            if (is_string($value)) {
                if ($value !== trim($value) || strlen($value) > 4096 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
                    continue;
                }
                if ($this->paymentMetadataValueContainsSecret($value)) {
                    continue;
                }
                if (str_contains(strtolower($key), 'url') && !$this->providerPublicConfigUrlSafe($value)) {
                    continue;
                }
            }
            $safe[$key] = $value;
        }

        return $safe;
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    private function redactedPaymentMetadata(array $metadata): array
    {
        $safe = [];
        foreach ($metadata as $key => $value) {
            if (!$this->paymentMetadataKeySafe($key)) {
                continue;
            }
            $name = strtolower((string) $key);
            if (preg_match('/password|secret|token|authorization|signature|auth|api[_-]?key|access[_-]?key|private|email|phone|address/', $name) === 1) {
                $safe[$key] = '[redacted]';
                continue;
            }
            if (str_contains($name, 'url') && is_string($value)) {
                $safe[$key] = $this->redactedPaymentMetadataUrl($value);
                continue;
            }
            if (is_string($value) && $this->paymentMetadataValueContainsSecret($value)) {
                $safe[$key] = '[redacted]';
                continue;
            }
            if ((is_string($value) && $this->paymentMetadataStringSafe($value)) || (is_scalar($value) && !is_string($value)) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    private function paymentMetadataKeySafe(mixed $key): bool
    {
        return is_string($key)
            && strlen($key) <= 64
            && preg_match('/^[a-zA-Z0-9._-]+$/', $key) === 1;
    }

    private function paymentMetadataStringSafe(string $value): bool
    {
        return strlen($value) <= 4096
            && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) !== 1;
    }

    private function paymentMetadataValueContainsSecret(string $value): bool
    {
        $pattern = '/(?:bearer\s+|payment_token=|sk_[A-Za-z0-9_=-]+|api[_-]?key=|access[_-]?key=|secret=|signature=)/i';

        return preg_match($pattern, $value) === 1
            || preg_match($pattern, rawurldecode($value)) === 1;
    }

    private function redactedPaymentMetadataUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return '[redacted]';
        }
        if ($this->paymentMetadataValueContainsSecret((string) ($parts['path'] ?? ''))) {
            return '[redacted]';
        }
        if (!isset($parts['query'])) {
            return $this->paymentMetadataUrlWithoutFragment($parts);
        }

        parse_str((string) $parts['query'], $query);
        foreach ($query as $key => $value) {
            if (!is_scalar($value)) {
                return '[redacted]';
            }
            $name = strtolower((string) $key);
            if (
                preg_match('/claim|token|signature|secret|authorization|auth|key|password|private/', $name) === 1
                || $this->paymentMetadataValueContainsSecret((string) $value)
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

    private function providerPublicConfigUrlSafe(string $url): bool
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
        if ($this->paymentMetadataValueContainsSecret(rawurldecode((string) ($parts['path'] ?? '')))) {
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
            if ($this->paymentMetadataValueContainsSecret(rawurldecode((string) $value))) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $parts */
    private function paymentMetadataUrlWithoutFragment(array $parts): string
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

    /** @param mixed $value */
    private function json(mixed $value): string
    {
        try {
            return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ExportException('Core export JSON encoding failed.');
        }
    }

    /** @param array<string,mixed> $value */
    private function compactJson(array $value, string $message): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ExportException($message);
        }
    }
}
