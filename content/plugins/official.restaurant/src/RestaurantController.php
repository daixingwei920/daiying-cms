<?php

declare(strict_types=1);

namespace Official\Restaurant;

use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Payment\PaymentService;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Support\View;
use Daiying\Commerce\CommerceRepository;

final class RestaurantController
{
    public function __construct(
        private readonly RestaurantRepository $repo,
        private readonly RestaurantTicketService $tickets,
        private readonly RestaurantPrintService $printer,
        private readonly ?RestaurantCheckoutService $checkout = null,
        private readonly ?PaymentService $payments = null,
        private readonly ?RestaurantMenuImportService $menuImport = null,
        private readonly string $addonEditorAssetUrl = '',
        private readonly bool $allowLoopbackBridge = false,
    ) {
    }

    public function dashboard(Request $request): Response
    {
        $stats = $this->repo->stats();
        $rows = '';
        foreach ($this->repo->tickets(30) as $ticket) {
            $ticketPayload = json_decode((string) ($ticket['payload_json'] ?? '{}'), true);
            $ticketPayload = is_array($ticketPayload) ? $ticketPayload : [];
            $orderId = (int) ($ticket['commerce_order_id'] ?? 0);
            $cart = $orderId > 0 ? $this->repo->cartByCommerceOrder($orderId) : null;
            $receipt = $cart === null ? null : $this->checkout?->orderReceiptForCart((string) $cart['token']);
            $paymentLabel = ['paid' => '付款已确认', 'refunded' => '已退款', 'partially_refunded' => '部分退款，人工核实', 'cancelled' => '已取消'][$receipt['payment_status'] ?? ''] ?? '付款未核实';
            $actions = '<form method="post" action="/admin/restaurant/tickets/status" style="display:inline">' . CsrfToken::field() .
                '<input type="hidden" name="ticket_id" value="' . (int) $ticket['id'] . '">' .
                '<select name="status"><option value="accepted">接单</option><option value="preparing">制作中</option><option value="ready">已出餐</option><option value="completed">完成</option><option value="cancelled">取消</option></select> ' .
                '<button type="submit">更新</button></form>';
            $rows .= '<tr><td>' . ($orderId > 0 ? '订单 #' . $orderId . '<br>' : '测试票<br>') . View::escape((string) $ticket['ticket_number']) . '</td><td>' . View::escape((string) $ticket['service_mode']) . '</td><td>' . View::escape((string) $ticket['customer_name']) . '<br>' . View::escape((string) ($ticket['customer_phone'] ?? '')) . '</td><td>' . View::escape($paymentLabel) . '</td><td>' . View::escape($this->repo->formatDueLocal((string) ($ticket['due_at'] ?? ''))) . '</td><td>' . View::escape((string) ($ticketPayload['delivery_address'] ?? '')) . '</td><td>' . View::escape((string) $ticket['status']) . '</td><td>' . View::escape((string) $ticket['created_at']) . '</td><td>' . $actions . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="9" class="muted">还没有餐厅订单。</td></tr>';
        }

        $body = '<h1>餐厅外卖</h1>' . $this->nav() . $this->notice($request) .
            '<p class="muted">复用 Commerce 的商品、支付和订单能力，餐厅插件负责菜单、厨房岗位、分单打印和出餐看板。</p>' .
            '<div class="admin-stats-grid">' .
            $this->stat('岗位', $stats['stations']) .
            $this->stat('打印机', $stats['printers']) .
            $this->stat('菜品', $stats['menu_items']) .
            $this->stat('待处理订单', $stats['open_tickets']) .
            $this->stat('待打印任务', $stats['queued_print_jobs']) .
            '</div>' .
            '<form method="post" action="/admin/restaurant/tickets/create-test" style="display:inline">' . CsrfToken::field() . '<button type="submit">生成测试分单</button></form> ' .
            '<form method="post" action="/admin/restaurant/orders/sync-paid" style="display:inline">' . CsrfToken::field() . '<button type="submit">同步已支付订单生成厨房票</button></form>' .
            '<h2>订单看板</h2><p>取餐核对订单号或票号、下单姓名/手机号，并确认付款已核实且未完成；不要只看截图。交餐后标记完成，取消厨房票不等于退款。预约时间按门店时区显示，未来预约票不能当作即时出餐。</p><table><thead><tr><th>订单号 / 票号</th><th>方式</th><th>顾客 / 手机号</th><th>付款核实</th><th>预约时间</th><th>配送地址</th><th>状态</th><th>创建时间</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        return Response::html(View::page('餐厅外卖', $body));
    }

    public function stations(Request $request): Response
    {
        $rows = '';
        foreach ($this->repo->stations() as $station) {
            $rows .= '<tr><td>' . (int) $station['id'] . '</td><td>' . View::escape((string) $station['name']) . '</td><td><code>' . View::escape((string) $station['code']) . '</code></td><td>' . View::escape((string) $station['status']) . '</td><td>' . (int) $station['sort_order'] . '</td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="5" class="muted">还没有岗位。建议先建：前台、油锅、寿司、炒锅、饮品。</td></tr>';
        $body = '<h1>厨房岗位</h1>' . $this->nav() . $this->notice($request) .
            '<form method="post" action="/admin/restaurant/stations/save">' . CsrfToken::field() .
            '<label>岗位名称<input name="name" placeholder="油锅 / 寿司 / 炒锅 / 饮品 / 前台"></label>' .
            '<label>岗位代码<input name="code" placeholder="fry / sushi / wok / drinks / front"></label>' .
            '<label>状态<select name="status"><option value="active">启用</option><option value="disabled">停用</option></select></label>' .
            '<label>排序<input name="sort_order" type="number" value="0"></label>' .
            '<button type="submit">保存岗位</button></form>' .
            '<table><thead><tr><th>ID</th><th>岗位</th><th>代码</th><th>状态</th><th>排序</th></tr></thead><tbody>' . $rows . '</tbody></table>';
        return Response::html(View::page('厨房岗位', $body));
    }

    public function saveStation(Request $request): Response
    {
        $this->repo->saveStation([
            'name' => $request->input('name', ''),
            'code' => $request->input('code', ''),
            'status' => $request->input('status', 'active'),
            'sort_order' => $request->input('sort_order', 0),
        ]);
        return Response::redirect('/admin/restaurant/stations?saved=1');
    }

    public function printers(Request $request): Response
    {
        $editing = $this->repo->printer((int) ($request->query['edit'] ?? 0)) ?? [];
        $config = $editing['config'] ?? [];
        $stationOptions = $this->stationCheckboxes($editing['station_codes'] ?? []);
        $select = static fn (string $value, string $current): string => $value === $current ? ' selected' : '';
        $rows = '';
        foreach ($this->repo->printers() as $printer) {
            $seen = (string) ($printer['bridge_last_seen_at'] ?? '');
            $online = $seen === '' ? '未连接' : (strtotime($seen . ' UTC') >= time() - 180 ? '打印桥在线（不代表已出纸）' : '打印桥离线 / 请人工接单');
            $rows .= '<tr><td><a href="/admin/restaurant/printers?edit=' . (int) $printer['id'] . '">' . (int) $printer['id'] . '</a></td><td>' . View::escape((string) $printer['name']) . '</td><td>' . View::escape((string) ($printer['config']['connection_mode'] ?? 'manual')) . ' / ' . View::escape((string) ($printer['config']['adapter'] ?? $printer['provider'])) . '</td><td>' . View::escape(implode(', ', $printer['station_codes'] ?? [])) . '</td><td>' . View::escape((string) $printer['status']) . '<br>' . View::escape($online) . '</td><td>' . View::escape((string) ($printer['last_test_status'] ?? '未测')) . ' ' . View::escape((string) ($printer['last_test_message'] ?? '')) . '<form method="post" action="/admin/restaurant/printers/test">' . CsrfToken::field() . '<input type="hidden" name="printer_id" value="' . (int) $printer['id'] . '"><label><input type="checkbox" name="confirmed" value="1" required>已获现场授权，生成明确标识的测试票</label><button type="submit">测试票入队</button></form></td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="6" class="muted">还没有设备；未绑定时订单保留在人工厨房看板。</td></tr>';
        $body = '<h1>打印机</h1>' . $this->nav() . $this->notice($request) .
            '<p>模式 A：授权的现有 Windows 打印队列；模式 B：独立、经验证的 Ethernet 设备。厂商 POS 接口须另获授权和完成适配，当前不可用。不修改 POS 数据库、不抢占端口、不开放打印机到公网。</p>' .
            '<p>一台设备可绑定多个岗位。网络地址和 Windows 队列名称只在本地打印桥配置，网站不能指挥打印桥连接任意主机。凭据仅授予绑定设备任务权限。</p>' .
            '<form method="post" action="/admin/restaurant/printers/save">' . CsrfToken::field() .
            '<input type="hidden" name="id" value="' . (int) ($editing['id'] ?? 0) . '">' .
            '<label>显示名称<input name="name" required value="' . View::escape((string) ($editing['name'] ?? '')) . '" placeholder="厨房 / 寿司台 / 饮料区"></label>' .
            '<label>处理方式<select name="provider"><option value="manual"' . $select('manual', (string) ($editing['provider'] ?? 'manual')) . '>人工看板</option><option value="agent"' . $select('agent', (string) ($editing['provider'] ?? '')) . '>本地 Print Bridge</option><option value="email">旧邮件配置（未适配）</option><option value="cloud">旧云打印配置（未适配）</option></select></label>' .
            '<label>连接模式<select name="connection_mode"><option value="existing"' . $select('existing', (string) ($config['connection_mode'] ?? 'existing')) . '>模式 A：复用现有设备</option><option value="independent"' . $select('independent', (string) ($config['connection_mode'] ?? '')) . '>模式 B：独立厨房打印机</option></select></label>' .
            '<label>可替换适配器<select name="adapter"><option value="windows_queue"' . $select('windows_queue', (string) ($config['adapter'] ?? 'windows_queue')) . '>Windows 授权打印队列（A/B）</option><option value="mac_queue"' . $select('mac_queue', (string) ($config['adapter'] ?? '')) . '>Mac 授权打印队列（A/B，测试候选）</option><option value="escpos_tcp"' . $select('escpos_tcp', (string) ($config['adapter'] ?? '')) . '>经验证 ESC/POS TCP 9100（仅 B）</option><option disabled>厂商授权 POS 接口（待适配）</option></select></label>' .
            '<fieldset><legend>绑定岗位</legend>' . $stationOptions . '</fieldset>' .
            '<label>邮箱（邮件小票）<input name="email" placeholder="kitchen@example.com"></label>' .
            '<label>本地打印桥 ID<input name="agent_id" value="' . View::escape((string) ($config['agent_id'] ?? '')) . '" placeholder="forest-kitchen-pc"></label>' .
            '<label>设备绑定凭据<input name="agent_token" type="password" autocomplete="new-password" placeholder="至少 32 字符；编辑时留空保留已有凭据"></label>' .
            '<label>云打印厂商<input name="cloud_vendor" placeholder="feie / yly / xprinter"></label>' .
            '<label>云打印设备 ID<input name="cloud_device_id"></label>' .
            '<label>状态<select name="status"><option value="enabled"' . $select('enabled', (string) ($editing['status'] ?? 'enabled')) . '>启用</option><option value="disabled"' . $select('disabled', (string) ($editing['status'] ?? '')) . '>停用并撤销访问</option></select></label>' .
            '<button type="submit">保存打印机</button></form>' .
            '<table><thead><tr><th>ID</th><th>名称</th><th>模式 / 适配器</th><th>岗位</th><th>启用 / 连接状态</th><th>测试结果 / 测试票</th></tr></thead><tbody>' . $rows . '</tbody></table>';
        return Response::html(View::page('打印机', $body));
    }

    public function savePrinter(Request $request): Response
    {
        $stationCodes = $request->input('station_codes', []);
        if (!is_array($stationCodes)) {
            $stationCodes = [$stationCodes];
        }
        try {
            $this->repo->savePrinter([
            'id' => $request->input('id', 0),
            'name' => $request->input('name', ''),
            'provider' => $request->input('provider', 'manual'),
            'status' => $request->input('status', 'enabled'),
            'station_codes' => $stationCodes,
            'email' => $request->input('email', ''),
            'agent_id' => $request->input('agent_id', ''),
            'agent_token' => $request->input('agent_token', ''),
            'cloud_vendor' => $request->input('cloud_vendor', ''),
            'cloud_device_id' => $request->input('cloud_device_id', ''),
            'connection_mode' => $request->input('connection_mode', 'existing'),
            'adapter' => $request->input('adapter', 'windows_queue'),
            'actor' => $this->printActor(),
        ]);
        } catch (\InvalidArgumentException $exception) {
            return Response::html(View::page('设备配置无效', '<h1>未保存设备</h1><p>' . View::escape($exception->getMessage()) . '</p>'), 400);
        }
        return Response::redirect('/admin/restaurant/printers?saved=1');
    }

    public function testPrinter(Request $request): Response
    {
        if ($request->input('confirmed', '') !== '1') {
            return Response::html(View::page('须确认测试', '<h1>须先确认现场授权</h1>'), 400);
        }
        try {
            $jobId = $this->tickets->createPrinterTestTicket((int) $request->input('printer_id', 0), $this->printActor());
            return Response::redirect('/admin/restaurant/print-jobs?test_job=' . $jobId);
        } catch (\InvalidArgumentException $exception) {
            return Response::html(View::page('测试票未创建', '<p>' . View::escape($exception->getMessage()) . '</p>'), 400);
        }
    }

    public function menu(Request $request): Response
    {
        $editing = $this->repo->menuItem((int) ($request->query['edit'] ?? 0)) ?? [];
        $field = static fn (string $key, string $default = ''): string => View::escape((string) ($editing[$key] ?? $default));
        $stationOptions = $this->stationOptions((string) ($editing['station_code'] ?? ''));
        $editingMetadata = json_decode((string) ($editing['modifiers_json'] ?? '{}'), true);
        $languageFields = '<fieldset><legend>三语内容（未填写时使用菜品原名，不自动伪造翻译）</legend><input type="hidden" name="language_fields_present" value="1">';
        foreach (['zh-CN' => '中文', 'en-US' => 'English', 'es-US' => 'Español'] as $locale => $label) {
            foreach (['name' => '名称', 'description' => '说明'] as $key => $fieldLabel) {
                $value = is_array($editingMetadata[$key] ?? null) ? (string) ($editingMetadata[$key][$locale] ?? '') : '';
                $languageFields .= '<label>' . $label . ' ' . $fieldLabel . '<input name="localized_' . $key . '[' . $locale . ']" value="' . View::escape($value) . '" maxlength="1000"></label>';
            }
        }
        $aliases = is_array($editingMetadata['search_aliases'] ?? null) ? implode(', ', $editingMetadata['search_aliases']) : '';
        $languageFields .= '<label>搜索别名（逗号分隔，如蛋花、egg drop）<input name="search_aliases" value="' . View::escape($aliases) . '" maxlength="3000"></label></fieldset>';
        $selectedSection = is_array($editingMetadata) ? (string) ($editingMetadata['menu_section'] ?? 'all_day') : 'all_day';
        $sectionOptions = '';
        foreach (['all_day' => '全天', 'lunch' => '午餐', 'dinner' => '晚餐', 'kids' => '儿童餐'] as $value => $label) {
            $sectionOptions .= '<option value="' . $value . '"' . ($selectedSection === $value ? ' selected' : '') . '>' . $label . '</option>';
        }
        $statusOptions = '';
        foreach (['active' => '可售', 'sold_out' => '售罄', 'disabled' => '停用'] as $value => $label) {
            $statusOptions .= '<option value="' . $value . '"' . (($editing['status'] ?? 'active') === $value ? ' selected' : '') . '>' . $label . '</option>';
        }
        $rows = '';
        foreach ($this->repo->menuItems() as $item) {
            $itemMetadata = json_decode((string) ($item['modifiers_json'] ?? '{}'), true);
            $itemSection = is_array($itemMetadata) ? (string) ($itemMetadata['menu_section'] ?? 'all_day') : 'all_day';
            $sectionLabel = ['all_day' => '全天', 'lunch' => '午餐', 'dinner' => '晚餐', 'kids' => '儿童餐'][$itemSection] ?? '全天';
            $rows .= '<tr><td><a href="/admin/restaurant/menu?edit=' . (int) $item['id'] . '">' . (int) $item['id'] . '</a></td><td>' . View::escape((string) $item['category']) . '</td><td>' . View::escape((string) $item['name']) . '</td><td>' . $sectionLabel . '</td><td>' . View::escape($this->repo->minorToMoney((int) $item['price_minor']) . ' ' . (string) $item['currency']) . '</td><td><code>' . View::escape((string) $item['station_code']) . '</code></td><td>' . View::escape((string) $item['status']) . '</td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="7" class="muted">还没有菜品。</td></tr>';
        $body = '<h1>菜单管理</h1>' . $this->nav() . $this->notice($request) .
            '<p>同一道菜在午餐、晚餐、儿童餐售价不同时，请分别新增菜品，选择对应分组并填写各自售价。每条记录按其实际售价加入餐车。</p>' .
            '<form method="post" action="/admin/restaurant/menu/save">' . CsrfToken::field() . '<input type="hidden" name="id" value="' . (int) ($editing['id'] ?? 0) . '">' .
            '<label>菜品名称<input name="name" value="' . $field('name') . '" required></label>' .
            $languageFields .
            '<label>分类<input name="category" value="' . $field('category') . '"></label>' .
            '<label>用餐分组<select name="menu_section">' . $sectionOptions . '</select></label>' .
            '<label>该分组售价<input name="price" inputmode="decimal" value="' . View::escape($this->repo->minorToMoney((int) ($editing['price_minor'] ?? 0))) . '" required></label>' .
            '<label>币种<input name="currency" value="' . $field('currency', 'CNY') . '" maxlength="3"></label>' .
            '<label>制作岗位<select name="station_code">' . $stationOptions . '</select></label>' .
            '<label>预计制作分钟<input name="prep_minutes" type="number" value="' . $field('prep_minutes', '10') . '"></label>' .
            '<label>状态<select name="status">' . $statusOptions . '</select></label>' .
            $this->addonEditor(is_array($editingMetadata) ? $editingMetadata : []) .
            '<p>“不要鸡肉、不要米饭”等由顾客在每道菜的备注中填写，不再配置成免费多选项。加料价格由后台设置，顾客不能输入价格；使用菜品币种、按每份计费。</p>' .
            '<details><summary>高级展示与规格设置（通常无需修改）</summary><label>展示数据 JSON（name/description/category 可含 zh-CN、en-US、es-US；featured、featured_order、tags、image_url、modifiers）<textarea name="metadata_json" rows="12">' . View::escape((string) ($editing['modifiers_json'] ?? '{}')) . '</textarea></label></details>' .
            '<button type="submit">保存菜品</button></form>' .
            '<table><thead><tr><th>ID</th><th>分类</th><th>菜品</th><th>用餐分组</th><th>售价</th><th>岗位</th><th>状态</th></tr></thead><tbody>' . $rows . '</tbody></table>';
        if ($this->addonEditorAssetUrl !== '') {
            $body .= '<script src="' . View::escape($this->addonEditorAssetUrl) . '" defer></script>';
        }
        return Response::html(View::page('菜单管理', $body));
    }

    private function addonEditor(array $metadata): string
    {
        $rows = '';
        foreach ($this->repo->paidAddonRows($metadata) as $index => $row) {
            $rows .= $this->addonRow((string) $index, $row);
        }
        return '<fieldset data-addon-editor><legend>加</legend><input type="hidden" name="addon_rows_present" value="1"><p>点“加”，选择米饭、鸡肉、牛肉或其他，再填每份加多少钱。常用加料自动提供中英西名称。</p><div data-addon-rows>' . $rows . '</div><button type="button" data-add-addon-row>加</button><template data-addon-row-template>' . $this->addonRow('__INDEX__', []) . '</template><noscript>启用 JavaScript 后可添加或移除加料行；已有行仍可编辑。</noscript></fieldset>';
    }

    private function addonRow(string $index, array $row): string
    {
        $prefix = 'addon_rows[' . $index . ']';
        $kind = (string) ($row['kind'] ?? '');
        $choices = '<option value="">选择要加的东西</option>';
        foreach (['rice' => '米饭', 'chicken' => '鸡肉', 'beef' => '牛肉', 'custom' => '其他'] as $value => $label) {
            $choices .= '<option value="' . $value . '"' . ($kind === $value ? ' selected' : '') . '>' . $label . '</option>';
        }
        $field = static fn (string $key): string => View::escape((string) ($row[$key] ?? ''));
        return '<div data-addon-row style="border:1px solid #ddd;padding:12px;margin-bottom:12px"><label>加什么<select name="' . $prefix . '[kind]" data-addon-kind required>' . $choices . '</select></label><label>每份加多少钱<input name="' . $prefix . '[price]" type="number" min="0" max="999999.99" step="0.01" inputmode="decimal" value="' . $field('price') . '" placeholder="例如 1.50" required></label><div data-addon-custom' . ($kind === 'custom' ? '' : ' hidden') . '><label>其他加料名称<input name="' . $prefix . '[name]" value="' . $field('name') . '" maxlength="100" data-addon-name' . ($kind === 'custom' ? ' required' : '') . '></label><details><summary>可选：英文和西语名称（未填写时保留原名称）</summary><label>English<input name="' . $prefix . '[name_en]" value="' . $field('name_en') . '" maxlength="100"></label><label>Español<input name="' . $prefix . '[name_es]" value="' . $field('name_es') . '" maxlength="100"></label></details></div><button type="button" data-remove-addon-row>移除这项</button></div>';
    }

    public function saveMenuItem(Request $request): Response
    {
        $metadata = json_decode((string) $request->input('metadata_json', '{}'), true);
        if (!is_array($metadata)) {
            return Response::html(View::page('菜单数据无效', '<h1>展示数据必须是 JSON 对象</h1>'), 400);
        }
        try {
            if ($request->input('language_fields_present', '') === '1') {
                foreach (['name', 'description'] as $key) {
                    $input = $request->input('localized_' . $key, []);
                    if (!is_array($input)) {
                        throw new \InvalidArgumentException('三语内容格式无效。');
                    }
                    $localized = is_array($metadata[$key] ?? null) ? $metadata[$key] : [];
                    foreach (['zh-CN', 'en-US', 'es-US'] as $locale) {
                        $value = mb_substr(trim((string) ($input[$locale] ?? '')), 0, 1000);
                        if ($value === '') {
                            unset($localized[$locale]);
                        } else {
                            $localized[$locale] = $value;
                        }
                    }
                    if ($localized !== []) {
                        $metadata[$key] = $localized;
                    } elseif (is_array($metadata[$key] ?? null)) {
                        unset($metadata[$key]);
                    }
                }
                $metadata['search_aliases'] = array_slice(array_values(array_filter(array_map('trim', preg_split('/[,，\r\n]+/u', (string) $request->input('search_aliases', '')) ?: []))), 0, 30);
            }
            if (array_key_exists('special_requests', $request->body)) {
                $metadata = $this->repo->withSpecialRequests($metadata, (string) $request->input('special_requests', ''));
            }
            if ($request->input('addon_rows_present', '') === '1') {
                $rows = $request->input('addon_rows', []);
                if (!is_array($rows)) {
                    throw new \InvalidArgumentException('加料行格式无效。');
                }
                $metadata = $this->repo->withPaidAddonRows($metadata, $rows);
            } elseif (array_key_exists('paid_addons', $request->body)) {
                $metadata = $this->repo->withPaidAddons($metadata, (string) $request->input('paid_addons', ''));
            }
            $this->repo->saveMenuItem([
            'id' => $request->input('id', 0),
            'name' => $request->input('name', ''),
            'category' => $request->input('category', ''),
            'menu_section' => $request->input('menu_section', 'all_day'),
            'price' => $request->input('price', '0'),
            'currency' => $request->input('currency', 'CNY'),
            'station_code' => $request->input('station_code', 'front'),
            'prep_minutes' => $request->input('prep_minutes', 10),
            'status' => $request->input('status', 'active'),
            'metadata' => $metadata,
            ]);
        } catch (\InvalidArgumentException $exception) {
            return Response::html(View::page('菜单数据无效', '<h1>菜品未保存</h1><p>' . View::escape($exception->getMessage()) . '</p><a href="/admin/restaurant/menu">返回菜单</a>'), 400);
        }
        return Response::redirect('/admin/restaurant/menu?saved=1');
    }

    public function menuImport(Request $request): Response
    {
        $capabilities = $this->menuImport?->capabilities() ?? [];
        $status = '文字 PDF：' . (!empty($capabilities['pdf_text']) ? '可用' : '需 pdftotext') . '；照片：' . (!empty($capabilities['image_ocr']) ? '可用' : '需 Tesseract') . '；扫描 PDF：' . (!empty($capabilities['scanned_pdf']) ? '可用' : '需 pdftoppm 与 Tesseract') . '；站点 AI：' . (!empty($capabilities['ai']) ? '已配置' : '未配置');
        $body = '<h1>识别菜单 → 核对草稿 → 导入</h1>' . $this->nav() . '<p>' . $status . '</p>' .
            '<p>本服务器 upload_max_filesize：' . View::escape((string) ini_get('upload_max_filesize')) . '；post_max_size：' . View::escape((string) ini_get('post_max_size')) . '；max_input_vars：' . View::escape((string) ini_get('max_input_vars')) . '。实际上传上限取服务器与插件限制中的较小值；批量审核建议 max_input_vars ≥ 2000。</p>' .
            '<p>照片识别取决于服务器语言包：英文 eng、中文 chi_sim、西语 spa。缺少相应语言包时，不保证能识别该语言。</p>' .
            '<p>保留手动新增/编辑。上传仅生成草稿，必须核对菜名、分类、午/晚/儿童价与说明，不会自动上架或覆盖已有菜品。复杂多栏识别可能不完整；最多 200 行、PDF 前 6 页。</p>' .
            '<form method="post" enctype="multipart/form-data" action="/admin/restaurant/menu/recognize">' . CsrfToken::field() .
            '<label>菜单 PDF/照片/TXT/CSV（≤10 MB）<input type="file" name="menu_file" accept=".pdf,.jpg,.jpeg,.png,.webp,.txt,.csv"></label>' .
            '<label>或粘贴菜单文字（≤60 KB）<textarea name="menu_text" rows="8" placeholder="[Soups]&#10;Miso Soup 2.95&#10;Tofu, seaweed"></textarea></label>' .
            '<label>币种<input name="currency" value="USD" pattern="[A-Z]{3}" maxlength="3" required></label>' .
            '<label><input type="checkbox" name="use_ai" value="1">允许将识别文字发送给已配置的站点 AI 整理（可能收费；不发送原图）。未勾选时只在本服务器处理。</label>' .
            '<button type="submit">识别为待审核草稿</button></form>';
        $draft = $_SESSION['restaurant_menu_draft'] ?? null;
        if (is_array($draft) && (int) ($draft['created_at'] ?? 0) >= time() - 3600) {
            $body .= $this->menuDraftForm($draft);
        }
        return Response::html(View::page('菜单识别与导入', $body));
    }

    public function recognizeMenu(Request $request): Response
    {
        try {
            if ($this->menuImport === null) {
                throw new \RuntimeException('菜单识别服务不可用。');
            }
            $text = trim((string) $request->input('menu_text', ''));
            $file = $_FILES['menu_file'] ?? null;
            if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if ($text !== '') {
                    throw new \InvalidArgumentException('上传文件与粘贴文字请选择一种，避免误导入。');
                }
                if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
                    throw new \InvalidArgumentException('上传失败，请检查服务器上传大小限制。');
                }
                $text = $this->menuImport->extractFile((string) $file['tmp_name']);
            }
            $draft = $this->menuImport->recognize($text, strtoupper(trim((string) $request->input('currency', 'USD'))), $request->input('use_ai', '') === '1');
            $this->menuSession();
            $_SESSION['restaurant_menu_draft'] = $draft;
            return Response::redirect('/admin/restaurant/menu/import#review');
        } catch (\Throwable $exception) {
            $message = !$exception instanceof \Cms\Core\Ai\AiException && ($exception instanceof \InvalidArgumentException || $exception instanceof \RuntimeException) ? $exception->getMessage() : '识别失败；未导入任何菜品。请检查站点 AI 或识别组件。';
            return Response::html(View::page('菜单识别失败', '<h1>未导入任何菜品</h1><p>' . View::escape($message) . '</p><a href="/admin/restaurant/menu/import">返回识别页</a>'), 400);
        }
    }

    public function importMenu(Request $request): Response
    {
        $this->menuSession();
        $draft = $_SESSION['restaurant_menu_draft'] ?? null;
        if (!is_array($draft) || !hash_equals((string) ($draft['id'] ?? ''), (string) $request->input('draft_id', '')) || (int) ($draft['created_at'] ?? 0) < time() - 3600) {
            return Response::html(View::page('草稿失效', '<h1>草稿已过期或已导入，请重新识别。</h1>'), 400);
        }
        $submitted = $request->input('rows', []);
        if (!is_array($submitted) || count($submitted) !== count($draft['rows']) || $request->input('review_complete', '') !== $draft['id'] || $request->input('confirmed', '') !== '1') {
            return Response::html(View::page('需要核对', '<h1>请先核对并勾选确认；提交若被服务器截断，请分批识别或提高 max_input_vars。</h1>'), 400);
        }
        $rows = [];
        foreach ($submitted as $index => $row) {
            if (!is_array($row) || !isset($draft['rows'][$index])) {
                return Response::html(View::page('草稿无效', '<h1>草稿行无效。</h1>'), 400);
            }
            $edited = [];
            foreach (['name', 'category', 'menu_section', 'price', 'currency', 'description'] as $field) {
                $edited[$field] = is_string($row[$field] ?? null) ? $row[$field] : '';
            }
            $edited['selected'] = ($row['selected'] ?? '') === '1';
            $draft['rows'][$index] = $edited;
            if (($row['selected'] ?? '') === '1') {
                $rows[] = $edited;
            }
        }
        $_SESSION['restaurant_menu_draft'] = $draft;
        try {
            $result = $this->repo->importReviewedMenu($rows, (string) $draft['source_hash'], $request->input('activate', '') === '1');
            unset($_SESSION['restaurant_menu_draft']);
            return Response::html(View::page('菜单已导入', '<h1>菜单已导入</h1><p>新增 ' . $result['created'] . ' 道；跳过同名/同分类/同分组已有菜品 ' . $result['skipped'] . ' 道，未覆盖人工录入。可售状态由刚才的明确选择决定。</p><a href="/admin/restaurant/menu">查看/编辑菜单</a>'));
        } catch (\InvalidArgumentException $exception) {
            return Response::html(View::page('核对未完成', '<h1>未导入任何菜品</h1><p>' . View::escape($exception->getMessage()) . '</p>' . $this->menuDraftForm($draft)), 400);
        }
    }

    private function menuDraftForm(array $draft): string
    {
        $body = '<section id="review"><h2>待审核草稿（1 小时内有效）</h2><p>逐行修改；删除误识别行请取消勾选。原文不保存在菜单库。</p>';
        foreach ($draft['warnings'] as $warning) {
            $body .= '<p>' . View::escape((string) $warning) . '</p>';
        }
        $body .= '<details><summary>展开识别原文对照（复杂多栏请结合原 PDF/照片核对）</summary><pre style="white-space:pre-wrap">' . View::escape((string) ($draft['source_text'] ?? '')) . '</pre></details>';
        $body .= '<form method="post" action="/admin/restaurant/menu/import">' . CsrfToken::field() . '<input type="hidden" name="draft_id" value="' . View::escape((string) $draft['id']) . '">';
        foreach ($draft['rows'] as $index => $row) {
            $prefix = 'rows[' . (int) $index . ']';
            $body .= '<fieldset><legend>第 ' . ((int) $index + 1) . ' 行 · 待核对</legend><label><input type="checkbox" name="' . $prefix . '[selected]" value="1"' . (($row['selected'] ?? true) ? ' checked' : '') . '>导入此行</label>';
            foreach (['name' => '菜名', 'category' => '分类（寿司/炒菜/头台/汤等）', 'price' => '此份量售价（空白表示未识别）', 'currency' => '币种'] as $field => $label) {
                $body .= '<label>' . $label . '<input name="' . $prefix . '[' . $field . ']" value="' . View::escape((string) $row[$field]) . '" maxlength="191"></label>';
            }
            $body .= '<label>分组<select name="' . $prefix . '[menu_section]">';
            foreach (['all_day' => '全天', 'lunch' => '午餐', 'dinner' => '晚餐', 'kids' => '儿童餐'] as $section => $label) {
                $body .= '<option value="' . $section . '"' . ($row['menu_section'] === $section ? ' selected' : '') . '>' . $label . '</option>';
            }
            $body .= '</select></label><label>说明/配料<textarea name="' . $prefix . '[description]" maxlength="2000">' . View::escape((string) $row['description']) . '</textarea></label></fieldset>';
        }
        return $body . '<input type="hidden" name="review_complete" value="' . View::escape((string) $draft['id']) . '"><label><input type="checkbox" name="confirmed" value="1" required>我已核对所选菜名、分类、价格与份量（午餐不设置限时）。</label><label><input type="checkbox" name="activate" value="1">导入后设为可售（未勾选时停用，可稍后手动开启）</label><button type="submit">确认导入所选行</button></form></section>';
    }

    private function menuSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
            throw new \RuntimeException('无法保存私有审核草稿，请检查服务器会话存储。');
        }
    }

    public function categories(Request $request): Response
    {
        $forms = '';
        foreach ($this->repo->menuCategories() as $category) {
            $key = View::escape($category['key']);
            $labels = $category['labels'];
            $forms .= '<form method="post" action="/admin/restaurant/categories/save" class="admin-card">' . CsrfToken::field() .
                '<h2>' . $key . ' · ' . (int) $category['count'] . ' 道菜</h2>' .
                '<input type="hidden" name="source_category" value="' . $key . '">' .
                '<label>分类标识<input name="category" value="' . $key . '" maxlength="191" required></label>' .
                '<label>中文名称<input name="label_zh" value="' . View::escape($labels['zh-CN']) . '" maxlength="191" required></label>' .
                '<label>English<input name="label_en" value="' . View::escape($labels['en-US']) . '" maxlength="191" required></label>' .
                '<label>Español<input name="label_es" value="' . View::escape($labels['es-US']) . '" maxlength="191" required></label>' .
                '<button type="submit">保存此分类</button></form>';
        }
        $forms = $forms !== '' ? $forms : '<p>还没有分类。先在菜单中新增菜品并填写分类。</p>';
        $body = '<h1>分类管理</h1>' . $this->nav() . $this->notice($request) .
            '<p>修改分类标识和三语名称后，该分类下所有菜品会一起更新；菜品归类可在菜单管理中单独调整。</p>' . $forms;
        return Response::html(View::page('分类管理', $body));
    }

    public function saveCategory(Request $request): Response
    {
        try {
            $updated = $this->repo->saveMenuCategory(
                (string) $request->input('source_category', ''),
                (string) $request->input('category', ''),
                [
                    'zh-CN' => (string) $request->input('label_zh', ''),
                    'en-US' => (string) $request->input('label_en', ''),
                    'es-US' => (string) $request->input('label_es', ''),
                ],
            );
        } catch (\InvalidArgumentException $exception) {
            return Response::html(View::page('分类保存失败', '<h1>分类保存失败</h1><p class="error">' . View::escape($exception->getMessage()) . '</p><p><a href="/admin/restaurant/categories">返回分类管理</a></p>'), 400);
        }
        return Response::redirect('/admin/restaurant/categories?saved=1&updated=' . $updated);
    }

    public function storefrontSettings(): Response
    {
        $row = $this->repo->storefrontSettings();
        $field = static fn (string $key): string => View::escape((string) ($row[$key] ?? ''));
        $tax = $this->repo->taxSettings();
        $languageOptions = '';
        $defaultLocale = $this->repo->defaultLocale();
        foreach (['en-US' => 'English（英文）', 'zh-CN' => '中文', 'es-US' => 'Español（西班牙语）'] as $locale => $label) {
            $languageOptions .= '<option value="' . $locale . '"' . ($locale === $defaultLocale ? ' selected' : '') . '>' . $label . '</option>';
        }
        $body = '<h1>店面与营业设置</h1>' . $this->nav() . '<form method="post" action="/admin/restaurant/storefront/save">' . CsrfToken::field() .
            '<label>餐馆名称<input name="name" value="' . $field('name') . '" required></label>' .
            '<input type="hidden" name="default_locale_present" value="1"><label>首选语言<select name="default_locale">' . $languageOptions . '</select></label><p>未自行选择语言的顾客使用首选语言，默认英文；顾客已保存的中英西语言选择优先。</p>' .
            '<label>电话<input name="phone" value="' . $field('phone') . '"></label>' .
            '<label>地址<input name="address" value="' . $field('address') . '"></label>' .
            '<label>时区<input name="timezone" value="' . View::escape((string) ($row['timezone'] ?? 'America/New_York')) . '"></label>' .
            '<fieldset><legend>税费</legend><input type="hidden" name="tax_fields_present" value="1">' .
            '<label>税率（%）<input name="tax_rate" inputmode="decimal" value="' . View::escape($tax['rate_percent']) . '" placeholder="由门店核定；留空表示未配置"></label>' .
            '<label>配送费是否计税<select name="delivery_taxable"><option value="">待确认</option><option value="no"' . ($tax['configured'] && !$tax['delivery_taxable'] ? ' selected' : '') . '>不计税</option><option value="yes"' . ($tax['configured'] && $tax['delivery_taxable'] ? ' selected' : '') . '>计税</option></select></label>' .
            '<p>税率由门店按经营地核定，系统不自动判断税务规则。当前使用未含税售价；税基包含菜品及收费加料，配送费按所选规则处理。未配置税率不可新建支付订单；合法零税率也须明确填写 0。现有已提交订单不追溯改价。</p></fieldset>' .
            '<p>在下方 JSON 中按实际商户资料设置 merchant_region（两位国家代码）和 transaction_region（cn_domestic、cross_border 或 international）。未配置时禁止结账；税费须另按实际经营地核定。</p>' .
            '<p>预约：设置 preorder_available=true、hours、preorder_lead_minutes、preorder_max_days。送餐：设置 delivery_available=true、delivery_fee_minor（最小货币单位）及 delivery_postal_codes 数组；未明确设置运费和配送邮编时不开放送餐。请仅配置实际可服务的区域。</p>' .
            '<p>营业时间格式：{"hours":{"mon":[{"open":"11:00","close":"21:00"}]}}。配置后按门店时区计算状态；paused/closed 可手动覆盖。</p>' .
            '<label>店面设置 JSON（多语言名称/地址/简介、门头、图库、营业状态和自取配送可用性）<textarea name="settings_json" rows="20">' . View::escape((string) ($row['settings_json'] ?? '{"status":"closed","pickup_available":false,"delivery_available":false}')) . '</textarea></label>' .
            '<button type="submit">保存店面</button></form>';
        return Response::html(View::page('店面设置', $body));
    }

    public function saveStorefrontSettings(Request $request): Response
    {
        $settings = json_decode((string) $request->input('settings_json', '{}'), true);
        if (!is_array($settings)) {
            return Response::html(View::page('店面数据无效', '<h1>店面设置必须是 JSON 对象</h1>'), 400);
        }
        try {
            if ((string) $request->input('default_locale_present', '') === '1') {
                $settings['default_locale'] = (string) $request->input('default_locale', 'en-US');
            }
            if ((string) $request->input('tax_fields_present', '') === '1') {
                $settings = $this->repo->withTaxSettings($settings, (string) $request->input('tax_rate', ''), (string) $request->input('delivery_taxable', ''));
            }
            $this->repo->saveStorefrontSettings([
                'name' => $request->input('name', ''),
                'phone' => $request->input('phone', ''),
                'address' => $request->input('address', ''),
                'timezone' => $request->input('timezone', ''),
                'settings' => $settings,
            ]);
        } catch (\InvalidArgumentException $exception) {
            return Response::html(View::page('店面设置无效', '<h1>店面未保存</h1><p>' . View::escape($exception->getMessage()) . '</p>'), 400);
        }
        return Response::redirect('/admin/restaurant/storefront?saved=1');
    }

    public function printJobs(): Response
    {
        $rows = '';
        foreach ($this->repo->printJobs(100) as $job) {
            $orderLabel = $job['commerce_order_id'] === null ? 'TEST / 人工票，非支付订单' : '订单 ' . (int) $job['commerce_order_id'];
            $rows .= '<tr><td>' . (int) $job['id'] . '<br><small>' . View::escape((string) $job['uuid']) . '</small><br>' . View::escape($orderLabel) . '</td><td>' . View::escape((string) $job['station_code']) . '</td><td>' . View::escape((string) ($job['printer_name'] ?? '未绑定')) . '<br><small>' . View::escape((string) $job['provider']) . '</small></td><td>' . View::escape((string) $job['status']) . '<br><strong>' . View::escape((string) ($job['last_error'] ?? '')) . '</strong></td><td>' . (int) $job['attempts'] . '</td></tr>';
            $rows .= '<tr><td colspan="5"><details><summary>厨房票及人工处理 / Ticket &amp; manual actions #' . (int) $job['id'] . '</summary><pre>' . View::escape((string) $job['payload_text']) . '</pre><div class="restaurant-print-actions"><form method="post" action="/admin/restaurant/print-jobs/retry">' . CsrfToken::field() . '<input type="hidden" name="job_id" value="' . (int) $job['id'] . '"><input type="hidden" name="request_key" value="' . bin2hex(random_bytes(16)) . '"><label>核实原因<input name="reason" required minlength="3" maxlength="500"></label><label><input type="checkbox" name="confirmed" value="1" required>已现场核实；了解重印可能产生重复纸票</label><button type="submit">新建人工重印任务</button></form><form method="post" action="/admin/restaurant/print-jobs/confirm">' . CsrfToken::field() . '<input type="hidden" name="job_id" value="' . (int) $job['id'] . '"><label>现场确认说明<input name="reason" required minlength="3" maxlength="500"></label><label><input type="checkbox" name="confirmed" value="1" required>已实际出纸或已人工处理</label><button type="submit">现场确认完成</button></form></div></details></td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="5" class="muted">还没有打印任务。</td></tr>';
        $body = '<h1>打印任务</h1>' . $this->nav() .
            '<p role="alert"><strong>待核实、失败、未绑定任务须人工接单。</strong> 数据已发送/已进入 Windows 队列不等于实际出纸。结果不确定不自动重打；取消与退款不能撤回已印出的纸票，请现场通知厨房。</p>' .
            '<style>.restaurant-print-jobs{width:100%;table-layout:fixed}.restaurant-print-jobs td{vertical-align:top;overflow-wrap:anywhere}.restaurant-print-jobs summary{cursor:pointer;font-weight:600}.restaurant-print-jobs pre{white-space:pre-wrap;overflow-wrap:anywhere;max-width:100%}.restaurant-print-actions{display:flex;gap:16px;flex-wrap:wrap}.restaurant-print-jobs .restaurant-print-actions form{display:grid;gap:8px;white-space:normal;flex:1 1 280px;min-width:0}.restaurant-print-jobs .restaurant-print-actions label{display:block;white-space:normal}.restaurant-print-jobs .restaurant-print-actions input:not([type=checkbox]){display:block;max-width:100%;width:100%}@media(max-width:640px){.restaurant-print-jobs{font-size:12px}.restaurant-print-jobs td{padding:6px}}</style>' .
            '<table class="restaurant-print-jobs"><thead><tr><th>ID / 订单</th><th>岗位</th><th>打印机 / 类型</th><th>状态 / 告警</th><th>次数</th></tr></thead><tbody>' . $rows . '</tbody></table>';
        $body .= '<h2>打印操作审计</h2><table><thead><tr><th>时间</th><th>任务</th><th>操作</th><th>人员 / 打印桥</th><th>说明</th></tr></thead><tbody>';
        foreach ($this->repo->printAudits() as $audit) {
            $body .= '<tr><td>' . View::escape((string) $audit['created_at']) . '</td><td>' . (int) ($audit['job_id'] ?? 0) . '</td><td>' . View::escape((string) $audit['action']) . '</td><td>' . View::escape((string) $audit['actor']) . '</td><td>' . View::escape((string) $audit['message']) . '</td></tr>';
        }
        return Response::html(View::page('打印任务', $body . '</tbody></table>'));
    }

    public function retryPrintJob(Request $request): Response
    {
        try {
            if ($request->input('confirmed', '') !== '1') {
                throw new \InvalidArgumentException('请先现场核实并明确确认重印。');
            }
            $this->printer->retry((int) $request->input('job_id', 0), (string) $request->input('reason', ''), $this->printActor(), (string) $request->input('request_key', ''));
        } catch (\InvalidArgumentException $exception) {
            return Response::html(View::page('未重印', '<p>' . View::escape($exception->getMessage()) . '</p>'), 400);
        }
        return Response::redirect('/admin/restaurant/print-jobs?retried=1');
    }

    public function confirmPrintJob(Request $request): Response
    {
        try {
            if ($request->input('confirmed', '') !== '1') {
                throw new \InvalidArgumentException('请先现场核实出纸或人工处理。');
            }
            $this->repo->confirmManualPrint((int) $request->input('job_id', 0), (string) $request->input('reason', ''), $this->printActor());
        } catch (\InvalidArgumentException $exception) {
            return Response::html(View::page('未确认打印', '<p>' . View::escape($exception->getMessage()) . '</p>'), 400);
        }
        return Response::redirect('/admin/restaurant/print-jobs?confirmed=1');
    }

    public function createTestTicket(): Response
    {
        $this->tickets->createDemoTicket();
        return Response::redirect('/admin/restaurant?created=1');
    }

    public function updateTicketStatus(Request $request): Response
    {
        $ticketId = (int) $request->input('ticket_id', 0);
        $status = (string) $request->input('status', 'new');
        $ticket = $this->repo->ticket($ticketId);
        if (($ticket['status'] ?? '') === 'completed' && $status !== 'completed' && (int) ($ticket['commerce_order_id'] ?? 0) > 0) {
            return Response::html(View::page('订单已完成', '<h1>订单已完成</h1><p>已交餐订单不能重新接单，请人工核实。</p>'), 409);
        }
        if ($status === 'completed' && (int) ($ticket['commerce_order_id'] ?? 0) > 0) {
            $cart = $this->repo->cartByCommerceOrder((int) $ticket['commerce_order_id']);
            $receipt = $cart === null ? null : $this->checkout?->orderReceiptForCart((string) $cart['token']);
            if (($receipt['payment_status'] ?? '') !== 'paid' || ($ticket['status'] ?? '') === 'cancelled') {
                return Response::html(View::page('不能确认交餐', '<h1>不能确认交餐</h1><p>请先核实真实付款及订单状态；已取消或退款订单须人工处理。</p>'), 409);
            }
        }
        $this->repo->updateTicketStatus($ticketId, $status);
        return Response::redirect('/admin/restaurant?status=1');
    }

    public function syncPaidOrders(Request $request): Response
    {
        if ($this->checkout === null) {
            return Response::redirect('/admin/restaurant?sync=unavailable');
        }
        $result = $this->checkout->syncPaidOrders();
        return Response::redirect('/admin/restaurant?sync=' . rawurlencode((string) json_encode($result, JSON_UNESCAPED_SLASHES)));
    }

    public function publicMenu(): Response
    {
        $cards = '';
        foreach ($this->repo->activeMenuItems() as $item) {
            $cards .= '<article class="restaurant-card"><div><strong>' . View::escape((string) $item['name']) . '</strong><p>' . View::escape((string) $item['category']) . '</p></div><span>' . View::escape($this->repo->minorToMoney((int) $item['price_minor']) . ' ' . (string) $item['currency']) . '</span>' .
                '<form method="post" action="/restaurant/api/cart/add">' . CsrfToken::field() . '<input type="hidden" name="menu_item_id" value="' . (int) $item['id'] . '"><input type="hidden" name="quantity" value="1"><button type="submit">加入餐车</button></form></article>';
        }
        $cards = $cards !== '' ? $cards : '<p class="muted">菜单还没有上架菜品。</p>';
        $body = '<style>.restaurant-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px}.restaurant-card{border:1px solid #d8dee9;border-radius:8px;padding:16px;display:grid;grid-template-columns:1fr auto;gap:12px;background:#fff}.restaurant-card form{grid-column:1/-1}.restaurant-card p{margin:6px 0 0;color:#667085}.restaurant-checkout{margin-top:24px;border:1px solid #d8dee9;border-radius:8px;padding:16px;background:#fff}</style>' .
            '<h1>餐厅菜单</h1><div class="restaurant-grid">' . $cards . '</div>' .
            '<section class="restaurant-checkout"><h2>提交餐厅订单</h2><form method="post" action="/restaurant/checkout">' . CsrfToken::field() .
            '<label>餐车 Token<input name="token" placeholder="加入菜品后返回的 cart_token"></label>' .
            '<label>支付 Provider ID<input name="provider_id" placeholder="official.payment.stripe"></label>' .
            '<label>方式<select name="service_mode"><option value="pickup">自取</option><option value="delivery">送餐</option></select></label>' .
            '<label>预约时间（门店本地时间，可留空表示尽快）<input name="fulfillment_at" type="datetime-local"></label>' .
            '<label>姓名<input name="customer_name"></label><label>电话<input name="customer_phone"></label><label>邮箱<input name="customer_email"></label>' .
            '<label>配送地址（送餐必填）<input name="delivery_address"></label><label>配送邮编（送餐必填）<input name="delivery_postal_code"></label><button type="submit">去支付</button></form></section>';
        return Response::html(View::page('餐厅菜单', $body));
    }

    public function apiCart(Request $request): Response
    {
        $token = $this->bearerToken($request);
        if ($token === '') {
            return Response::json(['ok' => false, 'error' => 'cart_token_required'], 401)->withHeaders(['Cache-Control' => 'no-store']);
        }
        try {
            $mode = isset($request->query['service_mode']) ? (string) $request->query['service_mode'] : null;
            return Response::json($this->publicCartSummary($this->repo->cartSummary($token, $mode)))->withHeaders(['Cache-Control' => 'no-store']);
        } catch (\InvalidArgumentException $exception) {
            return Response::json(['ok' => false, 'error' => $exception->getMessage()], 400)->withHeaders(['Cache-Control' => 'no-store']);
        }
    }

    public function apiCartAdd(Request $request): Response
    {
        $token = trim((string) $request->input('token', ''));
        if ($token === '') {
            $token = 'cart_' . bin2hex(random_bytes(16));
        }
        $modifiers = $request->input('modifiers', []);
        if (is_string($modifiers)) {
            $decoded = json_decode($modifiers, true);
            $modifiers = is_array($decoded) ? $decoded : [];
        }
        try {
            $summary = $this->repo->addCartItem($token, (int) $request->input('menu_item_id', 0), (int) $request->input('quantity', 1), is_array($modifiers) ? $modifiers : [], (string) $request->input('notes', ''));
            return Response::json($this->publicCartSummary($summary) + ['cart_token' => $token])->withHeaders(['Cache-Control' => 'no-store']);
        } catch (\Throwable $exception) {
            return Response::json(['ok' => false, 'error' => $exception->getMessage(), 'cart_token' => $token], 400);
        }
    }

    public function apiCartRemove(Request $request): Response
    {
        try {
            return Response::json($this->publicCartSummary($this->repo->removeCartItem((string) $request->input('token', ''), (int) $request->input('item_id', 0))))->withHeaders(['Cache-Control' => 'no-store']);
        } catch (\Throwable $exception) {
            return Response::json(['ok' => false, 'error' => $exception->getMessage()], 400);
        }
    }

    public function apiCartUpdate(Request $request): Response
    {
        $modifiers = $request->input('modifiers', []);
        if (is_string($modifiers)) {
            $modifiers = json_decode($modifiers, true);
        }
        try {
            if (!is_array($modifiers)) {
                throw new \InvalidArgumentException('加料选项格式无效。');
            }
            return Response::json($this->publicCartSummary($this->repo->updateCartItem((string) $request->input('token', ''), (int) $request->input('item_id', 0), (int) $request->input('quantity', 0), $modifiers, (string) $request->input('notes', ''))))->withHeaders(['Cache-Control' => 'no-store']);
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            return Response::json(['ok' => false, 'error' => $exception->getMessage()], 400)->withHeaders(['Cache-Control' => 'no-store']);
        }
    }

    public function checkout(Request $request): Response
    {
        if ($this->checkout === null) {
            return Response::html(View::page('餐厅支付不可用', '<h1>餐厅支付不可用</h1><p>Commerce Checkout Handoff 尚未启用。</p>'), 503);
        }
        try {
            $prepared = $this->checkout->beginHostedPayment(
                trim((string) $request->input('token', '')),
                trim((string) $request->input('provider_id', '')),
                [
                    'service_mode' => (string) $request->input('service_mode', 'pickup'),
                    'customer_name' => (string) $request->input('customer_name', ''),
                    'customer_phone' => (string) $request->input('customer_phone', ''),
                    'customer_email' => (string) $request->input('customer_email', ''),
                    'delivery_address' => (string) $request->input('delivery_address', ''),
                    'delivery_postal_code' => (string) $request->input('delivery_postal_code', ''),
                    'fulfillment_at' => (string) $request->input('fulfillment_at', ''),
                    'expected_total_minor' => $request->input('expected_total_minor', null),
                ],
            );
            $payment = is_array($prepared['payment'] ?? null) ? $prepared['payment'] : [];
            $checkoutUrl = is_string($payment['_provider_checkout_url'] ?? null) ? (string) $payment['_provider_checkout_url'] : '';
            if ($checkoutUrl !== '') {
                return Response::redirect($checkoutUrl);
            }
            return Response::html(View::page('等待支付', '<h1>等待支付确认</h1><p>餐厅订单已创建，请按支付方式提示完成付款。</p><p><a href="' . View::escape((string) ($prepared['success_url'] ?? '/restaurant')) . '">检查支付结果</a></p>'));
        } catch (\Throwable $exception) {
            return Response::html(View::page('餐厅下单失败', '<h1>餐厅下单失败</h1><p>' . View::escape($exception->getMessage()) . '</p><p><a href="/restaurant">返回菜单</a></p>'), 400);
        }
    }

    public function completeCheckout(Request $request): Response
    {
        if ($this->checkout === null) {
            return Response::html(View::page('餐厅订单不可用', '<h1>餐厅订单不可用</h1>'), 503);
        }
        try {
            $result = $this->checkout->completeHostedPayment(
                (int) ($request->query['order_id'] ?? 0),
                (string) ($request->query['claim'] ?? ''),
            );
            $orderId = (int) ($result['order']['id'] ?? 0);
            $ticket = $this->repo->ticket((int) ($result['ticket_id'] ?? 0));
            return Response::html(View::page('餐厅订单成功', '<h1>支付成功</h1><p>订单号：<strong>#' . $orderId . '</strong></p><p>厨房票：' . View::escape((string) ($ticket['ticket_number'] ?? '')) . '</p><p>取餐时出示订单号或本页面；店员以后台真实付款及未领取状态为准，不只凭截图交餐。</p><p><a href="/">返回网站查看订单凭证</a></p><p><a href="/restaurant">返回菜单</a></p>'))
                ->withHeaders(['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
        } catch (\Throwable $exception) {
            return Response::html(View::page('餐厅订单待确认', '<h1>订单待确认</h1><p>' . View::escape($exception->getMessage()) . '</p><p><a href="/restaurant">返回菜单</a></p>'), 400)
                ->withHeaders(['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
        }
    }

    public function apiStorefront(Request $request): Response
    {
        $locale = (string) ($request->query['locale'] ?? '');
        $payload = $this->repo->storefrontPayload($locale);
        $payload['payment_providers'] = $this->payments?->enabledProviders((string) ($request->query['currency'] ?? '')) ?? [];
        $payload['ordering'] = ['online_ready' => $this->checkout !== null && method_exists(CommerceRepository::class, 'updatePendingOrderClaim') && $this->repo->taxSettings()['configured'] && $payload['payment_providers'] !== []];
        return Response::json($payload);
    }

    public function apiMenu(Request $request): Response
    {
        $locale = (string) ($request->query['locale'] ?? '');
        $query = (string) ($request->query['q'] ?? '');
        return Response::json($this->repo->menuPayload($locale, $query));
    }

    public function agentJobs(Request $request): Response
    {
        $agentId = trim((string) ($request->query['agent_id'] ?? ''));
        $token = $this->bearerToken($request);
        if ($agentId === '' || $token === '') {
            return Response::json(['ok' => false, 'error' => 'agent_credentials_required'], 401);
        }
        return Response::json(['ok' => false, 'error' => 'legacy_agent_claim_disabled_use_secure_bridge'], 410)->withHeaders(['Cache-Control' => 'no-store']);
    }

    public function agentReport(Request $request): Response
    {
        if ($this->bearerToken($request) !== '') {
            return Response::json(['ok' => false, 'error' => 'legacy_agent_report_disabled_use_secure_bridge'], 410);
        }
        $jobId = (int) $request->input('job_id', 0);
        $status = (string) $request->input('status', 'failed');
        $error = (string) $request->input('error', '');
        if (!$this->repo->reportAgentJob(
            trim((string) $request->input('agent_id', '')),
            $this->bearerToken($request),
            $jobId,
            trim((string) $request->input('claim_token', '')),
            $status,
            $error,
        )) {
            return Response::json(['ok' => false, 'error' => 'invalid_agent_job_claim'], 403);
        }
        return Response::json(['ok' => true]);
    }

    public function bridgeBindings(Request $request): Response
    {
        $auth = $this->bridgeAuth($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$agentId, $token, $printers] = $auth;
        $this->repo->bridgeHeartbeat($agentId, $token);
        $devices = array_map(static fn (array $printer): array => ['printer_id' => (int) $printer['id'], 'name' => $printer['name'], 'station_codes' => $printer['station_codes'], 'connection_mode' => $printer['config']['connection_mode'] ?? 'existing', 'adapter' => $printer['config']['adapter'] ?? 'windows_queue'], $printers);
        return Response::json(['ok' => true, 'protocol' => '1', 'devices' => $devices])->withHeaders(['Cache-Control' => 'no-store']);
    }

    public function bridgeClaim(Request $request): Response
    {
        $auth = $this->bridgeAuth($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$agentId, $token] = $auth;
        if ($this->checkout === null) {
            return Response::json(['ok' => false, 'error' => 'checkout_reconciliation_unavailable'], 503);
        }
        $sync = $this->checkout->syncPaidOrders(50, max(0, (int) $request->input('sync_cursor', 0)));
        $this->repo->bridgeHeartbeat($agentId, $token);
        $jobs = $this->repo->queuedAgentJobs($agentId, $token, 1);
        $devices = array_column($auth[2], null, 'id');
        $payloads = array_map(static function (array $job) use ($devices): array {
            $device = $devices[$job['printer_id']];
            return ['id' => (int) $job['id'], 'uuid' => $job['uuid'], 'ticket_id' => (int) $job['ticket_id'], 'commerce_order_id' => $job['commerce_order_id'] === null ? null : (int) $job['commerce_order_id'], 'printer_id' => (int) $job['printer_id'], 'station_code' => $job['station_code'], 'job_kind' => $job['job_kind'], 'attempts' => (int) $job['attempts'], 'payload_text' => $job['payload_text'], 'payload_sha256' => hash('sha256', $job['payload_text']), 'claim_token' => $job['claim_token'], 'claim_expires_at' => $job['claim_expires_at'], 'adapter' => $device['config']['adapter'] ?? 'windows_queue', 'connection_mode' => $device['config']['connection_mode'] ?? 'existing'];
        }, $jobs);
        return Response::json(['ok' => true, 'jobs' => $payloads, 'reconciliation' => $sync])->withHeaders(['Cache-Control' => 'no-store']);
    }

    public function bridgeStart(Request $request): Response
    {
        $auth = $this->bridgeAuth($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$agentId, $token, $printers] = $auth;
        $jobId = (int) $request->input('job_id', 0);
        $claim = (string) $request->input('claim_token', '');
        $job = $this->repo->printJob($jobId);
        if ($job === null || !in_array((int) $job['printer_id'], array_map('intval', array_column($printers, 'id')), true) || $claim === '' || !hash_equals((string) ($job['claim_token_hash'] ?? ''), hash('sha256', $claim)) || $job['status'] !== 'printing' || (string) $job['claim_expires_at'] < gmdate('Y-m-d H:i:s')) {
            return Response::json(['ok' => false, 'error' => 'invalid_agent_job_claim'], 403);
        }
        if ($job['job_kind'] !== 'test') {
            if ($this->checkout === null || (int) ($job['commerce_order_id'] ?? 0) <= 0) {
                return Response::json(['ok' => false, 'error' => 'payment_confirmation_required'], 409);
            }
            try {
                $verified = $this->checkout->reconcileOrder((int) $job['commerce_order_id']);
                if (($verified['print_allowed'] ?? false) !== true) {
                    return Response::json(['ok' => false, 'error' => 'payment_cancelled_unconfirmed_or_refunded'], 409);
                }
            } catch (\Throwable) {
                return Response::json(['ok' => false, 'error' => 'payment_verification_failed'], 409);
            }
        }
        if (!$this->repo->beginAgentDispatch($agentId, $token, $jobId, $claim)) {
            return Response::json(['ok' => false, 'error' => 'dispatch_not_allowed'], 409);
        }
        return Response::json(['ok' => true, 'dispatch_allowed' => true])->withHeaders(['Cache-Control' => 'no-store']);
    }

    public function bridgeReport(Request $request): Response
    {
        $auth = $this->bridgeAuth($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        if (!in_array((string) $request->input('status', ''), ['submitted', 'failed', 'needs_review'], true)) {
            return Response::json(['ok' => false, 'error' => 'physical_confirmation_requires_owner'], 400);
        }
        if (!$this->repo->reportAgentJob($auth[0], $auth[1], (int) $request->input('job_id', 0), (string) $request->input('claim_token', ''), (string) $request->input('status', ''), (string) $request->input('error', ''))) {
            return Response::json(['ok' => false, 'error' => 'invalid_agent_job_claim'], 403);
        }
        return Response::json(['ok' => true])->withHeaders(['Cache-Control' => 'no-store']);
    }

    private function bridgeAuth(Request $request): array|Response
    {
        $peer = (string) ($request->server['REMOTE_ADDR'] ?? '');
        $host = strtolower((string) ($request->server['HTTP_HOST'] ?? ''));
        $loopback = $this->allowLoopbackBridge && in_array($peer, ['127.0.0.1', '::1'], true) && preg_match('/^(127\.0\.0\.1|localhost|\[::1\])(?::\d+)?$/', $host) === 1;
        $secure = in_array(strtolower((string) ($request->server['HTTPS'] ?? '')), ['on', '1'], true);
        if (!$secure && !$loopback) {
            return Response::json(['ok' => false, 'error' => 'bridge_https_required'], 403);
        }
        $agentId = trim((string) $request->input('agent_id', $request->query['agent_id'] ?? ''));
        $token = trim((string) ($request->server['HTTP_X_DAIYING_BRIDGE_TOKEN'] ?? $this->bearerToken($request)));
        if (preg_match('/^[A-Za-z0-9_-]{3,64}$/', $agentId) !== 1 || preg_match('/^[A-Za-z0-9._~-]{32,128}$/', $token) !== 1) {
            return Response::json(['ok' => false, 'error' => 'agent_credentials_required'], 401);
        }
        $printers = $this->repo->bridgePrinters($agentId, $token);
        if ($printers === []) {
            return Response::json(['ok' => false, 'error' => 'invalid_device_binding'], 403);
        }
        return [$agentId, $token, $printers];
    }

    private function printActor(): string
    {
        return 'admin:' . (int) ($_SESSION['admin_user']['id'] ?? 0);
    }

    private function bearerToken(Request $request): string
    {
        $authorization = trim((string) ($request->server['HTTP_AUTHORIZATION'] ?? ''));
        return preg_match('/^Bearer ([A-Za-z0-9._~-]+)$/', $authorization, $matches) === 1 ? $matches[1] : '';
    }

    private function publicCartSummary(array $summary): array
    {
        $cart = is_array($summary['cart'] ?? null) ? $summary['cart'] : [];
        $summary['order_receipt'] = $this->checkout?->orderReceiptForCart((string) ($cart['token'] ?? ''));
        $summary['cart'] = [
            'status' => (string) ($cart['status'] ?? 'open'),
            'service_mode' => (string) ($cart['service_mode'] ?? 'pickup'),
        ];
        return $summary;
    }

    private function nav(): string
    {
        return '<p><a href="/admin/restaurant">订单看板</a> · <a href="/admin/restaurant/storefront">店面设置</a> · <a href="/admin/restaurant/menu">菜单</a> · <a href="/admin/restaurant/menu/import">识别菜单</a> · <a href="/admin/restaurant/categories">分类</a> · <a href="/admin/restaurant/stations">厨房岗位</a> · <a href="/admin/restaurant/printers">打印机</a> · <a href="/admin/restaurant/print-jobs">打印任务</a> · <a href="/restaurant">前台菜单</a></p>';
    }

    private function notice(Request $request): string
    {
        if (($request->query['saved'] ?? '') === '1') {
            return '<p class="success">已保存。</p>';
        }
        if (($request->query['created'] ?? '') === '1') {
            return '<p class="success">测试分单已生成。</p>';
        }
        if (isset($request->query['sync'])) {
            return '<p class="notice">已同步 Commerce 已支付餐厅订单：' . View::escape((string) $request->query['sync']) . '</p>';
        }
        return '';
    }

    private function stat(string $label, int $value): string
    {
        return '<section><strong>' . $value . '</strong><span>' . View::escape($label) . '</span></section>';
    }

    private function stationOptions(string $selected): string
    {
        $options = '';
        foreach ($this->repo->activeStations() as $station) {
            $code = (string) $station['code'];
            $options .= '<option value="' . View::escape($code) . '" ' . ($selected === $code ? 'selected' : '') . '>' . View::escape((string) $station['name']) . ' (' . View::escape($code) . ')</option>';
        }
        return $options !== '' ? $options : '<option value="front">前台 (front)</option><option value="fry">油锅 (fry)</option><option value="sushi">寿司 (sushi)</option><option value="wok">炒锅 (wok)</option>';
    }

    /** @param list<string> $selected */
    private function stationCheckboxes(array $selected): string
    {
        $html = '';
        foreach ($this->repo->activeStations() as $station) {
            $code = (string) $station['code'];
            $html .= '<label style="display:inline-block;margin-right:16px"><input type="checkbox" name="station_codes[]" value="' . View::escape($code) . '" ' . (in_array($code, $selected, true) ? 'checked' : '') . '> ' . View::escape((string) $station['name']) . '</label>';
        }
        return $html !== '' ? $html : '<p class="muted">请先创建厨房岗位；不选择岗位的打印机会接收所有岗位。</p>';
    }
}
