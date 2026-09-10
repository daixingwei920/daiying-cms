<?php

declare(strict_types=1);

namespace Daiying\AffiliateHub;

use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Security\CsrfToken;

final class AffiliateController
{
    public function __construct(private readonly AffiliateRepository $repo)
    {
    }

    public function dashboard(Request $request): Response
    {
        $stats = $this->repo->stats();
        $cards = '';
        foreach ([
            'products' => '商品',
            'active_products' => '已发布',
            'offers' => 'Offer',
            'clicks' => '点击',
        ] as $key => $label) {
            $cards .= '<div style="border:1px solid #d9e2ef;border-radius:8px;padding:18px"><strong style="font-size:28px">' . (int) $stats[$key] . '</strong><div class="muted">' . $this->e($label) . '</div></div>';
        }

        return Response::html($this->shell('联盟商城', '<p class="muted">统一管理 Affiliate Product、Offer、点击跳转和平台 Adapter。联盟商品只导流到广告主，不走本站支付、订单或物流。</p><div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:18px 0">' . $cards . '</div><p><a class="button" href="/admin/affiliate-hub/products">商品列表</a> <a class="button" href="/admin/affiliate-hub/products/new">新增手工联盟商品</a></p>'));
    }

    public function products(Request $request): Response
    {
        $rows = '';
        foreach ($this->repo->products() as $product) {
            $offers = $this->repo->offersForProduct((int) $product['id']);
            $offer = $offers[0] ?? null;
            $price = $this->money($product['price_current'] ?? null, (string) ($product['currency'] ?? ''));
            $go = is_array($offer) ? '<a class="button" target="_blank" rel="noopener" href="/go/affiliate?offer_id=' . (int) $offer['id'] . '">测试跳转</a>' : '-';
            $rows .= '<tr><td>' . (int) $product['id'] . '</td><td>' . $this->e((string) ($product['display_title'] ?: $product['name_original'])) . '</td><td>' . $this->e((string) $product['provider_id']) . '</td><td>' . $this->e((string) ($product['advertiser_name'] ?? '')) . '</td><td>' . $this->e($price) . '</td><td>' . $this->e((string) $product['status']) . '</td><td>' . $go . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="7">暂无联盟商品。</td></tr>';
        }

        return Response::html($this->shell('联盟商品', '<p><a class="button" href="/admin/affiliate-hub/products/new">新增手工联盟商品</a></p><table><thead><tr><th>ID</th><th>商品</th><th>来源</th><th>商家</th><th>价格</th><th>状态</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>'));
    }

    public function newProduct(Request $request): Response
    {
        return Response::html($this->shell('新增手工联盟商品', $this->manualForm()));
    }

    public function saveProduct(Request $request): Response
    {
        try {
            $id = $this->repo->saveManualProduct($request->body);
        } catch (\Throwable $exception) {
            return Response::html($this->shell('新增手工联盟商品', '<div class="alert alert-error">保存失败：' . $this->e($exception->getMessage()) . '</div>' . $this->manualForm($request->body)), 422);
        }

        return Response::redirect('/admin/affiliate-hub/products?saved=' . $id);
    }

    public function go(Request $request): Response
    {
        $offerId = (int) $request->input('offer_id', 0);
        $offer = $this->repo->offer($offerId);
        if ($offer === null || (string) ($offer['status'] ?? '') !== 'active') {
            return Response::text('Affiliate offer is unavailable.', 404);
        }
        $product = $this->repo->product((int) $offer['product_id']);
        if ($product === null || (string) ($product['status'] ?? '') !== 'active') {
            return Response::text('Affiliate product is unavailable.', 404);
        }
        $target = (string) $offer['affiliate_url'];
        $this->assertRedirectUrl($target);
        $this->repo->recordClick($offer, $request->server, (string) ($request->server['REQUEST_URI'] ?? $request->path));

        return Response::redirect($target, 302);
    }

    /** @param array<string,mixed> $old */
    private function manualForm(array $old = []): string
    {
        $value = fn (string $key): string => $this->e((string) ($old[$key] ?? ''));
        $status = (string) ($old['status'] ?? 'draft');

        return '<form method="post" action="/admin/affiliate-hub/products/save">' . CsrfToken::field() .
            '<label>商品名称<input name="name" required value="' . $value('name') . '"></label>' .
            '<label>商家/广告主<input name="advertiser_name" value="' . $value('advertiser_name') . '"></label>' .
            '<label>品牌<input name="brand" value="' . $value('brand') . '"></label>' .
            '<label>目标商品 URL<input name="destination_url" type="url" required placeholder="https://merchant.example/product" value="' . $value('destination_url') . '"></label>' .
            '<label>Affiliate URL<input name="affiliate_url" type="url" required placeholder="https://tracking.example/..." value="' . $value('affiliate_url') . '"></label>' .
            '<label>图片 URL<input name="image_url" type="url" value="' . $value('image_url') . '"></label>' .
            '<label>价格<input name="price_current" inputmode="decimal" placeholder="29.99" value="' . $value('price_current') . '"></label>' .
            '<label>币种<input name="currency" maxlength="3" placeholder="USD/CNY" value="' . $value('currency') . '"></label>' .
            '<label>国家/市场<input name="country" maxlength="8" placeholder="US/CN" value="' . $value('country') . '"></label>' .
            '<label>语言<input name="language" maxlength="16" placeholder="zh-CN/en-US" value="' . $value('language') . '"></label>' .
            '<label>状态<select name="status">' . $this->options(['draft' => '草稿', 'active' => '发布', 'inactive' => '停用'], $status) . '</select></label>' .
            '<label><input type="checkbox" name="indexable" value="1"' . (!empty($old['indexable']) ? ' checked' : '') . '> 允许收录</label>' .
            '<label>披露说明<input name="disclosure_text" value="' . ($value('disclosure_text') ?: '本文包含联盟推广链接，成交后站点可能获得佣金。') . '"></label>' .
            '<p class="muted">手工联盟商品不会创建本站订单，也不会调用 Stripe/PayPal。购买按钮只通过 /go/affiliate 记录点击并跳转。</p>' .
            '<button type="submit">保存</button> <a class="button admin-button-secondary" href="/admin/affiliate-hub/products">返回</a></form>';
    }

    /** @param array<string,string> $items */
    private function options(array $items, string $selected): string
    {
        $html = '';
        foreach ($items as $value => $label) {
            $html .= '<option value="' . $this->e($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }

        return $html;
    }

    private function shell(string $title, string $body): string
    {
        return '<main class="admin-main"><h1>' . $this->e($title) . '</h1><nav style="margin:0 0 18px"><a href="/admin/affiliate-hub">总览</a> · <a href="/admin/affiliate-hub/products">商品</a> · <a href="/admin/affiliate-hub/products/new">手工商品</a></nav>' . $body . '</main>';
    }

    private function money(mixed $amount, string $currency): string
    {
        if ($amount === null || $amount === '') {
            return '未提供';
        }

        return rtrim(rtrim(number_format((float) $amount, 4, '.', ''), '0'), '.') . ($currency !== '' ? ' ' . $currency : '');
    }

    private function assertRedirectUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            throw new \RuntimeException('Affiliate redirect target is invalid.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('Affiliate redirect target cannot include user info.');
        }
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
