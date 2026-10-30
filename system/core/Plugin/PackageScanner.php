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
        $declaredCapabilities = $this->declaredCapabilities($zip, $findings);
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

    /** @param list<array<string,string>> $findings @return list<mixed> */
    private function declaredCapabilities(ZipArchive $zip, array &$findings): array
    {
        $candidates = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if (preg_match('#^(?:[^/]+/)?plugin\.json$#D', $name) === 1) {
                $candidates[] = ['path'=>$name, 'index'=>$i];
            }
        }
        if ($candidates === []) { return []; }
        if (count($candidates) !== 1) {
            $findings[] = $this->finding('critical', 'ambiguous_manifest', 'Package must have one unambiguous plugin manifest.', '');
            return [];
        }
        $path = $candidates[0]['path'];
        $raw = $zip->getFromIndex($candidates[0]['index']);
        $manifest = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($manifest) || array_is_list($manifest) || !is_array($manifest['capabilities'] ?? [])) {
            $findings[] = $this->finding('critical', 'invalid_manifest', 'Package plugin manifest is invalid.', $path);
            return [];
        }
        if ($path !== 'plugin.json') {
            $root = dirname($path);
            if (($manifest['plugin_id'] ?? null) !== $root) {
                $findings[] = $this->finding('critical', 'manifest_identity', 'Plugin manifest id must match its root directory.', $path);
                return [];
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
                if (!str_starts_with($name, $root . '/')) {
                    $findings[] = $this->finding('critical', 'manifest_scope', 'Package contains files outside its plugin manifest root.', $name);
                    return [];
                }
            }
        }
        return $manifest['capabilities'] ?? [];
    }

    /** @return array{severity: string, code: string, message: string, path: string} */
    private function finding(string $severity, string $code, string $message, string $path): array
    {
        return compact('severity', 'code', 'message', 'path');
    }
}
