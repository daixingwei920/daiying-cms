<?php

declare(strict_types=1);

namespace Cms\Core\Plugin;

use ZipArchive;

final class PackageScanner
{
    /** @return list<array{severity: string, code: string, message: string, path: string}> */
    public function scanZip(string $zipPath): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new PluginException('ZipArchive extension is required for plugin package scanning.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new PluginException('Unable to open plugin package.');
        }

        $findings = [];
        $declaredCapabilities = [];
        $manifestJson = $zip->getFromName('plugin.json');
        if (is_string($manifestJson)) {
            $manifest = json_decode($manifestJson, true);
            $declaredCapabilities = is_array($manifest['capabilities'] ?? null) ? $manifest['capabilities'] : [];
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $normalized = str_replace('\\', '/', $name);
            $opsys = 0;
            $attributes = 0;
            $zip->getExternalAttributesIndex($i, $opsys, $attributes);
            $unixMode = ($attributes >> 16) & 0xF000;
            if (in_array($unixMode, [0xA000, 0x6000], true)) {
                $findings[] = $this->finding('critical', 'link_or_special_file', 'Package contains a link or special file.', $name);
            }
            if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:\//', $normalized) || str_contains($normalized, '..')) {
                $findings[] = $this->finding('critical', 'unsafe_path', 'Package contains an unsafe path.', $name);
            }
            if (str_starts_with($normalized, 'system/') || str_starts_with($normalized, 'config/') || str_starts_with($normalized, 'storage/') || str_starts_with($normalized, 'public/')) {
                $findings[] = $this->finding('critical', 'core_write', 'Package attempts to write protected CMS files.', $name);
            }
            if (preg_match('/\.(exe|dll|dylib|bin)$/i', $normalized) === 1) {
                $findings[] = $this->finding('critical', 'binary', 'Package contains an executable binary.', $name);
            }
            if (preg_match('/\.zip$/i', $normalized) === 1) {
                $findings[] = $this->finding('critical', 'nested_zip', 'Package contains a nested ZIP.', $name);
            }
            if (preg_match('/\.(php|phtml)$/i', $normalized) === 1) {
                $content = $zip->getFromIndex($i);
                if (is_string($content) && preg_match('/\b(eval|shell_exec|passthru|proc_open)\s*\(/i', $content) === 1) {
                    $findings[] = $this->finding('high', 'dangerous_call', 'Package contains a high-risk PHP call.', $name);
                }
                if (is_string($content) && preg_match('/\b(system|exec|popen|curl_exec|file_get_contents\s*\(\s*[\'"]https?:)\s*\(/i', $content) === 1) {
                    $findings[] = $this->finding('high', 'risky_io', 'Package contains shell or remote IO code.', $name);
                }
                if (is_string($content) && preg_match('/\b(base64_decode|gzinflate|str_rot13)\s*\(/i', $content) === 1) {
                    $findings[] = $this->finding('medium', 'obfuscation', 'Package contains encoded or obfuscated code.', $name);
                }
                if (is_string($content) && str_contains($content, 'http') && !in_array('network.external', $declaredCapabilities, true)) {
                    $findings[] = $this->finding('high', 'capability_mismatch', 'Package uses network behavior without declaring capability.', $name);
                }
            }
        }

        $zip->close();

        return $findings;
    }

    /** @return array{severity: string, code: string, message: string, path: string} */
    private function finding(string $severity, string $code, string $message, string $path): array
    {
        return compact('severity', 'code', 'message', 'path');
    }
}
