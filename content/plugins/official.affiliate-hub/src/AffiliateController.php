<?php

declare(strict_types=1);

namespace Daiying\AffiliateHub;

use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Security\CsrfToken;

final class AffiliateController
{
    public function __construct(
        private readonly AffiliateRepository $repo,
        private readonly FeedImportService $feed,
        private readonly ?AffiliateConnectionRepository $connections = null,
        private readonly ?CjAffiliateAdapter $cj = null,
    )
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

        return Response::html($this->shell('联盟商城', '<p class="muted">统一管理 Affiliate Product、Offer、点击跳转和平台 Adapter。联盟商品只导流到广告主，不走本站支付、订单或物流。</p><div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:18px 0">' . $cards . '</div><p><a class="button" href="/admin/affiliate-hub/products">商品列表</a> <a class="button" href="/admin/affiliate-hub/products/new">新增手工联盟商品</a> <a class="button" href="/admin/affiliate-hub/import">一键导入</a> <a class="button" href="/admin/affiliate-hub/cj">CJ Affiliate</a></p>'));
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

    public function importForm(Request $request): Response
    {
        return Response::html($this->shell('一键导入', $this->importFormHtml()));
    }

    public function previewImport(Request $request): Response
    {
        $csv = $this->postedCsv($request);
        $parsed = $this->feed->parseCsv($csv, 50);
        if ($parsed['headers'] === []) {
            return Response::html($this->shell('一键导入', '<div class="alert alert-error">' . $this->e(implode(' ', $parsed['errors'])) . '</div>' . $this->importFormHtml($request->body)), 422);
        }
        $mapping = $this->feed->suggestMapping($parsed['headers']);
        $mapped = $this->feed->mapRows($parsed['rows'], $mapping, 5);

        return Response::html($this->shell('Feed 字段映射', $this->mappingFormHtml($csv, $parsed['headers'], $mapping, $mapped, $request->body, $parsed['errors'])));
    }

    public function runImport(Request $request): Response
    {
        $csv = $this->postedCsv($request);
        $parsed = $this->feed->parseCsv($csv, 1000);
        $mapping = [];
        foreach ($this->feedTargets() as $target => $_label) {
            $mapping[$target] = trim((string) $request->input('map_' . $target, ''));
        }
        $items = $this->feed->mapRows($parsed['rows'], $mapping, (int) $request->input('limit', 200));
        $result = $this->repo->importFeedProducts($items, [
            'source_name' => $request->input('source_name', 'feed'),
            'currency' => $request->input('default_currency', ''),
            'country' => $request->input('default_country', ''),
            'language' => $request->input('default_language', ''),
            'status' => $request->input('status', 'draft'),
            'indexable' => (string) $request->input('indexable', '') === '1',
        ]);
        $errors = '';
        foreach ($result['errors'] as $error) {
            $errors .= '<li>' . $this->e($error) . '</li>';
        }
        $html = '<div class="alert alert-success">导入完成：处理 ' . (int) $result['processed'] . '，新增 ' . (int) $result['created'] . '，更新 ' . (int) $result['updated'] . '，失败 ' . (int) $result['failed'] . '。</div>';
        if ($errors !== '') {
            $html .= '<h2>错误行</h2><ul>' . $errors . '</ul>';
        }
        $html .= '<p><a class="button" href="/admin/affiliate-hub/products">查看商品</a> <a class="button admin-button-secondary" href="/admin/affiliate-hub/import">继续导入</a></p>';

        return Response::html($this->shell('导入结果', $html));
    }

    public function cjSettings(Request $request): Response
    {
        return Response::html($this->shell('CJ Affiliate', $this->cjSettingsHtml()));
    }

    public function saveCjSettings(Request $request): Response
    {
        try {
            $this->requireConnections()->saveCjConnection($request->body);
        } catch (\Throwable $exception) {
            return Response::html($this->shell('CJ Affiliate', '<div class="alert alert-error">保存失败：' . $this->e($exception->getMessage()) . '</div>' . $this->cjSettingsHtml($request->body)), 422);
        }

        return Response::redirect('/admin/affiliate-hub/cj?saved=1');
    }

    public function testCjSettings(Request $request): Response
    {
        $connections = $this->requireConnections();
        try {
            $config = $connections->cjRuntimeConfig();
            $result = $this->requireCj()->validateCredentials($config);
            $connections->updateCjTest($result['ok'] ? 'ok' : 'failed', $result['message']);
            $class = $result['ok'] ? 'alert-success' : 'alert-error';
            return Response::html($this->shell('CJ Affiliate', '<div class="alert ' . $class . '">' . $this->e($result['message']) . '</div>' . $this->cjSettingsHtml()));
        } catch (\Throwable $exception) {
            $connections->updateCjTest('failed', $exception->getMessage());
            return Response::html($this->shell('CJ Affiliate', '<div class="alert alert-error">测试失败：' . $this->e($exception->getMessage()) . '</div>' . $this->cjSettingsHtml()), 422);
        }
    }

    public function cjSearch(Request $request): Response
    {
        try {
            $config = $this->requireConnections()->cjRuntimeConfig();
            $result = $this->requireCj()->searchProducts([
                'config' => $config,
                'keywords' => $request->input('keywords', ''),
                'advertiser_ids' => $request->input('advertiser_ids', ''),
                'limit' => $request->input('limit', 25),
                'offset' => $request->input('offset', 0),
            ]);
        } catch (CjAffiliateRateLimitException $exception) {
            $this->requireConnections()->markCjRateLimited($exception->getMessage());
            return Response::html($this->shell('CJ 商品搜索', '<div class="alert alert-error">CJ 限流：请稍后再试。</div>' . $this->cjSearchForm($request->query)), 429);
        } catch (\Throwable $exception) {
            return Response::html($this->shell('CJ 商品搜索', '<div class="alert alert-error">CJ 搜索失败：' . $this->e($exception->getMessage()) . '</div>' . $this->cjSearchForm($request->query)), 422);
        }

        return Response::html($this->shell('CJ 商品搜索', $this->cjSearchForm($request->query) . $this->cjResultsHtml($result['items'], $request->query, $result['next_cursor'])));
    }

    public function importCj(Request $request): Response
    {
        $payload = (string) $request->input('items_json', '');
        $items = json_decode($payload, true);
        if (!is_array($items)) {
            return Response::html($this->shell('CJ 商品导入', '<div class="alert alert-error">CJ 导入数据无效。</div>' . $this->cjSearchForm()), 422);
        }
        $config = $this->requireConnections()->cjRuntimeConfig();
        $result = $this->repo->importProviderProducts('affiliate.cj', array_values(array_filter($items, 'is_array')), [
            'source_name' => 'cj:' . ((string) ($config['company_id'] ?? '')),
            'connection_id' => (int) ($config['connection_id'] ?? 0),
            'status' => $request->input('status', 'draft'),
            'indexable' => (string) $request->input('indexable', '') === '1',
        ]);
        $errors = '';
        foreach ($result['errors'] as $error) {
            $errors .= '<li>' . $this->e($error) . '</li>';
        }
        $html = '<div class="alert alert-success">CJ 导入完成：处理 ' . (int) $result['processed'] . '，新增 ' . (int) $result['created'] . '，更新 ' . (int) $result['updated'] . '，失败 ' . (int) $result['failed'] . '。</div>';
        if ($errors !== '') {
            $html .= '<h2>错误</h2><ul>' . $errors . '</ul>';
        }
        $html .= '<p><a class="button" href="/admin/affiliate-hub/products">查看商品</a> <a class="button admin-button-secondary" href="/admin/affiliate-hub/cj/search">继续搜索 CJ</a></p>';

        return Response::html($this->shell('CJ 商品导入', $html));
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

    /** @param array<string,mixed> $old */
    private function importFormHtml(array $old = []): string
    {
        return '<form method="post" action="/admin/affiliate-hub/import/preview" enctype="multipart/form-data">' . CsrfToken::field() .
            '<label>Feed 名称<input name="source_name" value="' . $this->e((string) ($old['source_name'] ?? 'manual-csv')) . '"></label>' .
            '<label>上传 CSV<input type="file" name="feed_file" accept=".csv,text/csv"></label>' .
            '<label>或粘贴 CSV<textarea name="feed_text" rows="12" placeholder="id,name,price,currency,destination_url,affiliate_url">' . $this->e((string) ($old['feed_text'] ?? '')) . '</textarea></label>' .
            '<p class="muted">V1 先支持 CSV。导入前会进入字段映射和预览，不会在一个请求里导入几万条。</p>' .
            '<button type="submit">读取并预览</button></form>';
    }

    /** @param array<string,mixed> $old */
    private function cjSettingsHtml(array $old = []): string
    {
        $connection = $this->connections?->cjConnection();
        $config = is_array($connection['public_config'] ?? null) ? $connection['public_config'] : [];
        $value = function (string $key) use ($old, $config): string {
            return $this->e((string) ($old[$key] ?? $config[$key] ?? ''));
        };
        $enabled = (string) ($old['status'] ?? ($connection['status'] ?? 'disabled')) === 'enabled';
        $tokenHint = !empty($connection['token_configured']) ? '已配置（' . $this->e((string) ($connection['token_masked'] ?? '')) . '）' : '未配置';
        $test = '';
        if (is_array($connection) && (string) ($connection['last_test_status'] ?? '') !== '') {
            $test = '<p class="muted">上次测试：' . $this->e((string) $connection['last_test_status']) . ' · ' . $this->e((string) ($connection['last_test_message'] ?? '')) . ' · ' . $this->e((string) ($connection['last_tested_at'] ?? '')) . '</p>';
        }

        return '<p class="muted">CJ V1 使用官方 Product Feed/Search GraphQL 读取商品，并使用 CJ 返回的 linkCode(pid) 作为 Affiliate Tracking Link。Personal Access Token 只保存在服务端密钥仓库。</p>' .
            $test .
            '<form method="post" action="/admin/affiliate-hub/cj/save">' . CsrfToken::field() .
            '<label><input type="checkbox" name="status" value="enabled"' . ($enabled ? ' checked' : '') . '> 启用 CJ 连接</label>' .
            '<label>CJ Company ID<input name="company_id" required value="' . $value('company_id') . '"></label>' .
            '<label>Website ID / PID<input name="website_id" required value="' . $value('website_id') . '"></label>' .
            '<label>Personal Access Token<input name="personal_access_token" type="password" autocomplete="new-password" placeholder="' . $tokenHint . '"></label>' .
            '<p class="muted">留空表示保留已保存 Token。Token 不会回显，也不会写入日志或导入结果。</p>' .
            '<label>限定广告主 Company ID，可选<input name="advertiser_ids" placeholder="111,222" value="' . $value('advertiser_ids') . '"></label>' .
            '<label>超时秒数<input name="timeout" type="number" min="3" max="60" value="' . ($value('timeout') ?: '20') . '"></label>' .
            '<button type="submit">保存 CJ 配置</button> <button type="submit" formaction="/admin/affiliate-hub/cj/test">测试连接</button> <a class="button admin-button-secondary" href="/admin/affiliate-hub/cj/search">搜索商品</a></form>';
    }

    /** @param array<string,mixed> $old */
    private function cjSearchForm(array $old = []): string
    {
        return '<form method="get" action="/admin/affiliate-hub/cj/search">' .
            '<label>关键词<input name="keywords" value="' . $this->e((string) ($old['keywords'] ?? '')) . '" placeholder="shoes, camera, laptop"></label>' .
            '<label>广告主 Company ID，可选<input name="advertiser_ids" value="' . $this->e((string) ($old['advertiser_ids'] ?? '')) . '" placeholder="111,222"></label>' .
            '<label>数量<input name="limit" type="number" min="1" max="100" value="' . $this->e((string) ($old['limit'] ?? '25')) . '"></label>' .
            '<button type="submit">搜索 CJ 商品</button> <a class="button admin-button-secondary" href="/admin/affiliate-hub/cj">CJ 配置</a></form>';
    }

    /** @param list<array<string,mixed>> $items @param array<string,mixed> $query */
    private function cjResultsHtml(array $items, array $query = [], ?string $nextCursor = null): string
    {
        if ($items === []) {
            return '<p class="muted">没有 CJ 商品结果。</p>';
        }
        $rows = '';
        foreach ($items as $item) {
            $rows .= '<tr><td>' . $this->e((string) ($item['name'] ?? '')) . '</td><td>' . $this->e((string) ($item['advertiser_name'] ?? '')) . '</td><td>' . $this->e($this->money($item['price_current'] ?? null, (string) ($item['currency'] ?? ''))) . '</td><td>' . $this->e((string) ($item['availability'] ?? '')) . '</td><td>' . $this->e((string) ($item['affiliate_url'] ?? '')) . '</td></tr>';
        }
        $next = '';
        if ($nextCursor !== null) {
            $params = $query;
            $params['offset'] = $nextCursor;
            $next = ' <a class="button admin-button-secondary" href="/admin/affiliate-hub/cj/search?' . $this->e(http_build_query($params)) . '">下一页</a>';
        }

        return '<form method="post" action="/admin/affiliate-hub/cj/import">' . CsrfToken::field() .
            '<textarea name="items_json" hidden>' . $this->e(json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]') . '</textarea>' .
            '<label>导入状态<select name="status">' . $this->options(['draft' => '草稿', 'active' => '发布', 'inactive' => '停用'], 'draft') . '</select></label>' .
            '<label><input type="checkbox" name="indexable" value="1"> 允许收录</label>' .
            '<table><thead><tr><th>商品</th><th>广告主</th><th>价格</th><th>状态</th><th>Tracking URL</th></tr></thead><tbody>' . $rows . '</tbody></table>' .
            '<p><button type="submit">导入当前结果</button>' . $next . '</p></form>';
    }

    /** @param list<string> $headers @param array<string,string> $mapping @param list<array<string,mixed>> $preview @param array<string,mixed> $old @param list<string> $errors */
    private function mappingFormHtml(string $csv, array $headers, array $mapping, array $preview, array $old, array $errors): string
    {
        $fields = '';
        foreach ($this->feedTargets() as $target => $label) {
            $fields .= '<label>' . $this->e($label) . '<select name="map_' . $this->e($target) . '"><option value="">不映射</option>' . $this->headerOptions($headers, $mapping[$target] ?? '') . '</select></label>';
        }
        $rows = '';
        foreach ($preview as $item) {
            $rows .= '<tr><td>' . $this->e((string) ($item['name'] ?? '')) . '</td><td>' . $this->e((string) ($item['advertiser_name'] ?? '')) . '</td><td>' . $this->e($this->money($item['price_current'] ?? null, (string) ($item['currency'] ?? ''))) . '</td><td>' . $this->e((string) ($item['destination_url'] ?? '')) . '</td><td>' . $this->e((string) ($item['affiliate_url'] ?? '')) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5">没有可预览的行。</td></tr>';
        }
        $warnings = '';
        foreach ($errors as $error) {
            $warnings .= '<li>' . $this->e($error) . '</li>';
        }

        return ($warnings !== '' ? '<div class="alert alert-warning"><ul>' . $warnings . '</ul></div>' : '') .
            '<form method="post" action="/admin/affiliate-hub/import/run">' . CsrfToken::field() .
            '<textarea name="feed_text" hidden>' . $this->e($csv) . '</textarea>' .
            '<label>Feed 名称<input name="source_name" value="' . $this->e((string) ($old['source_name'] ?? 'manual-csv')) . '"></label>' .
            '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px">' . $fields . '</div>' .
            '<label>默认币种<input name="default_currency" maxlength="3" placeholder="USD/CNY"></label>' .
            '<label>默认国家<input name="default_country" maxlength="8" placeholder="US/CN"></label>' .
            '<label>默认语言<input name="default_language" maxlength="16" placeholder="en-US/zh-CN"></label>' .
            '<label>导入数量上限<input name="limit" type="number" min="1" max="1000" value="200"></label>' .
            '<label>状态<select name="status">' . $this->options(['draft' => '草稿', 'active' => '发布', 'inactive' => '停用'], 'draft') . '</select></label>' .
            '<label><input type="checkbox" name="indexable" value="1"> 允许收录</label>' .
            '<h2>预览</h2><table><thead><tr><th>商品</th><th>商家</th><th>价格</th><th>目标 URL</th><th>Affiliate URL</th></tr></thead><tbody>' . $rows . '</tbody></table>' .
            '<p class="muted">导入会按 Feed 名称和外部商品 ID 幂等更新。没有外部 ID 时会用目标 URL + Affiliate URL 生成来源身份。</p>' .
            '<button type="submit">确认导入</button> <a class="button admin-button-secondary" href="/admin/affiliate-hub/import">返回</a></form>';
    }

    /** @return array<string,string> */
    private function feedTargets(): array
    {
        return [
            'external_product_id' => '外部商品 ID',
            'name' => '商品名称',
            'description' => '商品描述',
            'brand' => '品牌',
            'advertiser_name' => '商家/广告主',
            'category' => '原始分类',
            'image_url' => '图片 URL',
            'price_current' => '当前价格',
            'currency' => '币种',
            'availability' => '库存/状态',
            'destination_url' => '目标商品 URL',
            'affiliate_url' => 'Affiliate URL',
            'country' => '国家/市场',
            'language' => '语言',
        ];
    }

    /** @param list<string> $headers */
    private function headerOptions(array $headers, string $selected): string
    {
        $html = '';
        foreach ($headers as $header) {
            $html .= '<option value="' . $this->e($header) . '"' . ($header === $selected ? ' selected' : '') . '>' . $this->e($header) . '</option>';
        }

        return $html;
    }

    private function postedCsv(Request $request): string
    {
        $text = (string) $request->input('feed_text', '');
        $file = $_FILES['feed_file']['tmp_name'] ?? '';
        if ($text === '' && is_string($file) && $file !== '' && is_uploaded_file($file)) {
            $text = (string) file_get_contents($file);
        }

        return $text;
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
        return '<main class="admin-main"><h1>' . $this->e($title) . '</h1><nav style="margin:0 0 18px"><a href="/admin/affiliate-hub">总览</a> · <a href="/admin/affiliate-hub/products">商品</a> · <a href="/admin/affiliate-hub/products/new">手工商品</a> · <a href="/admin/affiliate-hub/import">CSV 导入</a> · <a href="/admin/affiliate-hub/cj">CJ Affiliate</a></nav>' . $body . '</main>';
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

    private function requireConnections(): AffiliateConnectionRepository
    {
        if (!$this->connections instanceof AffiliateConnectionRepository) {
            throw new \RuntimeException('Affiliate connection manager is unavailable.');
        }

        return $this->connections;
    }

    private function requireCj(): CjAffiliateAdapter
    {
        if (!$this->cj instanceof CjAffiliateAdapter) {
            throw new \RuntimeException('CJ adapter is unavailable.');
        }

        return $this->cj;
    }
}
