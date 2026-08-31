<?php

declare(strict_types=1);

namespace Cms\Core\Advertising;

final class AdRenderer
{
    public static function renderSlot(string $slotKey, string $html): string
    {
        $safe = self::sanitizeHtml($html);
        if (trim(strip_tags($safe)) === '' && !str_contains($safe, '<img')) {
            return '';
        }

        return '<span class="cms-ad-track" aria-hidden="true"><img src="/ads/track/' . self::escapeAttr($slotKey) . '/impression" alt="" width="1" height="1" loading="lazy" style="position:absolute;width:1px;height:1px;opacity:0;pointer-events:none"></span>' .
            self::rewriteClicks($slotKey, $safe);
    }

    public static function sanitizeHtml(string $html): string
    {
        $html = preg_replace('/<(script|style|iframe|object|embed|form|input|button|textarea|select|meta|link)\b[^>]*>.*?<\/\1>/is', '', $html) ?? '';
        $html = preg_replace('/<\/?(script|style|iframe|object|embed|form|input|button|textarea|select|meta|link)\b[^>]*>/is', '', $html) ?? '';
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s+(href|src)\s*=\s*([\'"])\s*(javascript|data|vbscript):.*?\2/i', '', $html) ?? '';
        $html = preg_replace_callback('/\s+(href|src)\s*=\s*([^\s>]+)\s*/i', static function (array $matches): string {
            $value = trim((string) $matches[2], " \t\n\r\0\x0B'\"");
            if (preg_match('/^(javascript|data|vbscript):/i', $value) === 1) {
                return ' ';
            }
            return $matches[0];
        }, $html) ?? '';

        return strip_tags($html, '<a><img><p><span><strong><em><b><i><br><div>');
    }

    private static function rewriteClicks(string $slotKey, string $html): string
    {
        return preg_replace_callback('/<a\b([^>]*)\bhref=(["\'])(.*?)\2([^>]*)>/i', static function (array $matches) use ($slotKey): string {
            $target = html_entity_decode((string) $matches[3], ENT_QUOTES, 'UTF-8');
            if (!self::safeClickTarget($target)) {
                return '<a' . $matches[1] . $matches[4] . '>';
            }
            $url = '/ads/track/' . rawurlencode($slotKey) . '/click?to=' . rawurlencode($target);
            return '<a' . $matches[1] . 'href="' . self::escapeAttr($url) . '"' . $matches[4] . '>';
        }, $html) ?? $html;
    }

    private static function safeClickTarget(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }

    private static function escapeAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

