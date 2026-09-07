<?php

declare(strict_types=1);

namespace Daiying\Commerce;

use RuntimeException;

final class GoogleMerchantDistributionProvider implements CommerceDistributionInterface
{
    /**
     * @param null|callable(string,array<string,string>,array<string,mixed>):array<string,mixed> $transport
     */
    public function __construct(private $transport = null)
    {
    }

    public function providerId(): string
    {
        return 'official.commerce.distribution.google_merchant';
    }

    public function publishProduct(array $productSnapshot, array $pricingSnapshot, array $context = []): array
    {
        $config = is_array($context['config'] ?? null) ? $context['config'] : [];
        $accountId = $this->cleanId((string) ($config['merchant_account_id'] ?? $context['merchant_account_id'] ?? ''));
        $dataSourceId = $this->cleanId((string) ($config['data_source_id'] ?? $context['data_source_id'] ?? ''));
        $accessToken = trim((string) ($context['access_token'] ?? ''));
        if ($accountId === '' || $dataSourceId === '') {
            throw new RuntimeException('Google Merchant 需要 merchant_account_id 和 data_source_id。');
        }
        if ($accessToken === '') {
            throw new RuntimeException('Google Merchant 需要服务端 OAuth access_token 或服务账号授权。');
        }
        $payload = $this->googleProductInput($productSnapshot, $pricingSnapshot, $config);
        $url = 'https://merchantapi.googleapis.com/products/v1/accounts/' . rawurlencode($accountId) .
            '/productInputs:insert?dataSource=' . rawurlencode('accounts/' . $accountId . '/dataSources/' . $dataSourceId);
        $response = $this->postJson($url, [
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json',
        ], $payload);
        $status = (int) ($response['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Google Merchant API HTTP ' . $status);
        }
        $body = is_array($response['body'] ?? null) ? $response['body'] : [];
        $externalId = (string) ($body['product'] ?? $body['name'] ?? $payload['offerId']);

        return [
            'status' => 'synced',
            'provider' => $this->providerId(),
            'external_listing_id' => $externalId,
            'message' => 'Google Merchant 商品输入已提交，实际展示状态以 Merchant Center 审核结果为准。',
            'payload' => [
                'request_url' => $url,
                'product_input' => $payload,
                'response_name' => (string) ($body['name'] ?? ''),
                'response_product' => (string) ($body['product'] ?? ''),
            ],
        ];
    }

    public function syncListing(string $externalListingId, array $context = []): array
    {
        return [
            'status' => 'synced',
            'provider' => $this->providerId(),
            'external_listing_id' => $externalListingId,
            'message' => 'Google Merchant 同步状态由 Merchant Center 处理结果决定。',
        ];
    }

    public function unpublishProduct(string $externalListingId, array $context = []): array
    {
        return [
            'status' => 'failed',
            'provider' => $this->providerId(),
            'external_listing_id' => $externalListingId,
            'message' => 'Google Merchant 下架需要后续接入 delete ProductInput，本版本不自动下架。',
        ];
    }

    /** @param array<string,mixed> $product @param array<string,mixed> $pricing @param array<string,mixed> $config @return array<string,mixed> */
    public function googleProductInput(array $product, array $pricing, array $config = []): array
    {
        $language = strtolower(substr(preg_replace('/[^A-Za-z-]+/', '', (string) ($config['content_language'] ?? 'zh-CN')) ?: 'zh-CN', 0, 16));
        $feedLabel = strtoupper(substr(preg_replace('/[^A-Za-z0-9_-]+/', '', (string) ($config['feed_label'] ?? $product['region'] ?? 'CN')) ?: 'CN', 0, 20));
        $attributes = [
            'title' => (string) ($product['title'] ?? $product['name'] ?? ''),
            'description' => (string) ($product['description'] ?? $product['summary'] ?? ''),
            'link' => (string) ($product['url'] ?? $product['share_url'] ?? ''),
            'availability' => strtoupper((string) ($product['availability'] ?? 'in_stock')) === 'IN_STOCK' ? 'IN_STOCK' : 'OUT_OF_STOCK',
            'price' => [
                'amountMicros' => (string) ((int) ($product['price_minor'] ?? $pricing['base_minor'] ?? 0) * 10000),
                'currencyCode' => (string) ($product['currency'] ?? $pricing['currency'] ?? 'CNY'),
            ],
            'condition' => 'NEW',
        ];
        if ((string) ($product['image_url'] ?? '') !== '') {
            $attributes['imageLink'] = (string) $product['image_url'];
        }
        if ((string) ($product['brand'] ?? '') !== '') {
            $attributes['brand'] = (string) $product['brand'];
        }
        if ((string) ($product['model'] ?? '') !== '') {
            $attributes['mpn'] = (string) $product['model'];
        }

        return [
            'offerId' => (string) ($product['sku'] ?? $product['id'] ?? ''),
            'contentLanguage' => $language,
            'feedLabel' => $feedLabel,
            'productAttributes' => $attributes,
        ];
    }

    /** @param array<string,string> $headers @param array<string,mixed> $payload @return array<string,mixed> */
    private function postJson(string $url, array $headers, array $payload): array
    {
        $transport = $this->transport;
        if (is_callable($transport)) {
            return $transport($url, $headers, $payload);
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL 不可用。');
        }
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException($error !== '' ? $error : 'Google Merchant API 无响应。');
        }
        $decoded = json_decode($raw, true);

        return ['status' => $status, 'body' => is_array($decoded) ? $decoded : ['raw' => $raw]];
    }

    private function cleanId(string $value): string
    {
        return substr(preg_replace('/[^A-Za-z0-9_-]+/', '', trim($value)) ?? '', 0, 96);
    }
}
