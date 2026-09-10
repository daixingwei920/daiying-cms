<?php

declare(strict_types=1);

namespace Daiying\AffiliateHub;

final class FeedImportService
{
    /** @return array{headers:list<string>,rows:list<array<string,string>>,errors:list<string>} */
    public function parseCsv(string $csv, int $limit = 50): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        if (!is_array($lines) || count($lines) < 2) {
            return ['headers' => [], 'rows' => [], 'errors' => ['CSV 至少需要表头和一行商品数据。']];
        }

        $headers = array_map([$this, 'normalizeHeader'], str_getcsv((string) array_shift($lines), ',', '"', '\\'));
        $headers = array_values(array_filter($headers, static fn (string $header): bool => $header !== ''));
        if ($headers === []) {
            return ['headers' => [], 'rows' => [], 'errors' => ['CSV 表头不能为空。']];
        }

        $rows = [];
        $errors = [];
        foreach ($lines as $lineNumber => $line) {
            if (trim((string) $line) === '') {
                continue;
            }
            $values = str_getcsv((string) $line, ',', '"', '\\');
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = isset($values[$index]) ? trim((string) $values[$index]) : '';
            }
            if (implode('', $row) === '') {
                continue;
            }
            $rows[] = $row;
            if (count($rows) >= max(1, min(1000, $limit))) {
                break;
            }
            if (count($values) > count($headers)) {
                $errors[] = '第 ' . ($lineNumber + 2) . ' 行列数多于表头，已忽略多余列。';
            }
        }

        return ['headers' => $headers, 'rows' => $rows, 'errors' => array_slice($errors, 0, 20)];
    }

    /** @param list<array<string,string>> $rows @param array<string,string> $mapping @return list<array<string,mixed>> */
    public function mapRows(array $rows, array $mapping, int $limit = 200): array
    {
        $mapped = [];
        foreach (array_slice($rows, 0, max(1, min(1000, $limit))) as $row) {
            $item = [];
            foreach ($mapping as $target => $source) {
                $source = trim($source);
                if ($source === '') {
                    continue;
                }
                $item[$target] = $row[$source] ?? '';
            }
            $mapped[] = $item;
        }

        return $mapped;
    }

    /** @param list<string> $headers @return array<string,string> */
    public function suggestMapping(array $headers): array
    {
        $targets = [
            'external_product_id' => ['id', 'product_id', 'sku', 'item_id'],
            'name' => ['name', 'title', 'product_name', '商品名称'],
            'description' => ['description', 'desc', 'summary', '商品描述'],
            'brand' => ['brand', '品牌'],
            'advertiser_name' => ['merchant', 'advertiser', 'store', 'shop', '商家', '店铺'],
            'image_url' => ['image', 'image_url', 'img', 'picture', '图片'],
            'price_current' => ['price', 'sale_price', 'current_price', '价格'],
            'currency' => ['currency', '币种'],
            'availability' => ['availability', 'stock', '库存', '状态'],
            'destination_url' => ['url', 'link', 'destination_url', 'product_url', '商品链接'],
            'affiliate_url' => ['affiliate_url', 'tracking_url', 'deeplink', '推广链接'],
            'country' => ['country', 'market', '国家'],
            'language' => ['language', 'locale', '语言'],
        ];
        $normalized = [];
        foreach ($headers as $header) {
            $normalized[strtolower($header)] = $header;
        }

        $mapping = [];
        foreach ($targets as $target => $candidates) {
            $mapping[$target] = '';
            foreach ($candidates as $candidate) {
                $key = strtolower($candidate);
                if (isset($normalized[$key])) {
                    $mapping[$target] = $normalized[$key];
                    break;
                }
            }
        }

        return $mapping;
    }

    private function normalizeHeader(string $header): string
    {
        return trim(preg_replace('/\s+/', '_', $header) ?? $header);
    }
}
