<?php

declare(strict_types=1);

namespace Cms\Core\Content;

use Cms\Core\Security\CsrfToken;
use Cms\Core\Support\Money;

final class BlockRenderer
{
    /** @param array<int,array<string,mixed>> $media @param array<int,array<string,mixed>> $cardProducts @param array<string,list<array{id:string,label:string}>>|list<array{id:string,label:string}> $paymentProviders */
    public function __construct(
        private readonly array $media = [],
        private readonly array $cardProducts = [],
        private readonly array $paymentProviders = [],
        private readonly int $contentId = 0,
    )
    {
    }

    /** @param list<array<string, mixed>> $blocks */
    public function render(array $blocks): string
    {
        $html = '';
        foreach ($blocks as $index => $block) {
            $type = (string) ($block['type'] ?? '');
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $html .= match ($type) {
                'paragraph' => $this->paragraph($data),
                'heading' => $this->heading($data),
                'unordered_list' => $this->list($data, 'ul'),
                'ordered_list' => $this->list($data, 'ol'),
                'quote' => '<blockquote><p>' . $this->e($data['text'] ?? '') . '</p>' . (((string) ($data['cite'] ?? '')) !== '' ? '<cite>' . $this->e($data['cite']) . '</cite>' : '') . '</blockquote>',
                'code' => '<pre><code' . (((string) ($data['language'] ?? '')) !== '' ? ' class="language-' . $this->e($data['language']) . '"' : '') . '>' . $this->e($data['code'] ?? '') . '</code></pre>',
                'divider' => $this->divider($data),
                'button' => $this->button($data),
                'table' => $this->table($data),
                'html' => '<div class="content-html-block">' . (string) ($data['html'] ?? '') . '</div>',
                'raw_text' => '<pre class="raw-text">' . $this->e($data['text'] ?? '') . '</pre>',
                'image' => $this->image($data),
                'gallery' => $this->gallery($data),
                'audio' => $this->audio($data),
                'video' => $this->video($data),
                'attachment' => $this->attachment($data),
                'card_delivery' => $this->cardDelivery($data),
                'tip' => $this->tip($data, (int) $index),
                'missing-extension' => '<div class="missing-extension">此内容需要插件：' . $this->e($block['plugin_id'] ?? 'unknown') . '</div>',
                default => '',
            };
        }

        return $html;
    }

    /** @param array<string,mixed> $data */
    private function image(array $data): string
    {
        $media = $this->media[(int) ($data['media_id'] ?? 0)] ?? null;
        if (!is_array($media) || !($media['available'] ?? false) || ($media['media_type'] ?? '') !== 'image') {
            return '<figure class="media-missing">Image unavailable</figure>';
        }
        $alt = (string) ($data['alt'] ?: $media['alt_text'] ?? '');
        $width = (int) ($data['width'] ?? 0);
        $widthAttr = $width > 0 ? ' width="' . $width . '"' : '';
        $img = '<img src="' . $this->e($media['url'] ?? '') . '" alt="' . $this->e($alt) . '" loading="lazy"' . $widthAttr . '>';
        $link = (string) ($data['link'] ?? '');
        if ($link !== '') {
            $img = '<a href="' . $this->e($link) . '">' . $img . '</a>';
        }
        $caption = (string) ($data['caption'] ?? '');

        return '<figure class="media-image align-' . $this->e($data['alignment'] ?? 'none') . '">' . $img . ($caption !== '' ? '<figcaption>' . $this->e($caption) . '</figcaption>' : '') . '</figure>';
    }

    /** @param array<string,mixed> $data */
    private function gallery(array $data): string
    {
        $columns = max(1, min(6, (int) ($data['columns'] ?? 3)));
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $ids = is_array($data['media_ids'] ?? null) ? $data['media_ids'] : [];
        $html = '<div class="media-gallery" role="list" aria-label="Image gallery" style="--columns:' . $columns . '">';
        foreach ($ids as $offset => $id) {
            $media = $this->media[(int) $id] ?? null;
            if (!is_array($media) || !($media['available'] ?? false) || ($media['media_type'] ?? '') !== 'image') {
                $html .= '<figure role="listitem" class="media-missing">Image unavailable</figure>';
                continue;
            }
            $item = is_array($items[$offset] ?? null) ? $items[$offset] : [];
            $alt = (string) ($item['alt'] ?: $media['alt_text'] ?? '');
            $caption = (string) ($item['caption'] ?? '');
            $html .= '<figure role="listitem" tabindex="0"><img src="' . $this->e($media['url'] ?? '') . '" alt="' . $this->e($alt) . '" loading="lazy">' . ($caption !== '' ? '<figcaption>' . $this->e($caption) . '</figcaption>' : '') . '</figure>';
        }

        return $html . '</div>';
    }

    /** @param array<string,mixed> $data */
    private function audio(array $data): string
    {
        $media = $this->media[(int) ($data['media_id'] ?? 0)] ?? null;
        if (!is_array($media) || !($media['available'] ?? false) || ($media['media_type'] ?? '') !== 'audio') {
            return '<div class="media-missing">Audio unavailable</div>';
        }
        $controls = ($data['controls'] ?? true) ? ' controls' : '';
        $preload = in_array(($data['preload'] ?? 'metadata'), ['none', 'metadata', 'auto'], true) ? (string) $data['preload'] : 'metadata';
        $title = (string) ($data['title'] ?: $media['title'] ?? '');

        return '<figure class="media-audio">' . ($title !== '' ? '<figcaption>' . $this->e($title) . '</figcaption>' : '') . '<audio' . $controls . ' preload="' . $this->e($preload) . '"><source src="' . $this->e($media['url'] ?? '') . '" type="' . $this->e($media['mime_type'] ?? '') . '">Your browser does not support HTML5 audio.</audio></figure>';
    }

    /** @param array<string,mixed> $data */
    private function video(array $data): string
    {
        $media = $this->media[(int) ($data['media_id'] ?? 0)] ?? null;
        $sourceUrl = '';
        $mimeType = '';
        if (is_array($media) && ($media['available'] ?? false) && ($media['media_type'] ?? '') === 'video') {
            $sourceUrl = (string) ($media['url'] ?? '');
            $mimeType = (string) ($media['mime_type'] ?? '');
        } elseif ((string) ($data['source_url'] ?? '') !== '') {
            $sourceUrl = (string) $data['source_url'];
        }
        if ($sourceUrl === '') {
            return '<div class="media-missing">Video unavailable</div>';
        }
        $poster = '';
        $posterMedia = $this->media[(int) ($data['poster_media_id'] ?? 0)] ?? null;
        if (is_array($posterMedia) && ($posterMedia['available'] ?? false) && ($posterMedia['media_type'] ?? '') === 'image') {
            $poster = ' poster="' . $this->e($posterMedia['url'] ?? '') . '"';
        }
        $controls = ($data['controls'] ?? true) ? ' controls' : '';
        $autoplay = ($data['autoplay'] ?? false) ? ' autoplay' : '';
        $muted = ($data['muted'] ?? false) ? ' muted' : '';
        $loop = ($data['loop'] ?? false) ? ' loop' : '';
        $playsinline = ($data['playsinline'] ?? true) ? ' playsinline' : '';
        $preload = in_array(($data['preload'] ?? 'metadata'), ['none', 'metadata', 'auto'], true) ? (string) $data['preload'] : 'metadata';
        $type = $mimeType !== '' ? ' type="' . $this->e($mimeType) . '"' : '';

        return '<figure class="media-video"><video' . $controls . $autoplay . $muted . $loop . $playsinline . ' preload="' . $this->e($preload) . '"' . $poster . '><source src="' . $this->e($sourceUrl) . '"' . $type . '>Your browser does not support HTML5 video.</video></figure>';
    }

    /** @param array<string,mixed> $data */
    private function attachment(array $data): string
    {
        $media = $this->media[(int) ($data['media_id'] ?? 0)] ?? null;
        if (!is_array($media) || !($media['available'] ?? false) || ($media['media_type'] ?? '') !== 'attachment') {
            return '<div class="media-missing">Attachment unavailable</div>';
        }
        $name = (string) ($data['display_name'] ?: $media['title'] ?? $media['filename'] ?? 'Download');
        $size = number_format(((int) ($media['byte_size'] ?? 0)) / 1024, 1) . ' KB';
        $paid = is_array($media['paid_download'] ?? null) ? $media['paid_download'] : [];
        if (($paid['enabled'] ?? false) && !($paid['authorized'] ?? false)) {
            $price = $this->safePaymentPrice($paid['amount_minor'] ?? null, $paid['currency'] ?? null);
            $checkoutUrl = $this->safeCheckoutPath((string) ($paid['checkout_url'] ?? ''), '/paid-download/');
            if ($price === null || $checkoutUrl === '' || ($paid['available'] ?? true) !== true || count(is_array($paid['payment_providers'] ?? null) ? $paid['payment_providers'] : []) === 0) {
                return '<div class="media-attachment media-attachment-paid"><strong>' . $this->e($name) . '</strong> <span>' . $this->e((string) ($media['extension'] ?? 'file')) . ' · ' . $this->e($size) . '</span><p>支付配置暂不可用，文件仍由 CMS Core 锁定。</p></div>';
            }
            return '<div class="media-attachment media-attachment-paid"><strong>' . $this->e($name) . '</strong> <span>' . $this->e((string) ($media['extension'] ?? 'file')) . ' · ' . $this->e($size) . '</span><form method="post" action="' . $this->e($checkoutUrl) . '">' . CsrfToken::field() . $this->paymentProviderFields(is_array($paid['payment_providers'] ?? null) ? $paid['payment_providers'] : []) . '<button type="submit">' . $this->e((string) ($paid['label'] ?? '解锁下载')) . ' · ' . $this->e($price) . '</button></form></div>';
        }

        $downloadUrl = (string) ($media['download_url'] ?? '');
        if (($paid['enabled'] ?? false) && ($paid['authorized'] ?? false)) {
            $downloadUrl = $this->safeAuthorizedDownloadPath($downloadUrl);
            if ($downloadUrl === '') {
                return '<div class="media-attachment media-attachment-paid"><strong>' . $this->e($name) . '</strong> <span>' . $this->e((string) ($media['extension'] ?? 'file')) . ' · ' . $this->e($size) . '</span><p>支付配置暂不可用，文件仍由 CMS Core 锁定。</p></div>';
            }
        }

        return '<p class="media-attachment"><a href="' . $this->e($downloadUrl) . '">' . $this->e($name) . '</a> <span>' . $this->e((string) ($media['extension'] ?? 'file')) . ' · ' . $this->e($size) . '</span></p>';
    }

    /** @param array<string,mixed> $data */
    private function cardDelivery(array $data): string
    {
        $productId = (int) ($data['card_product_id'] ?? 0);
        $product = $this->cardProducts[$productId] ?? null;
        if ($productId <= 0 || !is_array($product) || ($product['status'] ?? '') !== 'active') {
            return '<section class="card-delivery-block card-delivery-unavailable" data-card-product-id="' . $this->e((string) $productId) . '"><p>发卡商品暂不可用。</p></section>';
        }

        $name = (string) ($product['name'] ?? '发卡商品');
        $price = $this->safePaymentPrice((int) ($product['price_minor'] ?? 0), (string) ($product['currency'] ?? 'USD'));
        $stock = max(0, (int) ($product['available_count'] ?? 0));
        $maxQuantity = max(1, min((int) ($product['max_quantity_per_order'] ?? 1), max(1, $stock)));
        $buttonText = $this->safePaymentLabel((string) ($data['button_text'] ?? '立即购买'), '立即购买');
        $html = '<section class="card-delivery-block" data-card-product-id="' . $productId . '">';
        if (($data['show_name'] ?? true) === true) {
            $html .= '<h2 class="card-delivery-title">' . $this->e($name) . '</h2>';
        }
        if (($data['show_price'] ?? true) === true && $price !== null) {
            $html .= '<p class="card-delivery-price">价格：' . $this->e($price) . '</p>';
        }
        if (($data['show_stock'] ?? true) === true) {
            $html .= '<p class="card-delivery-stock">剩余库存：' . $stock . '</p>';
        }
        if (($data['show_button'] ?? true) === true && $stock <= 0) {
            $html .= '<p class="card-delivery-sold-out">暂时缺货</p>';
        } elseif (($data['show_button'] ?? true) === true) {
            $quantityField = $maxQuantity > 1
                ? '<label>数量<input name="quantity" type="number" min="1" max="' . $maxQuantity . '" value="1"></label>'
                : '<input type="hidden" name="quantity" value="1">';
            $html .= '<form method="post" action="/card-delivery/' . $productId . '/checkout">' . CsrfToken::field() . $quantityField . '<button type="submit">' . $this->e($buttonText !== '' ? $buttonText : '立即购买') . '</button></form>';
        }

        return $html . '</section>';
    }

    /** @param array<string,mixed> $data */
    private function tip(array $data, int $blockIndex): string
    {
        $title = $this->safePaymentLabel((string) ($data['title'] ?? '喜欢这篇内容？'), '喜欢这篇内容？');
        $description = $this->safePaymentDescription((string) ($data['description'] ?? '可以打赏支持作者继续创作。'));
        $buttonText = $this->safePaymentLabel((string) ($data['button_text'] ?? '打赏支持'), '打赏支持');
        $currency = is_string($data['currency'] ?? null) ? (string) $data['currency'] : 'USD';
        $amounts = is_array($data['amounts'] ?? null) ? $data['amounts'] : [];
        $cleanAmounts = [];
        foreach ($amounts as $amount) {
            if (!is_string($amount) && !is_int($amount)) {
                continue;
            }
            $amount = trim((string) $amount);
            try {
                $label = Money::format(Money::toMinor($amount, $currency), $currency);
                $cleanAmounts[] = ['value' => $amount, 'label' => $label];
            } catch (\InvalidArgumentException) {
                continue;
            }
        }
        $providers = $this->tipPaymentProviders($currency);
        if ($this->contentId <= 0 || $cleanAmounts === [] || count($providers) === 0) {
            return '<section class="content-tip-block content-tip-unavailable"><h2>' . $this->e($title) . '</h2><p>打赏暂不可用，请联系网站管理员。</p></section>';
        }

        $amountButtons = '<fieldset class="tip-amount-options"><legend>打赏金额</legend>';
        foreach ($cleanAmounts as $index => $amount) {
            $amountButtons .= '<label><input type="radio" name="amount" value="' . $this->e((string) $amount['value']) . '"' . ($index === 0 ? ' checked' : '') . '> ' . $this->e((string) $amount['label']) . '</label>';
        }
        $amountButtons .= '</fieldset>';
        $custom = ($data['custom_amount'] ?? true) === true
            ? '<label class="tip-custom-amount">自定义金额<input name="custom_amount" inputmode="decimal" placeholder="' . $this->e((string) ($cleanAmounts[0]['value'] ?? '1.00')) . '"></label>'
            : '';

        return '<section class="content-tip-block"><h2>' . $this->e($title) . '</h2>' .
            ($description !== '' ? '<p>' . $this->e($description) . '</p>' : '') .
            '<form method="post" action="/tips/checkout">' . CsrfToken::field() .
            '<input type="hidden" name="content_id" value="' . $this->contentId . '">' .
            '<input type="hidden" name="block_index" value="' . $blockIndex . '">' .
            $amountButtons . $custom . $this->paymentProviderFields($providers) .
            '<button type="submit">' . $this->e($buttonText) . '</button></form></section>';
    }

    /** @return list<array{id:string,label:string}> */
    private function tipPaymentProviders(string $currency): array
    {
        $byCurrency = $this->paymentProviders[$currency] ?? null;
        if (is_array($byCurrency)) {
            return array_values(array_filter($byCurrency, static fn (mixed $provider): bool => is_array($provider)));
        }
        if (isset($this->paymentProviders[0]) && is_array($this->paymentProviders[0])) {
            return array_values(array_filter($this->paymentProviders, static fn (mixed $provider): bool => is_array($provider)));
        }

        return [];
    }

    /** @param list<array{id:string,label:string}> $providers */
    private function paymentProviderFields(array $providers): string
    {
        if (count($providers) === 1) {
            $id = (string) ($providers[0]['id'] ?? '');
            return $this->safeProviderId($id) ? '<input type="hidden" name="provider_id" value="' . $this->e($id) . '">' : '';
        }
        if (count($providers) < 2) {
            return '';
        }

        $html = '<fieldset class="payment-provider-options"><legend>支付方式</legend>';
        foreach ($providers as $index => $provider) {
            $id = (string) ($provider['id'] ?? '');
            if (!$this->safeProviderId($id)) {
                continue;
            }
            $label = $this->safePaymentLabel((string) ($provider['label'] ?? $id), $id);
            $html .= '<label><input type="radio" name="provider_id" value="' . $this->e($id) . '"' . ($html === '<fieldset class="payment-provider-options"><legend>支付方式</legend>' ? ' checked' : '') . '> ' . $this->e($label) . '</label>';
        }

        return $html . '</fieldset>';
    }

    private function safeProviderId(string $id): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9._-]{1,95}[a-z0-9]$/', $id) === 1;
    }

    private function safePaymentLabel(string $label, string $fallback): string
    {
        if ($label === '' || $label !== trim($label) || strlen($label) > 191 || preg_match('/[\x00-\x1F\x7F]/', $label) === 1) {
            return $fallback;
        }

        return $label;
    }

    private function safePaymentDescription(string $description): string
    {
        if ($description !== trim($description) || strlen($description) > 1000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $description) === 1) {
            return '';
        }

        return $description;
    }

    private function safePaymentPrice(mixed $amountMinor, mixed $currency): ?string
    {
        if (!is_int($amountMinor) || $amountMinor <= 0) {
            return null;
        }
        if (!is_string($currency)) {
            return null;
        }

        try {
            return Money::format($amountMinor, $currency);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function safeCheckoutPath(string $path, string $prefix): string
    {
        if ($path === '' || $path !== trim($path) || strlen($path) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return '';
        }

        $pattern = $prefix === '/paid-content/'
            ? '#^/paid-content/[1-9][0-9]{0,17}/checkout$#'
            : '#^/paid-download/[1-9][0-9]{0,17}/[1-9][0-9]{0,17}/checkout$#';
        if (preg_match($pattern, $path) !== 1) {
            return '';
        }

        return $path;
    }

    private function safeAuthorizedDownloadPath(string $path): string
    {
        if ($path === '' || $path !== trim($path) || strlen($path) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return '';
        }
        if (!str_starts_with($path, '/media/') || str_starts_with($path, '//')) {
            return '';
        }
        $parts = parse_url($path);
        if (!is_array($parts) || (string) ($parts['path'] ?? '') === '' || !isset($parts['query'])) {
            return '';
        }
        if (preg_match('#^/media/[1-9][0-9]{0,17}$#', (string) ($parts['path'] ?? '')) !== 1) {
            return '';
        }
        parse_str((string) $parts['query'], $query);
        $download = $query['download'] ?? '';
        $contentId = $query['content_id'] ?? '';
        $token = $query['payment_token'] ?? '';
        if (!is_string($download) || !is_string($contentId) || !is_string($token)) {
            return '';
        }
        if ($download !== '1'
            || preg_match('/^[1-9][0-9]{0,17}$/', $contentId) !== 1
            || preg_match('/^[A-Za-z0-9_-]{16,768}\.[A-Za-z0-9_-]{32,128}$/', $token) !== 1
        ) {
            return '';
        }

        return $path;
    }

    /** @param array<string, mixed> $data */
    private function paragraph(array $data): string
    {
        $rawAlignment = (string) ($data['alignment'] ?? 'left');
        $rawStyle = (string) ($data['style'] ?? 'body');
        $alignment = in_array($rawAlignment, ['left', 'center', 'right'], true) ? $rawAlignment : 'left';
        $style = in_array($rawStyle, ['body', 'lead', 'small'], true) ? $rawStyle : 'body';
        $text = $this->e($data['text'] ?? '');
        if (($data['bold'] ?? false) === true) {
            $text = '<strong>' . $text . '</strong>';
        }
        if (($data['italic'] ?? false) === true) {
            $text = '<em>' . $text . '</em>';
        }
        $link = (string) ($data['link'] ?? '');
        if ($link !== '') {
            $text = '<a href="' . $this->e($link) . '">' . $text . '</a>';
        }

        $classes = [];
        if ($style !== 'body') {
            $classes[] = 'paragraph-' . $style;
        }
        if ($alignment !== 'left') {
            $classes[] = 'text-align-' . $alignment;
        }
        $class = $classes === [] ? '' : ' class="' . $this->e(implode(' ', $classes)) . '"';

        return '<p' . $class . '>' . $text . '</p>';
    }

    /** @param array<string, mixed> $data */
    private function divider(array $data): string
    {
        $style = in_array(($data['style'] ?? 'solid'), ['solid', 'dashed', 'wide'], true) ? (string) $data['style'] : 'solid';
        $spacing = in_array(($data['spacing'] ?? 'normal'), ['compact', 'normal', 'large'], true) ? (string) $data['spacing'] : 'normal';

        return '<hr class="divider-' . $this->e($style) . ' divider-spacing-' . $this->e($spacing) . '">';
    }

    /** @param array<string, mixed> $data */
    private function heading(array $data): string
    {
        $level = max(1, min(6, (int) ($data['level'] ?? 2)));
        $alignment = in_array(($data['alignment'] ?? 'left'), ['left', 'center', 'right'], true) ? (string) $data['alignment'] : 'left';

        $class = $alignment === 'left' ? '' : ' class="text-align-' . $this->e($alignment) . '"';

        return '<h' . $level . $class . '>' . $this->e($data['text'] ?? '') . '</h' . $level . '>';
    }

    /** @param array<string, mixed> $data */
    private function list(array $data, string $tag): string
    {
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $html = '<' . $tag . '>';
        foreach ($items as $item) {
            $html .= '<li>' . $this->e($item) . '</li>';
        }

        return $html . '</' . $tag . '>';
    }

    /** @param array<string, mixed> $data */
    private function button(array $data): string
    {
        $url = (string) ($data['url'] ?? '');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($url !== '' && !str_starts_with($url, '/') && !str_starts_with($url, '#') && !in_array($scheme, ['http', 'https', 'mailto', 'tel'], true)) {
            $url = '#';
        }
        $target = (string) ($data['target'] ?? '_self');
        $target = in_array($target, ['_self', '_blank'], true) ? $target : '_self';
        $rel = $target === '_blank' ? ' rel="noopener noreferrer"' : '';

        $style = in_array(($data['style'] ?? 'primary'), ['primary', 'secondary', 'outline'], true) ? (string) $data['style'] : 'primary';
        $alignment = in_array(($data['alignment'] ?? 'left'), ['left', 'center', 'right'], true) ? (string) $data['alignment'] : 'left';

        return '<p class="button-align-' . $this->e($alignment) . '"><a class="content-button content-button-' . $this->e($style) . '" href="' . $this->e($url !== '' ? $url : '#') . '" target="' . $this->e($target) . '"' . $rel . '>' . $this->e($data['text'] ?? 'Button') . '</a></p>';
    }

    /** @param array<string, mixed> $data */
    private function table(array $data): string
    {
        $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
        $html = '<table><tbody>';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . $this->e($cell) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
    }

    private function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
