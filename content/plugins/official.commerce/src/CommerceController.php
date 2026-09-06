<?php

declare(strict_types=1);

namespace Daiying\Commerce;

use Cms\Core\Config\Settings;
use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Payment\PaymentRepository;
use Cms\Core\Payment\PaymentService;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Support\CurrencyRegistry;
use Cms\Core\Support\Money;
use Cms\Core\Support\View;
use PDO;
use Throwable;

final class CommerceController
{
    public function __construct(
        private readonly CommerceRepository $repo,
        private readonly PDO $pdo,
        private readonly Settings $settings,
    ) {
    }

    public function adminDashboard(Request $request): Response
    {
        $stats = $this->repo->stats();
        $body = '<h1>Daiying Commerce</h1><p class="muted">快速、透明、自主成交的轻量销售系统。</p>' .
            '<div class="admin-stat-grid">' .
            $this->stat('商品', (string) $stats['products']) .
            $this->stat('可售商品', (string) $stats['active_products']) .
            $this->stat('订单', (string) $stats['orders']) .
            $this->stat('已支付', (string) $stats['paid_orders']) .
            '</div>' .
            '<p><a class="button" href="/admin/commerce/products/new">新建商品</a> <a class="button admin-button-secondary" href="/admin/commerce/products">商品管理</a> <a class="button admin-button-secondary" href="/admin/commerce/orders">订单管理</a></p>';

        return Response::html(View::page('Commerce', $body));
    }

    public function adminProducts(Request $request): Response
    {
        $rows = '';
        foreach ($this->repo->products() as $product) {
            $status = (string) ($product['status'] ?? '');
            $rows .= '<tr><td><strong>' . $this->e((string) $product['name']) . '</strong><br><code>' . $this->e((string) $product['sku']) . '</code></td>' .
                '<td>' . $this->money((int) $product['price_minor'], (string) $product['currency']) . '<br><span class="muted">' . $this->e((string) $product['region']) . '</span></td>' .
                '<td>' . $this->e($status) . '<br><span class="muted">可售 ' . (int) $product['available_quantity'] . '</span></td>' .
                '<td><a class="button" href="/admin/commerce/products/edit?id=' . (int) $product['id'] . '">编辑</a> <a class="button admin-button-secondary" href="/commerce/product?id=' . (int) $product['id'] . '" target="_blank" rel="noopener">前台</a></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="muted">还没有商品。</td></tr>';
        }
        $body = '<h1>商品管理</h1><p><a class="button" href="/admin/commerce/products/new">新建商品</a> <a class="button admin-button-secondary" href="/admin/commerce">返回总览</a></p>' .
            '<table><tr><th>商品</th><th>价格</th><th>状态/库存</th><th>操作</th></tr>' . $rows . '</table>';

        return Response::html(View::page('商品管理', $body));
    }

    public function adminProductForm(Request $request): Response
    {
        $id = (int) ($request->query['id'] ?? 0);
        $product = $id > 0 ? $this->repo->product($id) : null;
        $isNew = $product === null;
        $title = $isNew ? '新建商品' : '编辑商品';
        $gallery = is_array($product['gallery_media_ids'] ?? null) ? implode(',', $product['gallery_media_ids']) : '';
        $specs = $this->keyValueText(is_array($product['specs'] ?? null) ? $product['specs'] : []);
        $status = (string) ($product['status'] ?? 'draft');
        $body = '<h1>' . $this->e($title) . '</h1>' .
            '<form method="post" action="/admin/commerce/products/save">' . CsrfToken::field() .
            '<input type="hidden" name="id" value="' . (int) ($product['id'] ?? 0) . '">' .
            '<label>商品名称<input name="name" required value="' . $this->e((string) ($product['name'] ?? '')) . '"></label>' .
            '<label>SKU / 商品编号<input name="sku" value="' . $this->e((string) ($product['sku'] ?? '')) . '" placeholder="留空自动生成"></label>' .
            '<label>固定链接<input name="slug" value="' . $this->e((string) ($product['slug'] ?? '')) . '" placeholder="留空自动生成"></label>' .
            '<label>状态<select name="status">' . $this->options(['draft' => '草稿', 'active' => '可销售', 'archived' => '归档'], $status) . '</select></label>' .
            '<label>价格（分）<input name="price_minor" type="number" min="1" required value="' . (int) ($product['price_minor'] ?? 0) . '"></label>' .
            '<label>币种<select name="currency">' . $this->currencyOptions((string) ($product['currency'] ?? 'CNY')) . '</select></label>' .
            '<label>交易区域<input name="region" value="' . $this->e((string) ($product['region'] ?? 'CN')) . '"></label>' .
            '<label>库存数量<input name="stock_quantity" type="number" min="0" value="' . (int) ($product['stock_quantity'] ?? 0) . '"></label>' .
            '<label>摘要<textarea name="summary" rows="3">' . $this->e((string) ($product['summary'] ?? '')) . '</textarea></label>' .
            '<label>详情内容 ID<input name="description_content_id" type="number" min="0" value="' . (int) ($product['description_content_id'] ?? 0) . '"></label>' .
            '<label>主图媒体 ID<input name="primary_media_id" type="number" min="0" value="' . (int) ($product['primary_media_id'] ?? 0) . '"></label>' .
            '<label>图库媒体 ID（逗号分隔）<input name="gallery_media_ids" value="' . $this->e($gallery) . '"></label>' .
            '<label>品牌<input name="brand" value="' . $this->e((string) ($product['brand'] ?? '')) . '"></label>' .
            '<label>型号<input name="model" value="' . $this->e((string) ($product['model'] ?? '')) . '"></label>' .
            '<label>来源 URL<input name="source_url" type="url" value="' . $this->e((string) ($product['source_url'] ?? '')) . '"></label>' .
            '<label>规格事实（每行一个：名称: 值）<textarea name="specs" rows="5">' . $this->e($specs) . '</textarea></label>' .
            '<label><input type="checkbox" name="requires_shipping" value="1" ' . ((int) ($product['requires_shipping'] ?? 0) === 1 ? 'checked' : '') . '> 需要物流配送</label>' .
            '<label><input type="checkbox" name="auto_delivery_enabled" value="1" ' . ((int) ($product['auto_delivery_enabled'] ?? 0) === 1 ? 'checked' : '') . '> 数字商品可自动交付</label>' .
            '<button type="submit">保存商品</button> <a class="button admin-button-secondary" href="/admin/commerce/products">返回列表</a></form>';

        if (!$isNew) {
            $body .= $this->adminProductChildren((int) $product['id']);
        }

        return Response::html(View::page($title, $body));
    }

    public function adminSaveProduct(Request $request): Response
    {
        try {
            $id = $this->repo->saveProduct($request->body, $this->adminId($request));
            if ($this->repo->activeActions($id) === []) {
                $this->repo->saveAction(['product_id' => $id, 'action_type' => 'site_checkout', 'label' => '立即购买', 'fulfillment_mode' => !empty($request->body['requires_shipping']) ? 'shipping' : 'none']);
            }
            return Response::redirect('/admin/commerce/products/edit?id=' . $id . '&saved=1');
        } catch (Throwable $exception) {
            return $this->error('商品保存失败', $exception, '/admin/commerce/products');
        }
    }

    public function adminSetProductStatus(Request $request): Response
    {
        try {
            $id = (int) ($request->body['id'] ?? 0);
            $this->repo->setProductStatus($id, (string) ($request->body['status'] ?? 'draft'), $this->adminId($request));
            return Response::redirect('/admin/commerce/products/edit?id=' . $id . '&status_saved=1');
        } catch (Throwable $exception) {
            return $this->error('状态修改失败', $exception, '/admin/commerce/products');
        }
    }

    public function adminSaveVariant(Request $request): Response
    {
        try {
            $this->repo->saveVariant($request->body);
            return Response::redirect('/admin/commerce/products/edit?id=' . (int) ($request->body['product_id'] ?? 0) . '&variant_saved=1');
        } catch (Throwable $exception) {
            return $this->error('规格保存失败', $exception, '/admin/commerce/products');
        }
    }

    public function adminSaveAction(Request $request): Response
    {
        try {
            $this->repo->saveAction($request->body);
            return Response::redirect('/admin/commerce/products/edit?id=' . (int) ($request->body['product_id'] ?? 0) . '&action_saved=1');
        } catch (Throwable $exception) {
            return $this->error('购买动作保存失败', $exception, '/admin/commerce/products');
        }
    }

    public function adminOrders(Request $request): Response
    {
        $rows = '';
        foreach ($this->repo->orders() as $order) {
            $snapshot = is_array($order['snapshot'] ?? null) ? $order['snapshot'] : [];
            $product = is_array($snapshot['product'] ?? null) ? $snapshot['product'] : [];
            $rows .= '<tr><td><strong>' . $this->e((string) $order['order_number']) . '</strong><br><span class="muted">' . $this->e((string) ($product['name'] ?? '')) . '</span></td>' .
                '<td>' . $this->money((int) $order['amount_minor'], (string) $order['currency']) . '<br><span class="muted">x ' . (int) $order['quantity'] . '</span></td>' .
                '<td>' . $this->e((string) $order['status']) . '<br><span class="muted">' . $this->e((string) $order['fulfillment_status']) . '</span></td>' .
                '<td>' . $this->e((string) $order['created_at']) . '</td><td><a class="button" href="/admin/commerce/orders/show?id=' . (int) $order['id'] . '">详情</a></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="muted">还没有订单。</td></tr>';
        }

        return Response::html(View::page('Commerce 订单', '<h1>订单管理</h1><p><a class="button admin-button-secondary" href="/admin/commerce">返回总览</a></p><table><tr><th>订单</th><th>金额</th><th>状态</th><th>时间</th><th>操作</th></tr>' . $rows . '</table>'));
    }

    public function adminOrderShow(Request $request): Response
    {
        $order = $this->repo->order((int) ($request->query['id'] ?? 0));
        if ($order === null) {
            return Response::html(View::page('订单不存在', '<h1>订单不存在</h1><p><a class="button" href="/admin/commerce/orders">返回订单</a></p>'), 404);
        }
        $snapshot = is_array($order['snapshot'] ?? null) ? $order['snapshot'] : [];
        $body = '<h1>订单 ' . $this->e((string) $order['order_number']) . '</h1>' .
            '<p><strong>状态：</strong>' . $this->e((string) $order['status']) . ' / ' . $this->e((string) $order['fulfillment_status']) . '</p>' .
            '<p><strong>金额：</strong>' . $this->money((int) $order['amount_minor'], (string) $order['currency']) . '</p>' .
            '<p><strong>支付：</strong>' . $this->e((string) ($order['provider_id'] ?? '')) . ' #' . $this->e((string) ($order['payment_id'] ?? '')) . '</p>' .
            '<h2>订单快照</h2><pre style="white-space:pre-wrap;background:#f8fafc;border:1px solid #d8dee8;border-radius:6px;padding:12px">' . $this->e(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}') . '</pre>' .
            '<p><a class="button admin-button-secondary" href="/admin/commerce/orders">返回订单</a></p>';

        return Response::html(View::page('订单详情', $body));
    }

    public function storefront(Request $request): Response
    {
        $cards = '';
        foreach ($this->repo->publicProducts() as $product) {
            $media = (int) ($product['primary_media_id'] ?? 0);
            $image = $media > 0 ? '<img src="/media/' . $media . '" alt="' . $this->e((string) $product['name']) . '">' : '<div class="placeholder">D</div>';
            $cards .= '<a class="commerce-card" href="/commerce/product?id=' . (int) $product['id'] . '">' . $image . '<strong>' . $this->e((string) $product['name']) . '</strong><span>' . $this->money((int) $product['price_minor'], (string) $product['currency']) . '</span><p>' . $this->e((string) ($product['summary'] ?? '')) . '</p></a>';
        }
        if ($cards === '') {
            $cards = '<section class="commerce-empty">暂无可售商品。</section>';
        }

        return Response::html($this->frontPage('商品', '<h1>商品</h1><div class="commerce-grid">' . $cards . '</div>'));
    }

    public function productPage(Request $request): Response
    {
        $product = $this->repo->publicProduct((int) ($request->query['id'] ?? 0));
        if ($product === null) {
            return Response::html($this->frontPage('商品不存在', '<h1>商品不存在</h1><p><a href="/commerce">返回商品列表</a></p>'), 404);
        }
        $this->repo->recordEvent((int) $product['id'], null, 'product_view');
        $media = (int) ($product['primary_media_id'] ?? 0);
        $image = $media > 0 ? '<img class="commerce-hero-img" src="/media/' . $media . '" alt="' . $this->e((string) $product['name']) . '">' : '<div class="commerce-hero-img placeholder">Daiying</div>';
        $actions = '';
        foreach ($this->repo->activeActions((int) $product['id']) as $action) {
            $type = (string) $action['action_type'];
            if ($type === 'external_url') {
                $actions .= '<a class="commerce-button" href="' . $this->e((string) $action['external_url']) . '" target="_blank" rel="nofollow noopener">' . $this->e((string) $action['label']) . '</a>';
                continue;
            }
            if ($type === 'contact') {
                $actions .= '<p class="commerce-contact">' . $this->e((string) $action['contact_text']) . '</p>';
                continue;
            }
            $actions .= '<form method="post" action="/commerce/checkout">' . CsrfToken::field() .
                '<input type="hidden" name="product_id" value="' . (int) $product['id'] . '">' .
                '<input type="hidden" name="action_id" value="' . (int) $action['id'] . '">' .
                '<label>数量<input name="quantity" type="number" min="1" max="99" value="1"></label>' .
                '<label>支付方式<select name="provider_id">' . $this->providerOptions((string) $product['currency']) . '</select></label>' .
                '<button class="commerce-button" type="submit">' . $this->e((string) $action['label']) . '</button></form>';
        }
        if ($actions === '') {
            $actions = '<p class="commerce-muted">当前商品暂无可用购买方式。</p>';
        }
        $source = !empty($product['source_url']) ? '<p><strong>来源：</strong><a href="' . $this->e((string) $product['source_url']) . '" target="_blank" rel="noopener nofollow">查看原始来源</a></p>' : '<p><strong>来源：</strong>商家未提供来源链接。</p>';
        $body = '<article class="commerce-product"><div>' . $image . '</div><div><h1>' . $this->e((string) $product['name']) . '</h1><p class="commerce-price">' . $this->money((int) $product['price_minor'], (string) $product['currency']) . '</p><p>' . $this->e((string) ($product['summary'] ?? '')) . '</p><p><strong>库存：</strong>' . (int) $product['available_quantity'] . '</p><p><strong>来源核验：</strong>' . $this->verificationLabel((string) $product['verification_status']) . '</p>' . $actions . '</div></article>' .
            '<section class="commerce-section"><h2>透明信息</h2>' . $source . '<p><strong>品牌/型号：</strong>' . $this->e(trim((string) ($product['brand'] ?? '') . ' ' . (string) ($product['model'] ?? '')) ?: '未提供') . '</p></section>';

        return Response::html($this->frontPage((string) $product['name'], $body));
    }

    public function checkout(Request $request): Response
    {
        try {
            $productId = (int) ($request->body['product_id'] ?? 0);
            $actionId = (int) ($request->body['action_id'] ?? 0);
            $variantId = (int) ($request->body['variant_id'] ?? 0);
            $providerId = trim((string) ($request->body['provider_id'] ?? ''));
            $quantity = max(1, min((int) ($request->body['quantity'] ?? 1), 99));
            if ($providerId === '') {
                throw new \RuntimeException('请选择支付方式。');
            }
            $idempotency = 'commerce-' . bin2hex(random_bytes(16));
            $claim = $this->claim(0, $idempotency);
            $order = $this->repo->createPendingOrder($productId, $variantId > 0 ? $variantId : null, $actionId, $quantity, $providerId, $idempotency, $claim);
            $orderId = (int) $order['id'];
            $claim = $this->claim($orderId, $idempotency);
            $this->refreshClaim($orderId, $claim);
            $payment = (new PaymentService($this->pdo, new PaymentRepository($this->pdo), $this->paymentSecret()))->createProviderPayment(
                'commerce_order',
                'order:' . $orderId,
                $providerId,
                (int) $order['amount_minor'],
                (string) $order['currency'],
                $idempotency,
                'success',
                [
                    'commerce_order_id' => $orderId,
                    'success_url' => '/commerce/orders/complete?order_id=' . $orderId . '&payment_key=' . rawurlencode($idempotency) . '&claim=' . rawurlencode($claim),
                    'cancel_url' => '/commerce/product?id=' . $productId,
                ],
            );
            if (isset($payment['id'])) {
                $this->repo->attachPayment($orderId, (int) $payment['id']);
            }
            $this->repo->recordEvent($productId, $orderId, 'checkout_started', ['provider' => $providerId]);
            if ((string) ($payment['status'] ?? '') === 'paid') {
                $this->repo->markOrderPaid($orderId);
                return Response::redirect('/commerce/orders/complete?order_id=' . $orderId . '&payment_key=' . rawurlencode($idempotency) . '&claim=' . rawurlencode($claim));
            }
            $checkoutUrl = is_string($payment['_provider_checkout_url'] ?? null) ? (string) $payment['_provider_checkout_url'] : '';
            if ($checkoutUrl !== '') {
                return Response::redirect($checkoutUrl);
            }

            return Response::html($this->frontPage('等待支付', '<h1>等待支付确认</h1><p>订单已创建，请根据支付方式提示完成付款。</p><p><a href="/commerce/orders/complete?order_id=' . $orderId . '&payment_key=' . rawurlencode($idempotency) . '&claim=' . rawurlencode($claim) . '">检查订单状态</a></p>'));
        } catch (Throwable $exception) {
            if (isset($orderId) && $orderId > 0) {
                $this->repo->markOrderPaymentFailed($orderId, $exception->getMessage());
            }
            return Response::html($this->frontPage('下单失败', '<h1>下单失败</h1><p class="commerce-error">' . $this->e($exception->getMessage()) . '</p><p><a href="/commerce">返回商品列表</a></p>'), 400);
        }
    }

    public function completeOrder(Request $request): Response
    {
        $orderId = (int) ($request->query['order_id'] ?? 0);
        $paymentKey = (string) ($request->query['payment_key'] ?? '');
        $claim = (string) ($request->query['claim'] ?? '');
        $order = $this->repo->order($orderId);
        if ($order === null || $paymentKey === '' || !hash_equals((string) $order['completion_claim'], $claim) || !hash_equals($this->claim($orderId, $paymentKey), $claim)) {
            return Response::html($this->frontPage('订单无效', '<h1>订单无效</h1><p>订单校验未通过。</p>'), 403);
        }
        try {
            if (!empty($order['payment_id'])) {
                $payment = (new PaymentService($this->pdo, new PaymentRepository($this->pdo), $this->paymentSecret()))->settleHostedCheckoutPayment((int) $order['payment_id'], $paymentKey);
                $trusted = (new PaymentRepository($this->pdo))->trustedStatus('commerce_order', 'order:' . $orderId, (string) ($order['currency'] ?? ''));
                if ((string) ($trusted['status'] ?? '') === 'paid' || in_array((string) ($payment['status'] ?? ''), ['paid', 'partially_refunded'], true)) {
                    $this->repo->markOrderPaid($orderId);
                    $order = $this->repo->order($orderId) ?? $order;
                }
            }
        } catch (Throwable $exception) {
            return Response::html($this->frontPage('订单待确认', '<h1>订单待确认</h1><p>支付状态暂未完成，请稍后刷新。</p><p class="commerce-muted">' . $this->e($exception->getMessage()) . '</p>'));
        }
        $paid = in_array((string) ($order['status'] ?? ''), ['paid', 'fulfilled'], true);
        $body = '<h1>' . ($paid ? '支付成功' : '订单待支付') . '</h1><p>订单号：<strong>' . $this->e((string) $order['order_number']) . '</strong></p><p>金额：' . $this->money((int) $order['amount_minor'], (string) $order['currency']) . '</p><p>状态：' . $this->e((string) $order['status']) . '</p><p><a href="/commerce">返回商品列表</a></p>';

        return Response::html($this->frontPage('订单结果', $body));
    }

    private function adminProductChildren(int $productId): string
    {
        $variants = '';
        foreach ($this->repo->variants($productId) as $variant) {
            $variants .= '<tr><td>' . $this->e((string) $variant['title']) . '</td><td><code>' . $this->e((string) $variant['sku']) . '</code></td><td>' . (int) $variant['stock_quantity'] . '</td><td>' . $this->e((string) $variant['status']) . '</td></tr>';
        }
        if ($variants === '') {
            $variants = '<tr><td colspan="4" class="muted">暂无规格。</td></tr>';
        }
        $actions = '';
        foreach ($this->repo->actions($productId) as $action) {
            $actions .= '<tr><td>' . $this->e((string) $action['label']) . '</td><td>' . $this->e((string) $action['action_type']) . '</td><td>' . $this->e((string) $action['fulfillment_mode']) . '</td><td>' . $this->e((string) $action['status']) . '</td></tr>';
        }
        if ($actions === '') {
            $actions = '<tr><td colspan="4" class="muted">暂无购买动作。</td></tr>';
        }
        $changes = '';
        foreach ($this->repo->productChanges($productId) as $change) {
            $changes .= '<tr><td>' . $this->e((string) $change['created_at']) . '</td><td>' . $this->e((string) $change['field_name']) . '</td><td><code>' . $this->e(json_encode($change['old_value'] ?? null, JSON_UNESCAPED_UNICODE) ?: 'null') . '</code></td><td><code>' . $this->e(json_encode($change['new_value'] ?? null, JSON_UNESCAPED_UNICODE) ?: 'null') . '</code></td></tr>';
        }
        if ($changes === '') {
            $changes = '<tr><td colspan="4" class="muted">暂无关键变更。</td></tr>';
        }

        return '<hr><h2>规格</h2><table><tr><th>名称</th><th>SKU</th><th>库存</th><th>状态</th></tr>' . $variants . '</table>' .
            '<form method="post" action="/admin/commerce/variants/save">' . CsrfToken::field() . '<input type="hidden" name="product_id" value="' . $productId . '"><label>规格名称<input name="title" placeholder="如 红色 / XL / 256GB"></label><label>规格 SKU<input name="sku"></label><label>库存<input name="stock_quantity" type="number" min="0" value="0"></label><label>价格增量（分）<input name="price_delta_minor" type="number" value="0"></label><label>选项（每行 名称:值）<textarea name="options" rows="3"></textarea></label><button type="submit">添加规格</button></form>' .
            '<hr><h2>购买动作</h2><table><tr><th>按钮</th><th>类型</th><th>履约</th><th>状态</th></tr>' . $actions . '</table>' .
            '<form method="post" action="/admin/commerce/actions/save">' . CsrfToken::field() . '<input type="hidden" name="product_id" value="' . $productId . '"><label>按钮文案<input name="label" placeholder="立即购买"></label><label>动作类型<select name="action_type">' . $this->options(['site_checkout' => '本站购买', 'external_url' => '外部购买', 'contact' => '联系购买', 'digital_delivery' => '数字自动交付'], 'site_checkout') . '</select></label><label>外部链接<input name="external_url" type="url"></label><label>联系说明<input name="contact_text"></label><label>履约模式<select name="fulfillment_mode">' . $this->options(['none' => '无需履约', 'shipping' => '物流配送', 'digital_card' => '自动发卡'], 'none') . '</select></label><button type="submit">添加购买动作</button></form>' .
            '<hr><h2>关键变更历史</h2><table><tr><th>时间</th><th>字段</th><th>旧值</th><th>新值</th></tr>' . $changes . '</table>';
    }

    private function providerOptions(string $currency): string
    {
        $providers = (new PaymentService($this->pdo, new PaymentRepository($this->pdo), $this->paymentSecret()))->enabledProviders($currency);
        if ($providers === []) {
            return '<option value="">暂无可用支付方式</option>';
        }
        $html = '';
        foreach ($providers as $provider) {
            $html .= '<option value="' . $this->e($provider['id']) . '">' . $this->e($provider['label']) . '</option>';
        }
        return $html;
    }

    private function refreshClaim(int $orderId, string $claim): void
    {
        $stmt = $this->pdo->prepare('UPDATE commerce_orders SET completion_claim = :claim WHERE id = :id');
        $stmt->execute([':id' => $orderId, ':claim' => $claim]);
    }

    private function claim(int $orderId, string $key): string
    {
        return hash_hmac('sha256', $orderId . '|' . $key, $this->paymentSecret());
    }

    private function paymentSecret(): string
    {
        $secret = (string) $this->settings->get('security.encryption_key', '');
        return $secret !== '' ? $secret : hash('sha256', __DIR__);
    }

    private function adminId(Request $request): ?int
    {
        $context = $request->server['plugin_admin_context'] ?? null;
        if (is_object($context) && isset($context->adminId)) {
            return (int) $context->adminId;
        }
        return null;
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

    private function currencyOptions(string $selected): string
    {
        $html = '';
        foreach (CurrencyRegistry::enabledCodes() as $code) {
            $html .= '<option value="' . $this->e($code) . '"' . ($code === $selected ? ' selected' : '') . '>' . $this->e(CurrencyRegistry::displayName($code)) . '</option>';
        }
        return $html;
    }

    /** @param array<string,string> $items */
    private function keyValueText(array $items): string
    {
        $lines = [];
        foreach ($items as $key => $value) {
            $lines[] = $key . ': ' . $value;
        }
        return implode("\n", $lines);
    }

    private function verificationLabel(string $status): string
    {
        return match ($status) {
            'verified' => '来源信息已核验',
            'failed' => '来源暂无法核验',
            'pending' => '等待核验',
            default => '未提供来源核验',
        };
    }

    private function stat(string $label, string $value): string
    {
        return '<div class="admin-stat-card"><strong>' . $this->e($value) . '</strong><span>' . $this->e($label) . '</span></div>';
    }

    private function error(string $title, Throwable $exception, string $back): Response
    {
        return Response::html(View::page($title, '<h1>' . $this->e($title) . '</h1><p class="error">' . $this->e($exception->getMessage()) . '</p><p><a class="button" href="' . $this->e($back) . '">返回</a></p>'), 400);
    }

    private function money(int $minor, string $currency): string
    {
        try {
            return $this->e(Money::format($minor, $currency, true));
        } catch (Throwable) {
            return $this->e((string) $minor . ' ' . $currency);
        }
    }

    private function frontPage(string $title, string $body): string
    {
        return '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $this->e($title) . '</title><style>' .
            'body{margin:0;background:#f8fafc;color:#172033;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.65}a{color:#1f6feb;text-decoration:none}.wrap{width:min(1120px,100% - 32px);margin:0 auto;padding:28px 0}.top{background:#fff;border-bottom:1px solid #e4e7ec}.top .wrap{display:flex;gap:18px;align-items:center;justify-content:space-between;padding:14px 0}.brand{font-weight:800;color:#172033}.commerce-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px}.commerce-card{display:block;background:#fff;border:1px solid #d8dee8;border-radius:8px;padding:14px;color:#172033}.commerce-card img,.commerce-card .placeholder{width:100%;aspect-ratio:4/3;object-fit:cover;background:#edf2f7;border-radius:6px;display:grid;place-items:center;color:#667085;font-weight:800}.commerce-card strong{display:block;margin-top:10px}.commerce-card span,.commerce-price{font-size:24px;font-weight:800;color:#b42318}.commerce-card p,.commerce-muted{color:#667085}.commerce-product{display:grid;grid-template-columns:minmax(0,1fr) minmax(320px,420px);gap:28px;align-items:start}.commerce-hero-img{width:100%;max-height:560px;object-fit:cover;background:#edf2f7;border-radius:8px}.commerce-button,button.commerce-button{display:inline-block;background:#1f6feb;color:#fff;border:0;border-radius:6px;padding:12px 16px;font-weight:750;cursor:pointer;margin-top:12px}.commerce-section,.commerce-empty{background:#fff;border:1px solid #d8dee8;border-radius:8px;padding:18px;margin-top:18px}.commerce-error{background:#fff1f0;border:1px solid #ffccc7;color:#8c1d18;border-radius:6px;padding:12px}label{display:block;font-weight:700;margin-top:12px}input,select{width:100%;box-sizing:border-box;border:1px solid #b8c0cc;border-radius:6px;padding:10px;margin-top:6px}@media(max-width:760px){.commerce-product{grid-template-columns:1fr}.wrap{width:min(100% - 24px,1120px)}}' .
            '</style></head><body><header class="top"><div class="wrap"><a class="brand" href="/commerce">Daiying Commerce</a><a href="/">返回首页</a></div></header><main class="wrap">' . $body . '</main></body></html>';
    }

    private function e(string $value): string
    {
        return View::escape($value);
    }
}
