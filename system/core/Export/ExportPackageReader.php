<?php

declare(strict_types=1);

namespace Cms\Core\Export;

use ZipArchive;

final class ExportPackageReader
{
    private const PAYMENT_LEDGER_PATH = 'payments/payment-ledger.json';
    private const PAYMENT_LEDGER_SCHEMA_VERSION = '1.0.0';
    private const PAYMENT_LEDGER_SECTIONS = [
        'payments',
        'refunds',
        'webhook_receipts',
        'authorizations',
        'authorization_events',
        'provider_settings',
        'entitlements',
    ];

    /** @return array<string, mixed> */
    public function manifest(string $zipPath): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ExportException('ZipArchive extension is required for export packages.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new ExportException('Unable to open export package.');
        }

        $manifest = $zip->getFromName('manifest.json');
        $zip->close();

        return $this->decodeManifest(is_string($manifest) ? $manifest : '');
    }

    /** @return array<string, mixed> */
    public function verifyPackage(string $zipPath): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ExportException('ZipArchive extension is required for export packages.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new ExportException('Unable to open export package.');
        }

        try {
            $manifest = $this->decodeManifest((string) $zip->getFromName('manifest.json'));
            $checksums = $manifest['checksums'] ?? null;
            if (!is_array($checksums) || $checksums === []) {
                throw new ExportException('Export package checksums are missing.');
            }
            $this->assertNoUndeclaredEntries($zip, $checksums);
            foreach ($checksums as $path => $hash) {
                if (!is_string($path) || !$this->safePayloadPath($path)) {
                    throw new ExportException('Export package checksum path is invalid.');
                }
                $hash = $this->packageChecksum($hash);
                $payload = $zip->getFromName($path);
                if (!is_string($payload) || hash('sha256', $payload) !== $hash) {
                    throw new ExportException('Export package checksum mismatch.');
                }
            }

            return $manifest;
        } finally {
            $zip->close();
        }
    }

    /** @return array<string, mixed> */
    public function jsonPayload(string $zipPath, string $path): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ExportException('ZipArchive extension is required for export packages.');
        }

        $path = ltrim($path, '/');
        if (!$this->safePayloadPath($path)) {
            throw new ExportException('Export package payload path is invalid.');
        }
        $manifest = $this->verifyPackage($zipPath);

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new ExportException('Unable to open export package.');
        }

        try {
            $checksums = $manifest['checksums'] ?? null;
            if (!is_array($checksums) || !array_key_exists($path, $checksums)) {
                throw new ExportException('Export package payload checksum is missing.');
            }
            $expectedHash = $this->packageChecksum($checksums[$path]);

            $payload = $zip->getFromName($path);
            if (!is_string($payload) || hash('sha256', $payload) !== $expectedHash) {
                throw new ExportException('Export package payload checksum mismatch.');
            }

            try {
                $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new ExportException('Export package payload JSON is invalid.');
            }
            if (!is_array($decoded)) {
                throw new ExportException('Export package payload JSON is invalid.');
            }

            return $decoded;
        } finally {
            $zip->close();
        }
    }

    /** @return array<string, mixed> */
    public function paymentLedger(string $zipPath): array
    {
        $ledger = $this->jsonPayload($zipPath, self::PAYMENT_LEDGER_PATH);
        $schemaVersion = $this->paymentLedgerSchemaVersion($ledger['schema_version'] ?? null, 'Export package Core payment ledger schema version is unsupported.');
        $exportedAt = $this->paymentLedgerTimestamp($ledger['exported_at'] ?? null, 'Export package Core payment ledger export time is invalid.');
        if (!is_array($ledger['counts'] ?? null)) {
            throw new ExportException('Export package Core payment ledger counts are missing.');
        }
        foreach (self::PAYMENT_LEDGER_SECTIONS as $section) {
            if (!array_key_exists($section, $ledger) || !is_array($ledger[$section])) {
                throw new ExportException('Export package Core payment ledger is missing section: ' . $section);
            }
            foreach ($ledger[$section] as $row) {
                if (!is_array($row)) {
                    throw new ExportException('Export package Core payment ledger section is invalid: ' . $section);
                }
            }
            if ($this->paymentLedgerCount($ledger['counts'][$section] ?? null) !== count($ledger[$section])) {
                throw new ExportException('Export package Core payment ledger count mismatch: ' . $section);
            }
        }
        $summary = $this->paymentLedgerSummary($zipPath);
        if ($this->paymentLedgerSchemaVersion($summary['schema_version'] ?? null, 'Export package Core payment ledger manifest schema version is invalid.') !== $schemaVersion) {
            throw new ExportException('Export package Core payment ledger manifest schema mismatch.');
        }
        if ($this->paymentLedgerTimestamp($summary['exported_at'] ?? null, 'Export package Core payment ledger manifest export time is invalid.') !== $exportedAt) {
            throw new ExportException('Export package Core payment ledger manifest export time mismatch.');
        }
        $summaryCounts = $summary['counts'] ?? null;
        if (!is_array($summaryCounts)) {
            throw new ExportException('Export package Core payment ledger manifest counts are invalid.');
        }
        foreach (self::PAYMENT_LEDGER_SECTIONS as $section) {
            if ($this->paymentLedgerCount($summaryCounts[$section] ?? null) !== $this->paymentLedgerCount($ledger['counts'][$section] ?? null)) {
                throw new ExportException('Export package Core payment ledger manifest count mismatch: ' . $section);
            }
        }

        return $ledger;
    }

    /** @return array<string, mixed> */
    public function paymentLedgerSummary(string $zipPath): array
    {
        $manifest = $this->manifest($zipPath);
        $payloads = $manifest['payloads'] ?? null;
        if (!is_array($payloads)) {
            throw new ExportException('Export package Core payment ledger manifest summary is missing.');
        }
        $summary = $payloads[self::PAYMENT_LEDGER_PATH] ?? null;
        if (!is_array($summary)
            || !is_string($summary['type'] ?? null)
            || $summary['type'] !== 'core_payment_ledger'
        ) {
            throw new ExportException('Export package Core payment ledger manifest summary is missing.');
        }

        return $summary;
    }

    /** @return list<array{id:int,path:string,sha256:string,byte_size:int}> */
    public function mediaFiles(string $zipPath): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ExportException('ZipArchive extension is required for export packages.');
        }
        $manifest = $this->verifyPackage($zipPath);

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new ExportException('Unable to open export package.');
        }

        try {
            $mediaFiles = $manifest['media_files'] ?? ['count' => 0, 'files' => []];
            if (!is_array($mediaFiles) || !is_array($mediaFiles['files'] ?? null)) {
                throw new ExportException('Export package media file manifest is invalid.');
            }
            $files = [];
            foreach ($mediaFiles['files'] as $file) {
                if (!is_array($file)) {
                    throw new ExportException('Export package media file manifest is invalid.');
                }
                $id = $this->positiveId($file['id'] ?? null);
                $path = $this->mediaFilePath($file['path'] ?? null);
                $hash = $this->sha256($file['sha256'] ?? null);
                $byteSize = $this->nonNegativeInt($file['byte_size'] ?? null);
                $checksums = $manifest['checksums'] ?? null;
                if (!is_array($checksums) || (string) ($checksums[$path] ?? '') !== $hash) {
                    throw new ExportException('Export package media file checksum is missing.');
                }
                $content = $zip->getFromName($path);
                if (!is_string($content) || hash('sha256', $content) !== $hash || strlen($content) !== $byteSize) {
                    throw new ExportException('Export package media file checksum mismatch.');
                }
                $files[] = [
                    'id' => $id,
                    'path' => $path,
                    'sha256' => $hash,
                    'byte_size' => $byteSize,
                ];
            }
            if ($this->nonNegativeInt($mediaFiles['count'] ?? null) !== count($files)) {
                throw new ExportException('Export package media file count mismatch.');
            }

            return $files;
        } finally {
            $zip->close();
        }
    }

    /** @param array<mixed,mixed> $checksums */
    private function assertNoUndeclaredEntries(ZipArchive $zip, array $checksums): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if ($entry === 'manifest.json') {
                continue;
            }
            if (!$this->safePayloadPath(rtrim($entry, '/'))) {
                throw new ExportException('Export package contains unsafe path.');
            }
            if (str_ends_with($entry, '/')) {
                continue;
            }
            if (!array_key_exists($entry, $checksums)) {
                throw new ExportException('Export package contains undeclared file.');
            }
        }
    }

    /** @return array<string, mixed> */
    private function decodeManifest(string $manifest): array
    {
        try {
            $decoded = json_decode($manifest, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ExportException('Export package manifest is missing or invalid.');
        }
        if (!is_array($decoded)) {
            throw new ExportException('Export package manifest is missing or invalid.');
        }

        return $decoded;
    }

    private function paymentLedgerCount(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,17})$/', $value) === 1) {
            return (int) $value;
        }

        throw new ExportException('Export package Core payment ledger count is invalid.');
    }

    private function paymentLedgerSchemaVersion(mixed $value, string $message): string
    {
        if (!is_string($value) || $value !== self::PAYMENT_LEDGER_SCHEMA_VERSION) {
            throw new ExportException($message);
        }

        return $value;
    }

    private function paymentLedgerTimestamp(mixed $value, string $message): string
    {
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $value) !== 1
        ) {
            throw new ExportException($message);
        }

        $year = (int) substr($value, 0, 4);
        $month = (int) substr($value, 5, 2);
        $day = (int) substr($value, 8, 2);
        $hour = (int) substr($value, 11, 2);
        $minute = (int) substr($value, 14, 2);
        $second = (int) substr($value, 17, 2);
        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            throw new ExportException($message);
        }

        return $value;
    }

    private function positiveId(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/', $value) === 1) {
            return (int) $value;
        }

        throw new ExportException('Export package media file id is invalid.');
    }

    private function nonNegativeInt(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,17})$/', $value) === 1) {
            return (int) $value;
        }

        throw new ExportException('Export package media file size is invalid.');
    }

    private function sha256(mixed $value): string
    {
        try {
            return $this->packageChecksum($value);
        } catch (ExportException) {
            throw new ExportException('Export package media file checksum is invalid.');
        }
    }

    private function mediaFilePath(mixed $value): string
    {
        if (!is_string($value) || !str_starts_with($value, 'media/files/') || str_contains($value, '\\') || str_contains($value, '..')) {
            throw new ExportException('Export package media file path is invalid.');
        }
        $relative = substr($value, strlen('media/files/'));
        if ($relative === '' || $relative !== trim($relative, '/') || strlen($relative) > 240) {
            throw new ExportException('Export package media file path is invalid.');
        }
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part[0] === '.' || preg_match('/^[A-Za-z0-9._-]{1,96}$/', $part) !== 1) {
                throw new ExportException('Export package media file path is invalid.');
            }
        }

        return $value;
    }

    private function packageChecksum(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new ExportException('Export package checksum is invalid.');
        }

        return $value;
    }

    private function safePayloadPath(string $path): bool
    {
        if ($path === '' || $path !== ltrim($path, '/') || strlen($path) > 240 || str_contains($path, '\\') || str_contains($path, '..') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return false;
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part[0] === '.' || preg_match('/^[A-Za-z0-9._-]{1,96}$/', $part) !== 1) {
                return false;
            }
        }

        return true;
    }
}
