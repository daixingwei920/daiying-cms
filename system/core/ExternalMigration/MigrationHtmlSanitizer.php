<?php

declare(strict_types=1);

namespace Cms\Core\ExternalMigration;

final class MigrationHtmlSanitizer
{
    /** @param array<string,string> $mediaMap */
    public function sanitize(string $html, array $mediaMap = []): string
    {
        $html = str_replace(array_keys($mediaMap), array_values($mediaMap), $html);
        $html = preg_replace('/<\s*(script|iframe|object|embed|form|input|button|style)\b[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $html) ?? '';
        $html = preg_replace('/<\s*(script|iframe|object|embed|form|input|button|style)\b[^>]*\/?\s*>/is', '', $html) ?? '';
        $html = preg_replace('/\s+on[a-z0-9_-]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace_callback('/\s+(href|src)\s*=\s*("|\')([^"\']*)(\2)/i', static function (array $match): string {
            $attr = strtolower((string) $match[1]);
            $quote = (string) $match[2];
            $url = trim(html_entity_decode((string) $match[3], ENT_QUOTES, 'UTF-8'));
            if ($url === '' || preg_match('/^(javascript|data|vbscript|file):/i', $url) === 1) {
                return '';
            }
            if (str_starts_with($url, '//')) {
                return '';
            }
            return ' ' . $attr . '=' . $quote . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . $quote;
        }, $html) ?? '';

        return trim($html);
    }
}
