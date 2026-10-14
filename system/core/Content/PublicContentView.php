<?php

declare(strict_types=1);

namespace Cms\Core\Content;

/** Projects stored content into theme data without exposing editor payloads. */
final class PublicContentView
{
    /** @param array<string,mixed> $content @param array<string,mixed> $visibility @return array<string,mixed> */
    public static function from(array $content, array $visibility): array
    {
        $blocks = is_array($content['blocks'] ?? null) ? $content['blocks'] : [];
        $publicBlocks = ($visibility['enabled'] ?? false)
            ? array_slice($blocks, 0, max(0, (int) ($visibility['preview_block_count'] ?? 0)))
            : $blocks;
        $content['blocks'] = ($visibility['authorized'] ?? false) ? $blocks : $publicBlocks;
        unset($content['blocks_json'], $content['meta_json'], $content['_meta_json_malformed']);
        $meta = is_array($content['meta'] ?? null) ? $content['meta'] : [];
        unset($meta['preview_token'], $meta['preview_expires_at']);
        $content['meta'] = $meta;
        $text = trim(strip_tags((string) ($meta['seo_description'] ?? '')));
        if ($text === '') {
            $parts = [];
            foreach ($publicBlocks as $block) {
                $data = is_array($block['data'] ?? null) ? $block['data'] : [];
                if (in_array(($block['type'] ?? ''), ['paragraph', 'heading', 'quote', 'raw_text'], true)) {
                    $parts[] = (string) ($data['text'] ?? '');
                } elseif (in_array(($block['type'] ?? ''), ['unordered_list', 'ordered_list'], true)) {
                    foreach (is_array($data['items'] ?? null) ? $data['items'] : [] as $item) {
                        if (is_string($item)) { $parts[] = $item; }
                    }
                }
            }
            $text = strip_tags(implode(' ', $parts));
        }
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        $content['public_excerpt'] = function_exists('mb_substr') ? mb_substr($text, 0, 180, 'UTF-8') : substr($text, 0, 180);

        return $content;
    }
}
