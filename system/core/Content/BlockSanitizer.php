<?php

declare(strict_types=1);

namespace Cms\Core\Content;

use Cms\Core\ExternalMigration\MigrationHtmlSanitizer;
use Cms\Core\Support\CurrencyRegistry;
use Cms\Core\Support\Money;
use InvalidArgumentException;

final class BlockSanitizer
{
    private const ALLOWED_TYPES = [
        'paragraph',
        'heading',
        'unordered_list',
        'ordered_list',
        'quote',
        'code',
        'divider',
        'button',
        'table',
        'html',
        'raw_text',
        'image',
        'gallery',
        'video',
        'audio',
        'attachment',
        'card_delivery',
        'tip',
    ];

    /**
     * @param mixed $blocks
     * @param list<string> $registeredTypes
     * @return list<array<string, mixed>>
     */
    public static function sanitize(mixed $blocks, array $registeredTypes = []): array
    {
        if (is_string($blocks)) {
            $decoded = json_decode($blocks, true);
            $blocks = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($blocks)) {
            return [];
        }

        $clean = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = (string) ($block['type'] ?? '');
            if (!in_array($type, self::ALLOWED_TYPES, true) && !in_array($type, $registeredTypes, true)) {
                $clean[] = [
                    'type' => 'missing-extension',
                    'plugin_id' => (string) ($block['plugin_id'] ?? 'unknown'),
                    'original' => $block,
                ];
                continue;
            }

            $clean[] = in_array($type, $registeredTypes, true) && !in_array($type, self::ALLOWED_TYPES, true)
                ? ['type' => $type, 'data' => self::cleanData($block['data'] ?? [])]
                : ['type' => $type, 'data' => self::cleanBuiltin($type, $block['data'] ?? [])];
        }

        return $clean;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<string>
     */
    public static function validate(array $blocks): array
    {
        $errors = [];
        foreach ($blocks as $index => $block) {
            if (!is_array($block)) {
                $errors[] = 'Block ' . $index . ' must be an object.';
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            if ($type === 'missing-extension') {
                continue;
            }
            if (!in_array($type, self::ALLOWED_TYPES, true)) {
                if (($block['plugin_id'] ?? '') !== '') {
                    continue;
                }
                $errors[] = 'Block ' . $index . ' has unsupported type: ' . $type;
                continue;
            }
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            if ($type === 'heading') {
                $level = (int) ($data['level'] ?? 2);
                if ($level < 1 || $level > 6) {
                    $errors[] = 'Heading block ' . $index . ' must use H1-H6.';
                }
            }
            if ($type === 'button') {
                $url = (string) ($data['url'] ?? '');
                if ($url !== '' && !self::safeUrl($url)) {
                    $errors[] = 'Button block ' . $index . ' has unsafe URL.';
                }
            }
        }

        return $errors;
    }

    /** @return array<string, mixed> */
    private static function cleanBuiltin(string $type, mixed $data): array
    {
        $data = is_array($data) ? $data : [];

        return match ($type) {
            'paragraph' => [
                'text' => self::plain((string) ($data['text'] ?? '')),
                'style' => in_array(($data['style'] ?? ''), ['body', 'lead', 'small'], true) ? (string) $data['style'] : 'body',
                'bold' => (bool) ($data['bold'] ?? false),
                'italic' => (bool) ($data['italic'] ?? false),
                'alignment' => in_array(($data['alignment'] ?? ''), ['left', 'center', 'right'], true) ? (string) $data['alignment'] : 'left',
                'link' => self::safeUrl((string) ($data['link'] ?? '')) ? trim((string) ($data['link'] ?? '')) : '',
            ],
            'heading' => [
                'level' => max(1, min(6, (int) ($data['level'] ?? 2))),
                'text' => self::plain((string) ($data['text'] ?? '')),
                'alignment' => in_array(($data['alignment'] ?? ''), ['left', 'center', 'right'], true) ? (string) $data['alignment'] : 'left',
            ],
            'unordered_list', 'ordered_list' => [
                'items' => self::plainList($data['items'] ?? []),
            ],
            'quote' => [
                'text' => self::plain((string) ($data['text'] ?? '')),
                'cite' => self::plain((string) ($data['cite'] ?? '')),
            ],
            'code' => [
                'language' => preg_replace('/[^a-zA-Z0-9_+-]/', '', (string) ($data['language'] ?? '')) ?: '',
                'code' => str_replace("\0", '', (string) ($data['code'] ?? '')),
            ],
            'divider' => [
                'style' => in_array(($data['style'] ?? ''), ['solid', 'dashed', 'wide'], true) ? (string) $data['style'] : 'solid',
                'spacing' => in_array(($data['spacing'] ?? ''), ['compact', 'normal', 'large'], true) ? (string) $data['spacing'] : 'normal',
            ],
            'button' => [
                'text' => self::plain((string) ($data['text'] ?? '')),
                'url' => self::safeUrl((string) ($data['url'] ?? '')) ? trim((string) ($data['url'] ?? '')) : '',
                'target' => in_array(($data['target'] ?? ''), ['_self', '_blank'], true) ? (string) $data['target'] : '_self',
                'style' => in_array(($data['style'] ?? ''), ['primary', 'secondary', 'outline'], true) ? (string) $data['style'] : 'primary',
                'alignment' => in_array(($data['alignment'] ?? ''), ['left', 'center', 'right'], true) ? (string) $data['alignment'] : 'left',
            ],
            'table' => [
                'rows' => self::tableRows($data['rows'] ?? []),
            ],
            'html' => [
                'html' => (new MigrationHtmlSanitizer())->sanitize((string) ($data['html'] ?? '')),
            ],
            'raw_text' => [
                'text' => self::plain((string) ($data['text'] ?? '')),
            ],
            'image', 'gallery', 'video', 'audio', 'attachment' => [
                ...self::cleanMediaBuiltin($type, $data),
                'caption' => self::plain((string) ($data['caption'] ?? '')),
                'alt' => self::plain((string) ($data['alt'] ?? '')),
            ],
            'card_delivery' => [
                'card_product_id' => max(0, (int) ($data['card_product_id'] ?? 0)),
                'show_name' => (bool) ($data['show_name'] ?? true),
                'show_price' => (bool) ($data['show_price'] ?? true),
                'show_stock' => (bool) ($data['show_stock'] ?? true),
                'show_button' => (bool) ($data['show_button'] ?? true),
                'button_text' => self::plain((string) ($data['button_text'] ?? '立即购买')) ?: '立即购买',
            ],
            'tip' => [
                'title' => self::plain((string) ($data['title'] ?? '喜欢这篇内容？')) ?: '喜欢这篇内容？',
                'description' => self::plain((string) ($data['description'] ?? '可以打赏支持作者继续创作。')),
                'amounts' => self::tipAmounts($data['amounts'] ?? ['1', '5', '10'], (string) ($data['currency'] ?? 'USD')),
                'currency' => self::currency((string) ($data['currency'] ?? 'USD')),
                'custom_amount' => (bool) ($data['custom_amount'] ?? true),
                'button_text' => self::plain((string) ($data['button_text'] ?? '打赏支持')) ?: '打赏支持',
            ],
            default => ['text' => self::plain((string) ($data['text'] ?? ''))],
        };
    }

    /** @return list<string> */
    private static function tipAmounts(mixed $amounts, string $currency): array
    {
        if (is_string($amounts)) {
            $amounts = preg_split('/[,\s]+/', $amounts) ?: [];
        }
        if (!is_array($amounts)) {
            $amounts = [];
        }
        $currency = self::currency($currency);
        $clean = [];
        foreach (array_slice($amounts, 0, 12) as $amount) {
            if (!is_string($amount) && !is_int($amount)) {
                continue;
            }
            $value = str_replace(["\r", "\n", "\t", ' '], '', trim((string) $amount));
            if (str_starts_with($value, '.')) {
                $value = '0' . $value;
            }
            try {
                if (Money::toMinor($value, $currency) > 0) {
                    $clean[$value] = $value;
                }
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return array_values($clean) ?: ['1', '5', '10'];
    }

    private static function currency(string $currency): string
    {
        try {
            $code = CurrencyRegistry::normalizeCode($currency);
            CurrencyRegistry::require($code);
            return $code;
        } catch (InvalidArgumentException) {
            return 'USD';
        }
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private static function cleanMediaBuiltin(string $type, array $data): array
    {
        if ($type === 'gallery') {
            $ids = $data['media_ids'] ?? [];
            if (is_string($ids)) {
                $ids = preg_split('/[,\s]+/', $ids) ?: [];
            }
            $items = [];
            foreach (array_slice(is_array($data['items'] ?? null) ? $data['items'] : [], 0, 100) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $items[] = [
                    'media_id' => (int) ($item['media_id'] ?? 0),
                    'alt' => self::plain((string) ($item['alt'] ?? '')),
                    'caption' => self::plain((string) ($item['caption'] ?? '')),
                ];
            }
            foreach ($items as $item) {
                if ((int) $item['media_id'] > 0) {
                    $ids[] = (int) $item['media_id'];
                }
            }

            return [
                'media_ids' => array_values(array_unique(array_filter(array_map(static fn (mixed $id): int => (int) $id, is_array($ids) ? $ids : []), static fn (int $id): bool => $id > 0))),
                'items' => $items,
                'columns' => max(1, min(6, (int) ($data['columns'] ?? 3))),
            ];
        }
        if ($type === 'image') {
            return [
                'media_id' => (int) ($data['media_id'] ?? 0),
                'alignment' => in_array(($data['alignment'] ?? ''), ['none', 'left', 'center', 'right'], true) ? (string) $data['alignment'] : 'none',
                'link' => self::safeUrl((string) ($data['link'] ?? '')) ? trim((string) ($data['link'] ?? '')) : '',
                'width' => max(0, min(4000, (int) ($data['width'] ?? 0))),
            ];
        }
        if ($type === 'audio') {
            return [
                'media_id' => (int) ($data['media_id'] ?? 0),
                'title' => self::plain((string) ($data['title'] ?? '')),
                'controls' => (bool) ($data['controls'] ?? true),
                'preload' => in_array(($data['preload'] ?? ''), ['none', 'metadata', 'auto'], true) ? (string) $data['preload'] : 'metadata',
            ];
        }
        if ($type === 'video') {
            $autoplay = (bool) ($data['autoplay'] ?? false);
            return [
                'media_id' => (int) ($data['media_id'] ?? 0),
                'poster_media_id' => (int) ($data['poster_media_id'] ?? 0),
                'source_url' => self::safeMediaUrl((string) ($data['source_url'] ?? '')) ? trim((string) ($data['source_url'] ?? '')) : '',
                'controls' => (bool) ($data['controls'] ?? true),
                'autoplay' => $autoplay,
                'muted' => $autoplay ? true : (bool) ($data['muted'] ?? false),
                'loop' => (bool) ($data['loop'] ?? false),
                'playsinline' => (bool) ($data['playsinline'] ?? true),
                'preload' => in_array(($data['preload'] ?? ''), ['none', 'metadata', 'auto'], true) ? (string) $data['preload'] : 'metadata',
            ];
        }

        $paidEnabled = (bool) ($data['paid_enabled'] ?? false);

        return [
            'media_id' => (int) ($data['media_id'] ?? 0),
            'display_name' => self::plain((string) ($data['display_name'] ?? '')),
            'paid_enabled' => $paidEnabled,
            'price_minor' => self::cleanPaidAttachmentPrice($data['price_minor'] ?? 0, $paidEnabled),
            'currency' => self::cleanPaidAttachmentCurrency($data['currency'] ?? 'USD', $paidEnabled),
            'payment_label' => self::plain((string) ($data['payment_label'] ?? '解锁下载')),
        ];
    }

    private static function cleanPaidAttachmentCurrency(mixed $value, bool $enabled): string
    {
        $currency = (string) $value;
        if (!$enabled && $currency === '') {
            return 'USD';
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new \InvalidArgumentException('Paid attachment currency must be a three-letter uppercase code.');
        }

        return $currency;
    }

    private static function cleanPaidAttachmentPrice(mixed $value, bool $enabled): int
    {
        $raw = (string) $value;
        if (!$enabled && $raw === '') {
            return 0;
        }
        if (preg_match('/^[0-9]{1,18}$/', $raw) !== 1) {
            if (!$enabled) {
                return 0;
            }
            throw new ContentException('Paid download price must be a positive integer minor-unit value.');
        }
        $amount = (int) $raw;
        if ($enabled && $amount <= 0) {
            throw new ContentException('Paid download price must be a positive integer minor-unit value.');
        }

        return $amount;
    }

    /** @return array<string, mixed> */
    private static function cleanData(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        $clean = [];
        foreach ($data as $key => $value) {
            if (!is_string($key) || strlen($key) > 64) {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $clean[$key] = is_string($value) ? trim($value) : $value;
            } elseif (is_array($value)) {
                $clean[$key] = json_decode(json_encode($value, JSON_UNESCAPED_UNICODE), true);
            }
        }

        return $clean;
    }

    private static function plain(string $value): string
    {
        $value = preg_replace('/<\s*script\b[^>]*>.*?<\s*\/\s*script\s*>/is', '', $value) ?? '';
        $value = preg_replace('/\son[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $value) ?? '';
        $value = preg_replace('/javascript\s*:/i', '', $value) ?? '';

        return trim(strip_tags($value));
    }

    /** @return list<string> */
    private static function plainList(mixed $items): array
    {
        if (is_string($items)) {
            $items = preg_split('/\R/', $items) ?: [];
        }
        if (!is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (mixed $item): string => self::plain((string) $item), $items), static fn (string $item): bool => $item !== ''));
    }

    /** @return list<list<string>> */
    private static function tableRows(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }
        $clean = [];
        foreach (array_slice($rows, 0, 50) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $clean[] = array_map(static fn (mixed $cell): string => self::plain((string) $cell), array_slice(array_values($row), 0, 12));
        }

        return $clean;
    }

    private static function safeUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return true;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true);
    }

    private static function safeMediaUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return true;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }
}
