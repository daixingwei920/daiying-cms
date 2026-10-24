<?php

declare(strict_types=1);

namespace Cms\Core\Seo;

/** A finite UA vocabulary, not an IP/source verification service. */
final class CrawlerRegistry
{
    /** @return array<string,array{label:string,domestic:bool,kind:string,aliases:list<string>}> */
    public static function agents(): array
    {
        return [
            'OAI-SearchBot' => self::entry('OpenAI 搜索', false, 'AI 搜索', ['OAI-SearchBot']),
            'GPTBot' => self::entry('OpenAI GPTBot', false, 'AI 训练声明', ['GPTBot']),
            'ChatGPT-User' => self::entry('ChatGPT 用户请求', false, '用户触发', ['ChatGPT-User']),
            'Claude-SearchBot' => self::entry('Claude 搜索', false, 'AI 搜索', ['Claude-SearchBot']),
            'ClaudeBot' => self::entry('ClaudeBot', false, 'AI 训练声明', ['ClaudeBot']),
            'Claude-User' => self::entry('Claude 用户请求', false, '用户触发', ['Claude-User']),
            'Googlebot' => self::entry('Google', false, '搜索', ['Googlebot']),
            'bingbot' => self::entry('Bing', false, '搜索', ['bingbot']),
            'Baiduspider' => self::entry('百度', true, '搜索', ['Baiduspider', 'Baiduspider-render', 'Baiduspider-image', 'Baiduspider-video', 'Baiduspider-news']),
            'Sogou web spider' => self::entry('搜狗', true, '搜索', ['Sogou web spider', 'Sogou head spider', 'Sogou inst spider', 'Sogou Orion spider', 'Sogou Pic spider', 'Sogou Test spider', 'New-Sogou-Spider']),
            '360Spider' => self::entry('360搜索', true, '搜索', ['360Spider', '360Spider-Image', '360Spider-Video']),
            'YisouSpider' => self::entry('神马', true, '搜索', ['YisouSpider']),
            'Bytespider' => self::entry('字节 Bytespider', true, '用途未声明', ['Bytespider']),
            'ToutiaoSpider' => self::entry('头条', true, '搜索标记', ['ToutiaoSpider']),
            'PetalBot' => self::entry('Petal', true, '搜索标记', ['PetalBot']),
        ];
    }

    public static function identify(string $userAgent): ?string
    {
        // Bound cost and ignore an URL in a UA: a documentation link is not an agent token.
        $ua = preg_replace('~https?://[^\s;)]+~i', '', substr($userAgent, 0, 512)) ?? '';
        foreach (self::agents() as $agent => $info) {
            foreach ($info['aliases'] as $alias) {
                $token = preg_quote($alias, '~');
                if (str_starts_with($alias, 'Sogou ')) { $token = str_replace(' ', '[ -]', $token); }
                if (preg_match('~(?<![a-z0-9_-])' . $token . '(?![a-z0-9_-])~i', $ua) === 1) {
                    return $agent;
                }
            }
        }
        return null;
    }

    /** @return array{label:string,domestic:bool,kind:string,aliases:list<string>} */
    private static function entry(string $label, bool $domestic, string $kind, array $aliases): array
    {
        return compact('label', 'domestic', 'kind', 'aliases');
    }
}
