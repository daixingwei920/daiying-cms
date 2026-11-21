<?php

declare(strict_types=1);

namespace Cms\Core\Seo;

/** Reviewed vendor policies, never URLs or suffixes supplied by a visitor. */
final class CrawlerSourcePolicy
{
    public const FEEDS = [
        'google' => 'https://developers.google.com/static/crawling/ipranges/common-crawlers.json',
        'bing' => 'https://www.bing.com/toolbox/bingbot.json',
        'openai_search' => 'https://openai.com/searchbot.json',
        'openai_training' => 'https://openai.com/gptbot.json',
        'openai_user' => 'https://openai.com/chatgpt-user.json',
        'anthropic' => 'https://claude.com/crawling/bots.json',
    ];

    /** @return array{feed:?string,suffixes:list<string>}|null */
    public static function forAgent(string $agent): ?array
    {
        return match ($agent) {
            'Googlebot' => ['feed' => 'google', 'suffixes' => ['googlebot.com']],
            'bingbot' => ['feed' => 'bing', 'suffixes' => ['search.msn.com']],
            'OAI-SearchBot' => ['feed' => 'openai_search', 'suffixes' => []],
            'GPTBot' => ['feed' => 'openai_training', 'suffixes' => []],
            'ChatGPT-User' => ['feed' => 'openai_user', 'suffixes' => []],
            'ClaudeBot', 'Claude-SearchBot', 'Claude-User' => ['feed' => 'anthropic', 'suffixes' => []],
            'Baiduspider' => ['feed' => null, 'suffixes' => ['baidu.com', 'baidu.jp']],
            'PetalBot' => ['feed' => null, 'suffixes' => ['aspiegel.com', 'petalsearch.com']],
            default => null,
        };
    }

    public static function publicIp(string $ip): ?string
    {
        if (strlen($ip) > 45 || $ip !== trim($ip)) { return null; }
        $packed = @inet_pton($ip);
        if ($packed === false) { return null; }
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $packed = substr($packed, 12);
        }
        if ((strlen($packed) === 4 && ord($packed[0]) >= 224) || (strlen($packed) === 16 && ord($packed[0]) === 255)) { return null; }
        $canonical = inet_ntop($packed);
        return is_string($canonical) && filter_var($canonical, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false
            ? $canonical : null;
    }

    public static function matches(string $ip, string $cidr): bool
    {
        if (!preg_match('~^([^/]+)/([0-9]{1,3})$~D', $cidr, $parts)) { return false; }
        $address = @inet_pton($ip); $network = @inet_pton($parts[1]); $bits = (int) $parts[2];
        if ($address === false || $network === false || strlen($address) !== strlen($network)
            || $bits < (strlen($address) === 4 ? 8 : 16) || $bits > strlen($address) * 8) { return false; }
        $bytes = intdiv($bits, 8); $remaining = $bits % 8;
        return substr($address, 0, $bytes) === substr($network, 0, $bytes)
            && ($remaining === 0 || (ord($address[$bytes]) & (255 << (8 - $remaining))) === (ord($network[$bytes]) & (255 << (8 - $remaining))));
    }

    /** Reject a malformed feed as a whole, including empty or overly broad lists. @return list<string>|null */
    public static function parseFeed(string $raw): ?array
    {
        if (strlen($raw) > 262144) { return null; }
        $data = json_decode($raw, true, 16);
        $rows = is_array($data) ? ($data['prefixes'] ?? null) : null;
        if (!is_array($rows) || !array_is_list($rows) || $rows === [] || count($rows) > 2048) { return null; }
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row) || isset($row['ipv4Prefix']) === isset($row['ipv6Prefix'])) { return null; }
            $cidr = $row['ipv4Prefix'] ?? $row['ipv6Prefix'];
            if (!is_string($cidr) || strlen($cidr) > 49 || !preg_match('~^([^/]+)/([0-9]{1,3})$~D', $cidr, $parts)) { return null; }
            $ip = self::publicIp($parts[1]);
            if ($ip === null || (isset($row['ipv4Prefix']) !== (strlen((string) inet_pton($ip)) === 4)) || !self::matches($ip, $cidr)) { return null; }
            $result[] = $ip . '/' . (int) $parts[2];
        }
        return array_values(array_unique($result));
    }

    /** A dot-boundary match, not a substring match. */
    public static function allowedHostname(string $name, array $suffixes): bool
    {
        $name = strtolower(str_ends_with($name, '.') ? substr($name, 0, -1) : $name);
        if (strlen($name) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $name)) { return false; }
        foreach ($suffixes as $suffix) {
            if (str_ends_with($name, '.' . $suffix)) { return true; }
        }
        return false;
    }

    /** Testable forward-confirmed reverse DNS; a PTR or a suffix alone proves nothing. */
    public static function verifyDns(string $ip, array $suffixes, callable $lookup): ?bool
    {
        if (self::publicIp($ip) !== $ip || $suffixes === []) { return null; }
        $packed = inet_pton($ip);
        $reverse = strlen($packed) === 4 ? implode('.', array_reverse(explode('.', $ip))) . '.in-addr.arpa'
            : implode('.', array_reverse(str_split(bin2hex($packed)))) . '.ip6.arpa';
        try {
            $records = $lookup($reverse, DNS_PTR);
            if (!is_array($records) || count($records) > 4) { return null; }
            $unavailable = false;
            foreach ($records as $record) {
                $host = $record['target'] ?? null;
                if (!is_string($host) || !self::allowedHostname($host, $suffixes)) { continue; }
                $host = strtolower(str_ends_with($host, '.') ? substr($host, 0, -1) : $host);
                $forward = $lookup($host, DNS_A | DNS_AAAA);
                if (!is_array($forward) || count($forward) > 32) { $unavailable = true; continue; }
                foreach ($forward as $address) {
                    $value = $address['ip'] ?? $address['ipv6'] ?? null;
                    if (is_string($value) && @inet_pton($value) === $packed) { return true; }
                }
            }
            return $unavailable ? null : false;
        } catch (\Throwable) { return null; }
    }
}
