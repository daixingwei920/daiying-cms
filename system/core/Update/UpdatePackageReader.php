<?php

declare(strict_types=1);

namespace Cms\Core\Update;

use ZipArchive;

final class UpdatePackageReader
{
    private const MAX_FILES = 1000;
    private const MAX_UNPACKED_BYTES = 100000000;
    private const MAX_DEPTH = 10;

    public function read(string $zipPath, SignatureVerifier $verifier): UpdatePackageManifest
    {
        if (!class_exists(ZipArchive::class)) {
            throw new UpdateException('ZipArchive extension is required for update packages.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new UpdateException('Unable to open update package.');
        }

        $updateJson = $zip->getFromName('update.json');
        $signature = $zip->getFromName('signature.bin');
        if (!is_string($updateJson) || !is_string($signature)) {
            $zip->close();
            throw new UpdateException('Update package must contain update.json and signature.bin.');
        }

        $decoded = json_decode($updateJson, true);
        if (!is_array($decoded)) {
            $zip->close();
            throw new UpdateException('Update manifest JSON is invalid.');
        }

        $manifest = UpdatePackageManifest::fromArray($decoded);
        if (!in_array($manifest->signatureAlgorithm, ['rsa-sha256', 'ed25519'], true)) {
            $zip->close();
            throw new UpdateException('Update package signature algorithm is unsupported.');
        }
        if (!$verifier->verify($updateJson, $signature, $manifest->keyId)) {
            $zip->close();
            throw new UpdateException('Update package signature is invalid.');
        }
        if ($manifest->packageSha256 !== '' && hash_file('sha256', $zipPath) !== $manifest->packageSha256) {
            $zip->close();
            throw new UpdateException('Update package hash mismatch.');
        }
        $this->assertZipPaths($zip, $manifest);
        $zip->close();

        return $manifest;
    }

    private function assertZipPaths(ZipArchive $zip, UpdatePackageManifest $manifest): void
    {
        if ($zip->numFiles > self::MAX_FILES) {
            throw new UpdateException('Update package contains too many files.');
        }
        $seen = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            $this->assertEntryName($name, $seen);
            $opsys = 0;
            $attributes = 0;
            $zip->getExternalAttributesIndex($i, $opsys, $attributes);
            $unixMode = ($attributes >> 16) & 0xF000;
            if (in_array($unixMode, [0xA000, 0x6000], true)) {
                throw new UpdateException('Update package contains links or special files.');
            }
            $total += (int) ($stat['size'] ?? 0);
            if ($total > self::MAX_UNPACKED_BYTES) {
                throw new UpdateException('Update package uncompressed size exceeds limit.');
            }
            if ($name === 'update.json' || $name === 'signature.bin') {
                continue;
            }
            if (preg_match('/\.zip$/i', $name)) {
                throw new UpdateException('Nested archives are not allowed in Core update packages.');
            }
            if (!isset($manifest->files[$name]) || !UpdatePackageManifest::isAllowedUpdatePath($name)) {
                throw new UpdateException('Unsafe or undeclared update package path: ' . $name);
            }
            $content = $zip->getFromIndex($i);
            if (!is_string($content) || hash('sha256', $content) !== $manifest->files[$name]) {
                throw new UpdateException('Update package hash mismatch: ' . $name);
            }
        }
        foreach (array_keys($manifest->files) as $path) {
            if ($zip->locateName($path) === false) {
                throw new UpdateException('Update package is missing declared file: ' . $path);
            }
        }
    }

    private function assertEntryName(string $name, array &$seen): void
    {
        if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:\//', $name) || str_contains($name, '../') || str_contains($name, '/..')) {
            throw new UpdateException('Update package contains unsafe path.');
        }
        if (substr_count($name, '/') > self::MAX_DEPTH) {
            throw new UpdateException('Update package directory depth exceeds limit.');
        }
        $normalized = function_exists('normalizer_normalize') && class_exists('\Normalizer')
            ? (normalizer_normalize($name, \Normalizer::FORM_C) ?: $name)
            : $name;
        $lower = function_exists('mb_strtolower') ? mb_strtolower($normalized) : strtolower($normalized);
        if (isset($seen[$lower])) {
            throw new UpdateException('Update package contains filename collision.');
        }
        $seen[$lower] = true;
    }
}
