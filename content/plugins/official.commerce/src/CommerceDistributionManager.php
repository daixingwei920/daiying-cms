<?php

declare(strict_types=1);

namespace Daiying\Commerce;

use Cms\Core\Support\Money;
use Throwable;

final class CommerceDistributionManager
{
    public function __construct(
        private readonly CommerceRepository $repo,
        private readonly ?CommerceAiModuleManager $aiManager = null,
        private $googleMerchantTransport = null,
    ) {
    }

    /** @param array<string,mixed> $product @return array<string,mixed> */
    public function productFeedItem(array $product, string $baseUrl = ''): array
    {
        $shareUrl = $this->shareUrl($product, $baseUrl);
        $imageUrl = $this->mediaUrl((int) ($product['primary_media_id'] ?? 0), $baseUrl);
        $pricing = $this->pricingFacts($product);

        return [
            'id' => (int) $product['id'],
            'sku' => (string) $product['sku'],
            'title' => (string) $product['name'],
            'description' => (string) ($product['summary'] ?? ''),
            'url' => $shareUrl,
            'share_url' => $shareUrl,
            'image_url' => $imageUrl,
            'price_minor' => (int) $product['price_minor'],
            'currency' => (string) $product['currency'],
            'price_display' => $this->money((int) $product['price_minor'], (string) $product['currency']),
            'availability' => (int) ($product['available_quantity'] ?? 0) > 0 ? 'in_stock' : 'out_of_stock',
            'brand' => (string) ($product['brand'] ?? ''),
            'model' => (string) ($product['model'] ?? ''),
            'region' => (string) ($product['region'] ?? ''),
            'transaction_region' => (string) ($product['transaction_region'] ?? ''),
            'verification_status' => (string) ($product['verification_status'] ?? 'not_provided'),
            'pricing' => $pricing,
            'updated_at' => (string) ($product['updated_at'] ?? ''),
            'published_at' => (string) ($product['published_at'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $product @return array<string,mixed> */
    public function shareCard(array $product, string $baseUrl = '', bool $allowPaidAi = false): array
    {
        $item = $this->productFeedItem($product, $baseUrl);
        $copy = $this->defaultShareCopy($item);
        $ai = ['ok' => false, 'message' => '未配置 AI 或 AI 不可用。'];
        if ($this->aiManager !== null) {
            $ai = $this->aiManager->runProductTask('share_copy', $product, ['allow_paid' => $allowPaidAi]);
            if (($ai['ok'] ?? false) === true && trim((string) ($ai['result'] ?? '')) !== '') {
                $copy = trim((string) $ai['result']);
            }
        }

        return [
            'feed_item' => $item,
            'title' => $item['title'],
            'summary' => $item['description'],
            'url' => $item['share_url'],
            'image_url' => $item['image_url'],
            'price_display' => $item['price_display'],
            'copy' => $copy,
            'ai' => [
                'used' => ($ai['ok'] ?? false) === true,
                'module_name' => $ai['module_name'] ?? null,
                'billing_type' => $ai['billing_type'] ?? null,
                'message' => $ai['message'] ?? null,
            ],
        ];
    }

    /** @param array<string,mixed> $product */
    public function shareUrl(array $product, string $baseUrl = ''): string
    {
        $path = '/commerce/product?id=' . (int) $product['id'];
        $baseUrl = rtrim($baseUrl, '/');

        return $baseUrl !== '' ? $baseUrl . $path : $path;
    }

    /** @param array<string,mixed> $product */
    public function recordManualShare(int $productId, ?int $channelId, array $payload): int
    {
        return $this->repo->recordDistributionEvent([
            'product_id' => $productId,
            'channel_id' => $channelId,
            'event_type' => 'manual_share',
            'status' => 'ready',
            'message' => '已生成分享链接和文案。',
            'payload' => $payload,
        ]);
    }

    /** @param array<string,mixed> $product @param array<string,mixed>|null $channel @param array<string,mixed> $context @return array<string,mixed> */
    public function publishProduct(array $product, ?array $channel, string $baseUrl = '', array $context = []): array
    {
        $card = $this->shareCard($product, $baseUrl, !empty($context['allow_paid_ai']));
        $feedItem = is_array($card['feed_item'] ?? null) ? $card['feed_item'] : $this->productFeedItem($product, $baseUrl);
        $pricing = is_array($feedItem['pricing'] ?? null) ? $feedItem['pricing'] : $this->pricingFacts($product);
        $providerType = is_array($channel) ? (string) ($channel['provider_type'] ?? 'manual_share') : 'manual_share';
        $config = is_array($channel['config'] ?? null) ? $channel['config'] : [];
        $provider = $providerType === 'google_merchant'
            ? new GoogleMerchantDistributionProvider($this->googleMerchantTransport)
            : new SystemShareDistributionProvider();
        $result = CommerceProviderIsolation::capture($provider->providerId(), 'publish_product', fn (): array => $provider->publishProduct($feedItem, $pricing, [
            'share_card' => $card,
            'config' => $config,
            'access_token' => (string) ($context['access_token'] ?? ''),
        ]));
        if (($result['ok'] ?? false) !== true) {
            return [
                'status' => 'failed',
                'provider' => $provider->providerId(),
                'message' => (string) ($result['error'] ?? '分发 Provider 暂不可用。'),
                'share_card' => $card,
                'payload' => [],
            ];
        }
        $providerResult = is_array($result['result'] ?? null) ? $result['result'] : [];
        $providerResult['share_card'] = $card;

        return $providerResult;
    }

    /** @param array<string,mixed> $item */
    private function defaultShareCopy(array $item): string
    {
        $parts = [(string) $item['title'], (string) $item['price_display']];
        if ((string) ($item['description'] ?? '') !== '') {
            $parts[] = (string) $item['description'];
        }
        $parts[] = (string) $item['share_url'];

        return implode("\n", $parts);
    }

    private function mediaUrl(int $mediaId, string $baseUrl): string
    {
        if ($mediaId <= 0) {
            return '';
        }
        $path = '/media/' . $mediaId;
        $baseUrl = rtrim($baseUrl, '/');

        return $baseUrl !== '' ? $baseUrl . $path : $path;
    }

    /** @param array<string,mixed> $product @return array<string,mixed> */
    private function pricingFacts(array $product): array
    {
        return [
            'base_minor' => (int) $product['price_minor'],
            'shipping_fee_minor' => (int) ($product['shipping_fee_minor'] ?? 0),
            'tax_fee_minor' => (int) ($product['tax_fee_minor'] ?? 0),
            'service_fee_minor' => (int) ($product['service_fee_minor'] ?? 0),
            'discount_minor' => (int) ($product['discount_minor'] ?? 0),
            'currency' => (string) $product['currency'],
            'note' => (string) ($product['price_note'] ?? ''),
        ];
    }

    private function money(int $minor, string $currency): string
    {
        try {
            return Money::format($minor, $currency, true);
        } catch (Throwable) {
            return (string) $minor . ' ' . $currency;
        }
    }
}
