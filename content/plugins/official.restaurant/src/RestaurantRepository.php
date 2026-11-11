<?php

declare(strict_types=1);

namespace Official\Restaurant;

use PDO;

final class RestaurantRepository
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function stations(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM restaurant_kitchen_stations ORDER BY sort_order ASC, id ASC');
        return $stmt === false ? [] : $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function activeStations(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM restaurant_kitchen_stations WHERE status = 'active' ORDER BY sort_order ASC, id ASC");
        return $stmt === false ? [] : $stmt->fetchAll();
    }

    public function saveStation(array $data): int
    {
        $now = $this->now();
        $id = (int) ($data['id'] ?? 0);
        $code = $this->stationCode((string) ($data['code'] ?? $data['name'] ?? ''));
        if ($code === '') {
            throw new \InvalidArgumentException('岗位代码不能为空。');
        }
        $status = in_array((string) ($data['status'] ?? 'active'), ['active', 'disabled'], true) ? (string) ($data['status'] ?? 'active') : 'active';
        $payload = [
            ':name' => trim((string) ($data['name'] ?? $code)),
            ':code' => $code,
            ':status' => $status,
            ':sort_order' => (int) ($data['sort_order'] ?? 0),
            ':updated_at' => $now,
        ];
        if ($id > 0) {
            $payload[':id'] = $id;
            $this->pdo->prepare('UPDATE restaurant_kitchen_stations SET name = :name, code = :code, status = :status, sort_order = :sort_order, updated_at = :updated_at WHERE id = :id')
                ->execute($payload);
            return $id;
        }

        $payload[':created_at'] = $now;
        $this->pdo->prepare('INSERT INTO restaurant_kitchen_stations (name, code, status, sort_order, created_at, updated_at) VALUES (:name, :code, :status, :sort_order, :created_at, :updated_at)')
            ->execute($payload);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function printers(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM restaurant_print_devices ORDER BY id ASC');
        $rows = $stmt === false ? [] : $stmt->fetchAll();
        return array_map(fn (array $row): array => $this->decodePrinter($row), $rows);
    }

    /** @return list<array<string,mixed>> */
    public function printersForStation(string $stationCode): array
    {
        return array_values(array_filter($this->printers(), static function (array $printer) use ($stationCode): bool {
            if (($printer['status'] ?? '') !== 'enabled') {
                return false;
            }
            $codes = $printer['station_codes'] ?? [];
            return $codes === [] || in_array($stationCode, $codes, true);
        }));
    }

    public function savePrinter(array $data): int
    {
        $now = $this->now();
        $id = (int) ($data['id'] ?? 0);
        $provider = (string) ($data['provider'] ?? 'manual');
        $existing = $id > 0 ? $this->printer($id) : null;
        if ($id > 0 && $existing === null) {
            throw new \InvalidArgumentException('打印设备不存在。');
        }
        if (!in_array($provider, ['manual', 'email', 'agent', 'cloud'], true)) {
            throw new \InvalidArgumentException('打印机类型无效。');
        }
        $stationCodes = $this->normaliseCodes((array) ($data['station_codes'] ?? []));
        $config = [
            'email' => trim((string) ($data['email'] ?? '')),
            'agent_id' => trim((string) ($data['agent_id'] ?? '')),
            'agent_token_hash' => trim((string) ($data['agent_token'] ?? '')) !== '' ? password_hash((string) $data['agent_token'], PASSWORD_DEFAULT) : (string) ($existing['config']['agent_token_hash'] ?? ''),
            'cloud_vendor' => trim((string) ($data['cloud_vendor'] ?? '')),
            'cloud_device_id' => trim((string) ($data['cloud_device_id'] ?? '')),
        ];
        if ($provider === 'agent' && (isset($data['adapter']) || isset($existing['config']['adapter']))) {
            $mode = (string) ($data['connection_mode'] ?? $existing['config']['connection_mode'] ?? 'existing');
            $adapter = (string) ($data['adapter'] ?? $existing['config']['adapter'] ?? 'windows_queue');
            if (!in_array($mode, ['existing', 'independent'], true) || !in_array($adapter, ['windows_queue', 'mac_queue', 'escpos_tcp'], true) || ($mode === 'existing' && $adapter === 'escpos_tcp')) {
                throw new \InvalidArgumentException('复用现有设备须走已授权 Windows/Mac 队列；网络直连仅用于独立、经验证设备。');
            }
            if (preg_match('/^[A-Za-z0-9_-]{3,64}$/', $config['agent_id']) !== 1 || $config['agent_token_hash'] === '' || (trim((string) ($data['agent_token'] ?? '')) !== '' && strlen((string) $data['agent_token']) < 32)) {
                throw new \InvalidArgumentException('打印桥 ID 须为 3–64 位字母/数字/横线；绑定凭据至少 32 字符。');
            }
            $config += ['connection_mode' => $mode, 'adapter' => $adapter, 'bridge_protocol' => '1'];
        }
        $status = in_array((string) ($data['status'] ?? 'enabled'), ['enabled', 'disabled'], true) ? (string) ($data['status'] ?? 'enabled') : 'enabled';
        $payload = [
            ':name' => trim((string) ($data['name'] ?? '打印机')),
            ':provider' => $provider,
            ':status' => $status,
            ':station_codes_json' => $this->json($stationCodes),
            ':config_json' => $this->json($config),
            ':updated_at' => $now,
        ];
        if ($id > 0) {
            $payload[':id'] = $id;
            $this->pdo->prepare('UPDATE restaurant_print_devices SET name = :name, provider = :provider, status = :status, station_codes_json = :station_codes_json, config_json = :config_json, updated_at = :updated_at WHERE id = :id')
                ->execute($payload);
            $this->printAudit(null, 'device.updated', (string) ($data['actor'] ?? 'system'), 'printer:' . $id);
            return $id;
        }

        $payload[':created_at'] = $now;
        $this->pdo->prepare('INSERT INTO restaurant_print_devices (name, provider, status, station_codes_json, config_json, created_at, updated_at) VALUES (:name, :provider, :status, :station_codes_json, :config_json, :created_at, :updated_at)')
            ->execute($payload);
        $id = (int) $this->pdo->lastInsertId();
        $this->printAudit(null, 'device.bound', (string) ($data['actor'] ?? 'system'), 'printer:' . $id);
        return $id;
    }

    public function printer(int $id): ?array
    {
        foreach ($this->printers() as $printer) {
            if ((int) $printer['id'] === $id) {
                return $printer;
            }
        }
        return null;
    }

    /** @return list<array<string,mixed>> */
    public function menuItems(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM restaurant_menu_items ORDER BY category ASC, id DESC');
        return $stmt === false ? [] : $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function activeMenuItems(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM restaurant_menu_items WHERE status IN ('active', 'sold_out') ORDER BY category ASC, id DESC");
        return $stmt === false ? [] : $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function menuItem(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM restaurant_menu_items WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return list<array{key:string,count:int,labels:array<string,string>}> */
    public function menuCategories(): array
    {
        $categories = [];
        foreach ($this->menuItems() as $item) {
            $key = (string) ($item['category'] ?? '');
            if ($key === '') {
                continue;
            }
            if (!isset($categories[$key])) {
                $metadata = $this->decodeJson((string) ($item['modifiers_json'] ?? '{}'));
                $labels = is_array($metadata) && is_array($metadata['category'] ?? null) ? $metadata['category'] : [];
                $categories[$key] = [
                    'key' => $key,
                    'count' => 0,
                    'labels' => [
                        'zh-CN' => (string) ($labels['zh-CN'] ?? $key),
                        'en-US' => (string) ($labels['en-US'] ?? $key),
                        'es-US' => (string) ($labels['es-US'] ?? $key),
                    ],
                ];
            }
            $categories[$key]['count']++;
        }
        return array_values($categories);
    }

    /** @param array<string,string> $labels */
    public function saveMenuCategory(string $sourceKey, string $targetKey, array $labels): int
    {
        $sourceKey = trim($sourceKey);
        $targetKey = trim($targetKey);
        if ($sourceKey === '' || $targetKey === '' || mb_strlen($targetKey) > 191) {
            throw new \InvalidArgumentException('分类标识不能为空且不得超过 191 个字符。');
        }
        foreach (['zh-CN', 'en-US', 'es-US'] as $locale) {
            $label = trim((string) ($labels[$locale] ?? ''));
            if ($label === '' || mb_strlen($label) > 191) {
                throw new \InvalidArgumentException('请填写有效的中英西三语分类名称。');
            }
            $labels[$locale] = $label;
        }

        $this->pdo->beginTransaction();
        try {
            if ($sourceKey !== $targetKey) {
                $collision = $this->pdo->prepare('SELECT 1 FROM restaurant_menu_items WHERE category = :category LIMIT 1');
                $collision->execute([':category' => $targetKey]);
                if ($collision->fetchColumn() !== false) {
                    throw new \InvalidArgumentException('目标分类已存在，请先选择已有分类。');
                }
            }
            $stmt = $this->pdo->prepare('SELECT id, modifiers_json FROM restaurant_menu_items WHERE category = :category');
            $stmt->execute([':category' => $sourceKey]);
            $items = $stmt->fetchAll();
            if ($items === []) {
                throw new \InvalidArgumentException('原分类不存在。');
            }
            $update = $this->pdo->prepare('UPDATE restaurant_menu_items SET category = :category, modifiers_json = :metadata, updated_at = :updated_at WHERE id = :id');
            $now = $this->now();
            foreach ($items as $item) {
                $metadata = $this->decodeJson((string) ($item['modifiers_json'] ?? '{}'));
                $metadata = is_array($metadata) ? $metadata : [];
                $metadata['category'] = $labels;
                $update->execute([
                    ':category' => $targetKey,
                    ':metadata' => $this->json($metadata),
                    ':updated_at' => $now,
                    ':id' => (int) $item['id'],
                ]);
            }
            $this->pdo->commit();
            return count($items);
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    public function storefrontPayload(string $locale = '', ?\DateTimeImmutable $now = null): array
    {
        $stmt = $this->pdo->query("SELECT * FROM restaurant_locations WHERE status = 'active' ORDER BY id ASC LIMIT 1");
        $location = $stmt === false ? false : $stmt->fetch();
        $location = is_array($location) ? $location : [];
        $settings = $this->decodeJson((string) ($location['settings_json'] ?? '{}'));
        $settings = is_array($settings) ? $settings : [];
        $locale = $locale !== '' ? $locale : $this->defaultLocale($settings);
        $deliveryPostalCodes = is_array($settings['delivery_postal_codes'] ?? null)
            ? array_values(array_filter(array_map(static fn (mixed $code): string => is_string($code) ? strtoupper(trim($code)) : '', $settings['delivery_postal_codes']), static fn (string $code): bool => preg_match('/^[A-Z0-9 -]{3,10}$/', $code) === 1))
            : [];
        $unconfiguredName = ['zh-CN' => '餐馆尚未设置', 'en-US' => 'Restaurant not configured', 'es-US' => 'Restaurante sin configurar'];
        $name = $this->localizedValue($settings['name'] ?? null, $locale, (string) ($location['name'] ?? ($unconfiguredName[$this->normalizeLocale($locale)] ?? $unconfiguredName['zh-CN'])));
        $hours = $this->openingState($settings, (string) ($location['timezone'] ?? 'UTC'), $now);

        return [
            'ok' => true,
            'locale' => $this->normalizeLocale($locale),
            'default_locale' => $this->defaultLocale($settings),
            'restaurant' => [
                'configured' => $location !== [],
                'name' => $name,
                'logo_url' => (string) ($settings['logo_url'] ?? ''),
                'phone' => (string) ($location['phone'] ?? $settings['phone'] ?? ''),
                'address' => $this->localizedValue($settings['address'] ?? null, $locale, (string) ($location['address'] ?? '')),
                'timezone' => (string) ($location['timezone'] ?? 'Asia/Shanghai'),
                'status' => $hours['status'],
                'status_reason' => $this->localizedValue($settings['status_reason'] ?? null, $locale, ''),
                'today_hours' => $hours['today_hours'] !== '' ? $hours['today_hours'] : $this->localizedValue($settings['today_hours'] ?? null, $locale, (string) ($settings['hours_text'] ?? '')),
                'hours' => is_array($settings['hours'] ?? null) ? $settings['hours'] : [],
                'service_modes' => [
                    'pickup' => [
                        'available' => $location !== [] && (bool) ($settings['pickup_available'] ?? false),
                        'reason' => $this->localizedValue($settings['pickup_reason'] ?? null, $locale, ''),
                    ],
                    'delivery' => [
                        'available' => $location !== [] && (bool) ($settings['delivery_available'] ?? false) && isset($settings['delivery_fee_minor']) && filter_var($settings['delivery_fee_minor'], FILTER_VALIDATE_INT) !== false && (int) $settings['delivery_fee_minor'] >= 0 && $deliveryPostalCodes !== [],
                        'reason' => $this->localizedValue($settings['delivery_reason'] ?? null, $locale, ''),
                        'fee_minor' => max(0, (int) ($settings['delivery_fee_minor'] ?? 0)),
                        'fee' => $this->minorToMoney(max(0, (int) ($settings['delivery_fee_minor'] ?? 0))),
                        'postal_codes' => $deliveryPostalCodes,
                    ],
                ],
                'preorder' => [
                    'available' => (bool) ($settings['preorder_available'] ?? false) && !in_array((string) ($settings['status'] ?? 'closed'), ['paused', 'closed'], true) && is_array($settings['hours'] ?? null) && $settings['hours'] !== [],
                    'lead_minutes' => max(15, min(1440, (int) ($settings['preorder_lead_minutes'] ?? 30))),
                    'max_days' => max(1, min(30, (int) ($settings['preorder_max_days'] ?? 7))),
                ],
            ],
            'hero' => [
                'image_url' => (string) ($settings['storefront_image_url'] ?? ''),
                'desktop_focal_point' => (string) ($settings['desktop_focal_point'] ?? '50% 50%'),
                'mobile_focal_point' => (string) ($settings['mobile_focal_point'] ?? '50% 45%'),
                'headline' => $this->localizedValue($settings['headline'] ?? null, $locale, $name),
                'subtitle' => $this->localizedValue($settings['subtitle'] ?? null, $locale, ''),
            ],
            'gallery' => $this->normalizeGallery($settings['gallery'] ?? [], $locale),
            'about' => [
                'title' => $this->localizedValue($settings['about_title'] ?? null, $locale, ''),
                'body' => $this->localizedValue($settings['about_body'] ?? null, $locale, ''),
            ],
            'seo' => [
                'schema_type' => 'Restaurant',
                'indexable' => true,
            ],
            'capabilities' => [
                'cart_api' => true,
                'checkout_api' => true,
                'schedule_order' => (bool) ($settings['preorder_available'] ?? false) && !in_array((string) ($settings['status'] ?? 'closed'), ['paused', 'closed'], true) && is_array($settings['hours'] ?? null) && $settings['hours'] !== [],
            ],
        ];
    }

    /** @return array{due_at:?string,fee_minor:int} */
    public function validateFulfillment(string $mode, string $localTime, string $address, string $postalCode = '', ?\DateTimeImmutable $now = null): array
    {
        $storefront = $this->storefrontPayload();
        $restaurant = $storefront['restaurant'];
        $location = $this->storefrontSettings();
        $settings = json_decode((string) ($location['settings_json'] ?? '{}'), true);
        $settings = is_array($settings) ? $settings : [];
        if (!in_array($mode, ['pickup', 'delivery'], true) || !($restaurant['service_modes'][$mode]['available'] ?? false)
            || in_array((string) ($settings['status'] ?? 'closed'), ['paused', 'closed'], true)) {
            throw new \RuntimeException('当前不接受该方式的订单。');
        }
        if ($mode === 'delivery' && trim($address) === '') {
            throw new \RuntimeException('配送地址不能为空。');
        }
        if ($mode === 'delivery' && !in_array(strtoupper(trim($postalCode)), array_map('strtoupper', $restaurant['service_modes']['delivery']['postal_codes'] ?? []), true)) {
            throw new \RuntimeException('该邮编不在本店配送范围内。');
        }
        $fee = $mode === 'delivery' ? (int) ($restaurant['service_modes']['delivery']['fee_minor'] ?? 0) : 0;
        if ($localTime === '') {
            if (!in_array((string) $restaurant['status'], ['open', 'closing_soon'], true)) {
                throw new \RuntimeException('目前未营业，请选择可预约的时间。');
            }
            return ['due_at' => null, 'fee_minor' => $fee];
        }
        $preorder = $restaurant['preorder'] ?? [];
        if (!($preorder['available'] ?? false)) {
            throw new \RuntimeException('本店暂未开放预约。');
        }
        $zone = new \DateTimeZone((string) $restaurant['timezone']);
        $requested = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $localTime, $zone);
        if ($requested === false || $requested->format('Y-m-d\TH:i') !== $localTime) {
            throw new \RuntimeException('预约时间格式无效。');
        }
        $current = ($now ?? new \DateTimeImmutable('now', $zone))->setTimezone($zone);
        if ($requested->getTimestamp() < $current->getTimestamp() + ((int) $preorder['lead_minutes'] * 60)
            || $requested->getTimestamp() > $current->getTimestamp() + ((int) $preorder['max_days'] * 86400)
            || !in_array($this->openingState(['status' => 'open', 'hours' => $restaurant['hours']], (string) $restaurant['timezone'], $requested)['status'], ['open', 'closing_soon'], true)) {
            throw new \RuntimeException('预约时间不在可接单营业时段内。');
        }
        return ['due_at' => $requested->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'fee_minor' => $fee];
    }

    public function configureCartFulfillment(string $token, string $mode, ?string $dueAt, int $feeMinor, string $postalCode = ''): void
    {
        $cart = $this->cartByToken($token);
        if ($cart === null || !in_array((string) $cart['status'], ['open', 'checkout_started'], true)) {
            throw new \RuntimeException('餐车不可修改。');
        }
        $payload = is_array($cart['payload'] ?? null) ? $cart['payload'] : [];
        if (((string) $cart['status'] === 'checkout_started' || (int) ($cart['commerce_order_id'] ?? 0) > 0)
            && ((string) $cart['service_mode'] !== $mode || ($payload['fulfillment_at'] ?? null) !== $dueAt || (int) $cart['fee_minor'] !== $feeMinor || (string) ($payload['delivery_postal_code'] ?? '') !== strtoupper(trim($postalCode)))) {
            throw new \RuntimeException('已开始支付的订单不能更改配送或预约时间。');
        }
        $payload['fulfillment_at'] = $dueAt;
        $payload['delivery_postal_code'] = $mode === 'delivery' ? strtoupper(trim($postalCode)) : '';
        $pricing = $this->cartPricing($cart, $mode, $feeMinor);
        $payload['pricing_snapshot'] = $pricing;
        $this->pdo->prepare('UPDATE restaurant_carts SET service_mode = :mode, fee_minor = :fee, total_minor = :total, payload_json = :payload, updated_at = :updated_at WHERE token = :token')
            ->execute([':mode' => $mode, ':fee' => $feeMinor, ':total' => $pricing['total_minor'], ':payload' => $this->json($payload), ':updated_at' => $this->now(), ':token' => $token]);
    }

    /** @return array<string,mixed> */
    public function menuPayload(string $locale = '', string $query = ''): array
    {
        $locale = $locale !== '' ? $locale : $this->defaultLocale();
        $items = array_map(fn (array $row): array => $this->menuItemPayload($row, $locale), $this->activeMenuItems());
        $query = trim(mb_strtolower($query));
        if ($query !== '') {
            $items = array_values(array_filter($items, static function (array $item) use ($query): bool {
                return str_contains(mb_strtolower(implode(' ', $item['search_terms'])), $query);
            }));
        }

        $categories = [];
        foreach ($items as $item) {
            $slug = $this->slug((string) ($item['category'] ?? 'menu'));
            if (!isset($categories[$slug])) {
                $categories[$slug] = [
                    'slug' => $slug,
                    'name' => (string) ($item['category'] ?? 'Menu'),
                    'count' => 0,
                ];
            }
            $categories[$slug]['count']++;
        }

        $featured = array_values(array_filter($items, static fn (array $item): bool => (bool) ($item['featured'] ?? false)));
        usort($featured, static fn (array $left, array $right): int => ($left['featured_order'] <=> $right['featured_order']) ?: ($left['id'] <=> $right['id']));

        return [
            'ok' => true,
            'locale' => $this->normalizeLocale($locale),
            'categories' => array_values($categories),
            'items' => $items,
            'featured' => $featured,
            'query' => $query,
        ];
    }

    public function specialRequestText(array $metadata): string
    {
        return $this->editableModifierText($metadata, 'special_requests', false);
    }

    public function paidAddonText(array $metadata): string
    {
        return $this->editableModifierText($metadata, 'paid_addons', true);
    }

    private function editableModifierText(array $metadata, string $groupId, bool $priced): string
    {
        $lines = [];
        foreach ($metadata['modifiers'] ?? [] as $group) {
            if (($group['id'] ?? '') !== $groupId) {
                continue;
            }
            foreach ($group['options'] ?? [] as $option) {
                $labels = $option['name'] ?? '';
                $line = is_array($labels) ? implode(' | ', [$labels['zh-CN'] ?? '', $labels['en-US'] ?? '', $labels['es-US'] ?? '']) : (string) $labels;
                $lines[] = $line . ($priced ? ' | ' . (string) ($option['price'] ?? '0.00') : '');
            }
        }
        return implode("\n", $lines);
    }

    public function withSpecialRequests(array $metadata, string $text): array
    {
        return $this->withEditableOptions($metadata, $text, 'special_requests', false);
    }

    public function withPaidAddons(array $metadata, string $text): array
    {
        return $this->withEditableOptions($metadata, $text, 'paid_addons', true);
    }

    public function paidAddonChoices(): array
    {
        return [
            'rice' => ['zh-CN' => '加米饭', 'en-US' => 'Extra rice', 'es-US' => 'Arroz extra'],
            'chicken' => ['zh-CN' => '加鸡肉', 'en-US' => 'Extra chicken', 'es-US' => 'Pollo extra'],
            'beef' => ['zh-CN' => '加牛肉', 'en-US' => 'Extra beef', 'es-US' => 'Carne extra'],
        ];
    }

    public function paidAddonRows(array $metadata): array
    {
        $rows = [];
        foreach ($metadata['modifiers'] ?? [] as $group) {
            if (($group['id'] ?? '') !== 'paid_addons') {
                continue;
            }
            foreach ($group['options'] ?? [] as $option) {
                $labels = is_array($option['name'] ?? null) ? $option['name'] : ['zh-CN' => (string) ($option['name'] ?? '')];
                $kind = 'custom';
                foreach ($this->paidAddonChoices() as $key => $choice) {
                    if ($labels === $choice) {
                        $kind = $key;
                        break;
                    }
                }
                $rows[] = ['kind' => $kind, 'name' => $labels['zh-CN'] ?? '', 'name_en' => $labels['en-US'] ?? '', 'name_es' => $labels['es-US'] ?? '', 'price' => (string) ($option['price'] ?? '0.00')];
            }
        }
        return $rows;
    }

    public function withPaidAddonRows(array $metadata, array $rows): array
    {
        if (count($rows) > 20) {
            throw new \InvalidArgumentException('每道菜最多 20 个加料选项。');
        }
        $lines = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('加料行格式无效。');
            }
            foreach (['kind', 'price', 'name', 'name_en', 'name_es'] as $field) {
                if (isset($row[$field]) && !is_string($row[$field])) {
                    throw new \InvalidArgumentException('加料名称与价格须为文字。');
                }
            }
            $kind = (string) ($row['kind'] ?? '');
            $labels = $this->paidAddonChoices()[$kind] ?? null;
            if ($kind === 'custom') {
                $name = trim((string) ($row['name'] ?? ''));
                $labels = ['zh-CN' => $name, 'en-US' => trim((string) ($row['name_en'] ?? '')) ?: $name, 'es-US' => trim((string) ($row['name_es'] ?? '')) ?: $name];
            }
            if ($labels === null || in_array('', $labels, true)) {
                throw new \InvalidArgumentException('请选择要加的东西，其他加料须填写名称。');
            }
            foreach ($labels as $label) {
                if (preg_match('/[|\r\n]/u', $label) === 1) {
                    throw new \InvalidArgumentException('加料名称不能含竖线或换行。');
                }
            }
            $lines[] = implode(' | ', $labels) . ' | ' . trim((string) ($row['price'] ?? ''));
        }
        return $this->withPaidAddons($metadata, implode("\n", $lines));
    }

    private function withEditableOptions(array $metadata, string $text, string $groupId, bool $priced): array
    {
        if (strlen($text) > 24000 || !mb_check_encoding($text, 'UTF-8')) {
            throw new \InvalidArgumentException('额外选项须为 UTF-8 文字且不超过 24 KB。');
        }
        $options = [];
        foreach (preg_split('/\R/u', trim($text)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $labels = array_map('trim', explode('|', $line));
            if (count($labels) !== ($priced ? 4 : 3) || in_array('', $labels, true)) {
                throw new \InvalidArgumentException($priced ? '收费加料每行须填写：中文 | English | Español | 每份加价；名称与价格均不可为空。' : '额外选项每行须填写：中文 | English | Español；三种名称均不可为空。');
            }
            $price = $priced ? array_pop($labels) : '0.00';
            if (preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', $price) !== 1) {
                throw new \InvalidArgumentException('加料价格必须为非负金额，最多两位小数（例如 1.50）。');
            }
            $priceParts = explode('.', $price, 2);
            $price = $this->minorToMoney((int) $priceParts[0] * 100 + (int) str_pad($priceParts[1] ?? '', 2, '0'));
            foreach ($labels as $label) {
                if (mb_strlen($label) > 100) {
                    throw new \InvalidArgumentException('额外选项名称不可超过 100 字。');
                }
            }
            $optionId = ($priced ? 'addon_' : 'request_') . substr(hash('sha256', implode('|', $labels)), 0, 16);
            if (isset($options[$optionId])) {
                throw new \InvalidArgumentException('额外选项重复。');
            }
            $options[$optionId] = ['id' => $optionId, 'name' => array_combine(['zh-CN', 'en-US', 'es-US'], $labels), 'price' => $price];
        }
        if (count($options) > 20) {
            throw new \InvalidArgumentException('每道菜最多 20 个额外选项。');
        }
        $groups = $metadata['modifiers'] ?? [];
        if (!is_array($groups)) {
            throw new \InvalidArgumentException('规格数据必须为数组。');
        }
        foreach ($groups as $group) {
            if (!is_array($group)) {
                throw new \InvalidArgumentException('每个规格组必须为对象；未保存任何修改。');
            }
        }
        $metadata['modifiers'] = array_values(array_filter($groups, static fn ($group): bool => is_array($group) && ($group['id'] ?? '') !== $groupId));
        if ($options !== []) {
            $name = $priced ? ['zh-CN' => '收费加料（每份加价）', 'en-US' => 'Add-ons (per serving)', 'es-US' => 'Extras (por porción)'] : ['zh-CN' => '额外要求（不改变售价）', 'en-US' => 'Special requests (same price)', 'es-US' => 'Peticiones especiales (mismo precio)'];
            $metadata['modifiers'][] = ['id' => $groupId, 'name' => $name, 'min' => 0, 'max' => count($options), 'options' => array_values($options)];
        }
        return $metadata;
    }

    public function importReviewedMenu(array $rows, string $sourceHash, bool $activate = false): array
    {
        if ($rows === [] || count($rows) > 200 || preg_match('/^[a-f0-9]{64}$/', $sourceHash) !== 1) {
            throw new \InvalidArgumentException('菜单草稿无效或未选择菜品。');
        }
        foreach ($rows as $row) {
            if (!is_array($row) || trim((string) ($row['name'] ?? '')) === '' || mb_strlen((string) $row['name']) > 191
                || trim((string) ($row['category'] ?? '')) === '' || mb_strlen((string) $row['category']) > 191
                || preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', (string) ($row['price'] ?? '')) !== 1
                || preg_match('/^[A-Z]{3}$/', (string) ($row['currency'] ?? '')) !== 1
                || !in_array($row['menu_section'] ?? '', ['all_day', 'lunch', 'dinner', 'kids'], true)
                || mb_strlen((string) ($row['description'] ?? '')) > 2000) {
                throw new \InvalidArgumentException('请核对选中行的菜名、分类、价格、币种、分组和说明；错误行不会导入。');
            }
        }
        $key = static fn (array $row): string => hash('sha256', mb_strtolower(trim((string) $row['name']) . '|' . trim((string) $row['category']) . '|' . (string) $row['menu_section']));
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $existing = [];
            foreach ($this->menuItems() as $item) {
                $metadata = $this->decodeJson((string) $item['modifiers_json']);
                $existing[$key(['name' => $item['name'], 'category' => $item['category'], 'menu_section' => $metadata['menu_section'] ?? 'all_day'])] = true;
            }
            $result = ['created' => 0, 'skipped' => 0];
            foreach ($rows as $row) {
                $identity = $key($row);
                if (isset($existing[$identity])) {
                    $result['skipped']++;
                    continue;
                }
                $this->saveMenuItem(['name' => $row['name'], 'category' => $row['category'], 'menu_section' => $row['menu_section'], 'price' => $row['price'], 'currency' => $row['currency'], 'status' => $activate ? 'active' : 'disabled', 'station_code' => 'front', 'metadata' => ['description' => (string) ($row['description'] ?? ''), 'review_status' => 'reviewed', 'import_source_hash' => $sourceHash]]);
                $existing[$identity] = true;
                $result['created']++;
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function saveMenuItem(array $data): int
    {
        $now = $this->now();
        $id = (int) ($data['id'] ?? 0);
        $existing = $id > 0 ? ($this->menuItem($id) ?? []) : [];
        $status = in_array((string) ($data['status'] ?? 'active'), ['active', 'sold_out', 'disabled'], true) ? (string) ($data['status'] ?? 'active') : 'active';
        $metadata = $data['metadata'] ?? $data['modifiers'] ?? [];
        $metadata = is_array($metadata) ? $metadata : [];
        $menuSection = (string) ($data['menu_section'] ?? $metadata['menu_section'] ?? 'all_day');
        if (!in_array($menuSection, ['all_day', 'lunch', 'dinner', 'kids'], true)) {
            throw new \InvalidArgumentException('无效的用餐分组。');
        }
        $metadata['menu_section'] = $menuSection;
        $category = trim((string) ($data['category'] ?? ''));
        if ($category !== '' && ($id === 0 || $category !== (string) ($existing['category'] ?? ''))) {
            $categoryStmt = $this->pdo->prepare('SELECT modifiers_json FROM restaurant_menu_items WHERE category = :category LIMIT 1');
            $categoryStmt->execute([':category' => $category]);
            $categoryRow = $categoryStmt->fetch();
            if (is_array($categoryRow)) {
                $categoryMetadata = $this->decodeJson((string) ($categoryRow['modifiers_json'] ?? '{}'));
                if (is_array($categoryMetadata) && is_array($categoryMetadata['category'] ?? null)) {
                    $metadata['category'] = $categoryMetadata['category'];
                } else {
                    unset($metadata['category']);
                }
            } elseif ($id > 0) {
                unset($metadata['category']);
            }
        }
        $payload = [
            ':commerce_product_id' => ((int) ($data['commerce_product_id'] ?? $existing['commerce_product_id'] ?? 0)) > 0 ? (int) ($data['commerce_product_id'] ?? $existing['commerce_product_id']) : null,
            ':name' => trim((string) ($data['name'] ?? '')),
            ':category' => $category,
            ':price_minor' => max(0, $this->moneyToMinor((string) ($data['price'] ?? $data['price_minor'] ?? '0'))),
            ':currency' => strtoupper(substr(trim((string) ($data['currency'] ?? 'CNY')), 0, 3)),
            ':station_code' => $this->stationCode((string) ($data['station_code'] ?? 'front')),
            ':prep_minutes' => max(0, (int) ($data['prep_minutes'] ?? 10)),
            ':status' => $status,
            ':modifiers_json' => $this->json($metadata),
            ':updated_at' => $now,
        ];
        if ($payload[':name'] === '') {
            throw new \InvalidArgumentException('菜品名称不能为空。');
        }

        if ($id > 0) {
            $payload[':id'] = $id;
            $this->pdo->prepare('UPDATE restaurant_menu_items SET commerce_product_id = :commerce_product_id, name = :name, category = :category, price_minor = :price_minor, currency = :currency, station_code = :station_code, prep_minutes = :prep_minutes, status = :status, modifiers_json = :modifiers_json, updated_at = :updated_at WHERE id = :id')
                ->execute($payload);
            return $id;
        }

        $payload[':created_at'] = $now;
        $this->pdo->prepare('INSERT INTO restaurant_menu_items (commerce_product_id, name, category, price_minor, currency, station_code, prep_minutes, status, modifiers_json, created_at, updated_at) VALUES (:commerce_product_id, :name, :category, :price_minor, :currency, :station_code, :prep_minutes, :status, :modifiers_json, :created_at, :updated_at)')
            ->execute($payload);
        return (int) $this->pdo->lastInsertId();
    }

    public function defaultLocale(?array $settings = null): string
    {
        $settings ??= $this->decodeJson((string) ($this->storefrontSettings()['settings_json'] ?? '{}'));
        $locale = $settings['default_locale'] ?? 'en-US';
        return in_array($locale, ['en-US', 'zh-CN', 'es-US'], true) ? $locale : 'en-US';
    }

    /** @return array<string,mixed> */
    public function storefrontSettings(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM restaurant_locations WHERE status = 'active' ORDER BY id ASC LIMIT 1");
        $row = $stmt === false ? false : $stmt->fetch();
        return is_array($row) ? $row : [];
    }

    public function formatDueLocal(?string $dueAt): string
    {
        if ($dueAt === null || $dueAt === '') {
            return '';
        }
        $location = $this->storefrontSettings();
        try {
            return (new \DateTimeImmutable($dueAt, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone((string) ($location['timezone'] ?? 'UTC')))
                ->format('Y-m-d H:i T');
        } catch (\Throwable) {
            return $dueAt . ' UTC';
        }
    }

    public function saveStorefrontSettings(array $data): void
    {
        $settings = $data['settings'] ?? [];
        if (!is_array($settings)) {
            throw new \InvalidArgumentException('店面设置格式无效。');
        }
        if (array_key_exists('default_locale', $settings) && !in_array($settings['default_locale'], ['en-US', 'zh-CN', 'es-US'], true)) {
            throw new \InvalidArgumentException('首选语言须为 English、中文或 Español。');
        }
        $this->taxSettings($settings);
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('餐馆名称不能为空。');
        }
        $now = $this->now();
        $row = $this->storefrontSettings();
        $values = [
            ':name' => $name,
            ':phone' => trim((string) ($data['phone'] ?? '')),
            ':address' => trim((string) ($data['address'] ?? '')),
            ':timezone' => trim((string) ($data['timezone'] ?? 'America/New_York')),
            ':settings' => $this->json($settings),
            ':updated_at' => $now,
        ];
        if ($row !== []) {
            $values[':id'] = (int) $row['id'];
            $this->pdo->prepare('UPDATE restaurant_locations SET name = :name, phone = :phone, address = :address, timezone = :timezone, settings_json = :settings, updated_at = :updated_at WHERE id = :id')->execute($values);
            return;
        }
        $values[':created_at'] = $now;
        $this->pdo->prepare("INSERT INTO restaurant_locations (name, status, phone, address, timezone, settings_json, created_at, updated_at) VALUES (:name, 'active', :phone, :address, :timezone, :settings, :created_at, :updated_at)")->execute($values);
    }

    public function createTicket(array $ticket, array $items): int
    {
        $now = $this->now();
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $payload = [
                ':uuid' => $ticket['uuid'] ?? $this->uuid(),
                ':commerce_order_id' => ((int) ($ticket['commerce_order_id'] ?? 0)) > 0 ? (int) $ticket['commerce_order_id'] : null,
                ':ticket_number' => $ticket['ticket_number'] ?? $this->ticketNumber(),
                ':customer_name' => trim((string) ($ticket['customer_name'] ?? '')),
                ':customer_phone' => trim((string) ($ticket['customer_phone'] ?? '')),
                ':service_mode' => in_array((string) ($ticket['service_mode'] ?? 'pickup'), ['pickup', 'delivery', 'dine_in'], true) ? (string) $ticket['service_mode'] : 'pickup',
                ':status' => 'new',
                ':source' => (string) ($ticket['source'] ?? 'manual'),
                ':due_at' => $ticket['due_at'] ?? null,
                ':payload_json' => $this->json($ticket['payload'] ?? []),
                ':created_at' => $now,
                ':updated_at' => $now,
            ];
            $this->pdo->prepare('INSERT INTO restaurant_order_tickets (uuid, commerce_order_id, ticket_number, customer_name, customer_phone, service_mode, status, source, due_at, payload_json, created_at, updated_at) VALUES (:uuid, :commerce_order_id, :ticket_number, :customer_name, :customer_phone, :service_mode, :status, :source, :due_at, :payload_json, :created_at, :updated_at)')
                ->execute($payload);
            $ticketId = (int) $this->pdo->lastInsertId();

            $stmt = $this->pdo->prepare('INSERT INTO restaurant_ticket_items (ticket_id, menu_item_id, commerce_product_id, station_code, item_name, quantity, notes, modifiers_json, status, created_at, updated_at) VALUES (:ticket_id, :menu_item_id, :commerce_product_id, :station_code, :item_name, :quantity, :notes, :modifiers_json, :status, :created_at, :updated_at)');
            foreach ($items as $item) {
                $stmt->execute([
                    ':ticket_id' => $ticketId,
                    ':menu_item_id' => ((int) ($item['menu_item_id'] ?? 0)) > 0 ? (int) $item['menu_item_id'] : null,
                    ':commerce_product_id' => ((int) ($item['commerce_product_id'] ?? 0)) > 0 ? (int) $item['commerce_product_id'] : null,
                    ':station_code' => $this->stationCode((string) ($item['station_code'] ?? 'front')),
                    ':item_name' => trim((string) ($item['item_name'] ?? $item['name'] ?? '菜品')),
                    ':quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                    ':notes' => trim((string) ($item['notes'] ?? '')),
                    ':modifiers_json' => $this->json($item['modifiers'] ?? []),
                    ':status' => 'new',
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $ticketId;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function transaction(callable $operation): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $operation();
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function updateTicketStatus(int $ticketId, string $status): void
    {
        if (!in_array($status, ['new', 'accepted', 'preparing', 'ready', 'completed', 'cancelled'], true)) {
            throw new \InvalidArgumentException('订单状态无效。');
        }
        $this->pdo->prepare('UPDATE restaurant_order_tickets SET status = :status, updated_at = :updated_at WHERE id = :id')
            ->execute([':id' => $ticketId, ':status' => $status, ':updated_at' => $this->now()]);
        if ($status === 'cancelled') {
            $this->holdTicketPrintJobs($ticketId, 'TICKET_CANCELLED');
        }
    }

    /** @return list<array<string,mixed>> */
    public function tickets(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM restaurant_order_tickets ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function ticketItems(int $ticketId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM restaurant_ticket_items WHERE ticket_id = :ticket_id ORDER BY station_code ASC, id ASC');
        $stmt->execute([':ticket_id' => $ticketId]);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function ticket(int $ticketId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM restaurant_order_tickets WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $ticketId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function enqueuePrintJob(int $ticketId, ?int $printerId, string $stationCode, string $provider, string $payload, ?int $sourceJobId = null, string $jobKind = 'order', string $requestKey = ''): int
    {
        $now = $this->now();
        $key = $sourceJobId === null ? 'ticket:' . $ticketId . ':printer:' . ($printerId ?? 0) . ':station:' . $this->stationCode($stationCode) : 'reprint:' . $sourceJobId . ':' . hash('sha256', $requestKey);
        $lookup = $this->pdo->prepare('SELECT id FROM restaurant_print_jobs WHERE dedup_key = :key');
        $lookup->execute([':key' => $key]);
        $existing = $lookup->fetchColumn();
        if ($existing !== false) {
            return (int) $existing;
        }
        try {
            $this->pdo->prepare('INSERT INTO restaurant_print_jobs (uuid, ticket_id, printer_id, station_code, provider, status, attempts, payload_text, dedup_key, source_job_id, job_kind, created_at, updated_at) VALUES (:uuid, :ticket_id, :printer_id, :station_code, :provider, :status, 0, :payload_text, :dedup_key, :source_job_id, :job_kind, :created_at, :updated_at)')
            ->execute([
                ':uuid' => $this->uuid(),
                ':ticket_id' => $ticketId,
                ':printer_id' => $printerId,
                ':station_code' => $this->stationCode($stationCode),
                ':provider' => $provider,
                ':status' => $provider === 'manual' ? 'pending_manual' : 'queued',
                ':payload_text' => $payload,
                ':dedup_key' => $key,
                ':source_job_id' => $sourceJobId,
                ':job_kind' => $jobKind === 'test' ? 'test' : 'order',
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        } catch (\PDOException $exception) {
            $lookup->execute([':key' => $key]);
            $existing = $lookup->fetchColumn();
            if ($existing !== false) {
                return (int) $existing;
            }
            throw $exception;
        }
        $id = (int) $this->pdo->lastInsertId();
        $this->printAudit($id, 'job.enqueued', 'system', $jobKind === 'test' ? 'TEST ONLY' : 'ticket:' . $ticketId);
        return $id;
    }

    /** @return list<array<string,mixed>> */
    public function printJobs(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare('SELECT j.*, p.name AS printer_name, t.commerce_order_id FROM restaurant_print_jobs j LEFT JOIN restaurant_print_devices p ON p.id = j.printer_id LEFT JOIN restaurant_order_tickets t ON t.id = j.ticket_id ORDER BY j.id DESC LIMIT :limit');
        $stmt->bindValue(':limit', max(1, min(300, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function queuedAgentJobs(string $agentId, string $token, int $limit = 10): array
    {
        $printers = $this->authorizedAgentPrinters($agentId, $token);
        if ($printers === []) {
            return [];
        }
        $ids = array_map(static fn (array $printer): int => (int) $printer['id'], $printers);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $expire = $this->pdo->prepare("UPDATE restaurant_print_jobs SET status = 'needs_review', last_error = 'CLAIM_EXPIRED_OUTCOME_UNKNOWN', updated_at = ? WHERE provider = 'agent' AND status = 'printing' AND claim_expires_at < ? AND printer_id IN ({$placeholders})");
        $expire->execute([$this->now(), $this->now(), ...$ids]);
        $stmt = $this->pdo->prepare("SELECT j.*, t.commerce_order_id FROM restaurant_print_jobs j JOIN restaurant_order_tickets t ON t.id = j.ticket_id WHERE j.provider = 'agent' AND j.status IN ('queued','retry') AND t.status <> 'cancelled' AND j.printer_id IN ({$placeholders}) ORDER BY j.id ASC LIMIT " . max(1, min(50, $limit)));
        $stmt->execute($ids);
        $claimed = [];
        foreach ($stmt->fetchAll() as $job) {
            $claimToken = bin2hex(random_bytes(32));
            $expiresAt = gmdate('Y-m-d H:i:s', time() + 120);
            $update = $this->pdo->prepare("UPDATE restaurant_print_jobs SET status = 'printing', claim_token_hash = :hash, claim_expires_at = :expires, dispatch_started_at = NULL, attempts = attempts + 1, updated_at = :now WHERE id = :id AND status IN ('queued','retry') AND printer_id = :printer_id AND ticket_id IN (SELECT id FROM restaurant_order_tickets WHERE status <> 'cancelled')");
            $update->execute([
                ':hash' => hash('sha256', $claimToken), ':expires' => $expiresAt,
                ':now' => $this->now(), ':id' => (int) $job['id'], ':printer_id' => (int) $job['printer_id'],
            ]);
            if ($update->rowCount() === 1) {
                $job['claim_token'] = $claimToken;
                $job['claim_expires_at'] = $expiresAt;
                $job['attempts'] = (int) $job['attempts'] + 1;
                $this->printAudit((int) $job['id'], 'job.claimed', 'bridge:' . $agentId, 'lease:' . $expiresAt);
                $claimed[] = $job;
            }
        }
        return $claimed;
    }

    public function reportAgentJob(string $agentId, string $token, int $jobId, string $claimToken, string $status, string $error = ''): bool
    {
        if (!in_array($status, ['submitted', 'printed', 'failed', 'needs_review'], true) || $claimToken === '') {
            return false;
        }
        $printerIds = array_map('intval', array_column($this->authorizedAgentPrinters($agentId, $token), 'id'));
        $stmt = $this->pdo->prepare("SELECT * FROM restaurant_print_jobs WHERE id = :id AND provider = 'agent'");
        $stmt->execute([':id' => $jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($job) || !in_array((int) $job['printer_id'], $printerIds, true)
            || !hash_equals((string) ($job['claim_token_hash'] ?? ''), hash('sha256', $claimToken))) {
            return false;
        }
        if ((string) $job['status'] === $status) {
            return true;
        }
        if ((string) $job['status'] !== 'printing' || (string) ($job['claim_expires_at'] ?? '') < $this->now()) {
            return false;
        }
        $update = $this->pdo->prepare("UPDATE restaurant_print_jobs SET status = :status, last_error = :error, printed_at = CASE WHEN :is_printed = 1 THEN :printed_at ELSE printed_at END, updated_at = :updated_at WHERE id = :id AND status = 'printing' AND claim_token_hash = :hash AND claim_expires_at >= :valid_until");
        $update->execute([
            ':status' => $status, ':error' => mb_substr($error, 0, 500), ':is_printed' => $status === 'printed' ? 1 : 0,
            ':printed_at' => $this->now(), ':updated_at' => $this->now(), ':valid_until' => $this->now(),
            ':id' => $jobId, ':hash' => hash('sha256', $claimToken),
        ]);
        if ($update->rowCount() !== 1) {
            return false;
        }
        $this->printAudit($jobId, 'job.' . $status, 'bridge:' . $agentId, $error);
        if (($job['job_kind'] ?? '') === 'test') {
            $this->pdo->prepare('UPDATE restaurant_print_devices SET last_test_status = :status, last_test_message = :message, last_tested_at = :now WHERE id = :id')
                ->execute([':status' => $status, ':message' => mb_substr($error, 0, 500), ':now' => $this->now(), ':id' => $job['printer_id']]);
        }
        return true;
    }

    public function bridgePrinters(string $agentId, string $token): array
    {
        return $this->authorizedAgentPrinters($agentId, $token);
    }

    public function bridgeHeartbeat(string $agentId, string $token): void
    {
        foreach ($this->authorizedAgentPrinters($agentId, $token) as $printer) {
            $this->pdo->prepare('UPDATE restaurant_print_devices SET bridge_last_seen_at = :now WHERE id = :id')->execute([':now' => $this->now(), ':id' => $printer['id']]);
        }
    }

    public function printJob(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT j.*, t.commerce_order_id, t.status AS ticket_status FROM restaurant_print_jobs j JOIN restaurant_order_tickets t ON t.id = j.ticket_id WHERE j.id = :id');
        $stmt->execute([':id' => $jobId]);
        $job = $stmt->fetch();
        return is_array($job) ? $job : null;
    }

    public function beginAgentDispatch(string $agentId, string $token, int $jobId, string $claimToken): bool
    {
        $job = $this->printJob($jobId);
        if ($job === null || !in_array((int) $job['printer_id'], array_map('intval', array_column($this->authorizedAgentPrinters($agentId, $token), 'id')), true) || $claimToken === '' || !hash_equals((string) ($job['claim_token_hash'] ?? ''), hash('sha256', $claimToken))) {
            return false;
        }
        $stmt = $this->pdo->prepare("UPDATE restaurant_print_jobs SET dispatch_started_at = :now WHERE id = :id AND status = 'printing' AND claim_expires_at >= :valid_until AND dispatch_started_at IS NULL AND ticket_id IN (SELECT id FROM restaurant_order_tickets WHERE status <> 'cancelled')");
        $stmt->execute([':now' => $this->now(), ':valid_until' => $this->now(), ':id' => $jobId]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        $this->printAudit($jobId, 'job.dispatch_started', 'bridge:' . $agentId, 'Output may now be irreversible; never auto replay.');
        return true;
    }

    public function holdTicketPrintJobs(int $ticketId, string $reason): void
    {
        $stmt = $this->pdo->prepare("SELECT id FROM restaurant_print_jobs WHERE ticket_id = :id AND status <> 'cancelled' AND (last_error IS NULL OR last_error <> :reason)");
        $stmt->execute([':id' => $ticketId, ':reason' => $reason]);
        foreach ($stmt->fetchAll() as $job) {
            $this->pdo->prepare("UPDATE restaurant_print_jobs SET status = CASE WHEN status IN ('printing','submitted','printed','needs_review') THEN 'needs_review' ELSE 'cancelled' END, last_error = :reason, updated_at = :now WHERE id = :id")
                ->execute([':reason' => $reason, ':now' => $this->now(), ':id' => $job['id']]);
            $this->printAudit((int) $job['id'], 'job.held', 'system', $reason);
        }
    }

    public function reprintJob(int $jobId, string $reason, string $actor, string $requestKey): int
    {
        return $this->transaction(function () use ($jobId, $reason, $actor, $requestKey): int {
            $job = $this->printJob($jobId);
            if ($job === null || in_array($job['status'], ['queued', 'retry', 'printing', 'cancelled'], true) || $job['ticket_status'] === 'cancelled' || mb_strlen(trim($reason)) < 3 || preg_match('/^[a-f0-9]{32,64}$/', $requestKey) !== 1) {
                throw new \InvalidArgumentException('须先核实结果、填写原因；不能重印正在处理或已取消的订单。');
            }
            $id = $this->enqueuePrintJob((int) $job['ticket_id'], $job['printer_id'] === null ? null : (int) $job['printer_id'], (string) $job['station_code'], (string) $job['provider'], 'REPRINT / 人工重印 / REIMPRESIÓN #' . $jobId . "\n" . $job['payload_text'], $jobId, (string) $job['job_kind'], $requestKey);
            $this->printAudit($id, 'job.reprinted', $actor, 'source:' . $jobId . ' ' . $reason);
            return $id;
        });
    }

    public function confirmManualPrint(int $jobId, string $reason, string $actor): void
    {
        $job = $this->printJob($jobId);
        if ($job === null || !in_array($job['status'], ['needs_review', 'pending_manual', 'failed'], true) || mb_strlen(trim($reason)) < 3) {
            throw new \InvalidArgumentException('仅可在现场核实后确认待核实/人工任务，须填写原因。');
        }
        $statement = $this->pdo->prepare("UPDATE restaurant_print_jobs SET status = 'printed', printed_at = :now, updated_at = :updated_at WHERE id = :id AND status IN ('needs_review','pending_manual','failed')");
        $statement->execute([':now' => $this->now(), ':updated_at' => $this->now(), ':id' => $jobId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('打印状态已改变，请刷新并重新核实。');
        }
        $this->printAudit($jobId, 'job.owner_confirmed', $actor, $reason);
    }

    public function printAudit(?int $jobId, string $action, string $actor, string $message): void
    {
        $this->pdo->prepare('INSERT INTO restaurant_print_audit (job_id, action, actor, message, created_at) VALUES (:job_id, :action, :actor, :message, :now)')
            ->execute([':job_id' => $jobId, ':action' => $action, ':actor' => mb_substr($actor, 0, 191), ':message' => mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $message) ?? '', 0, 500), ':now' => $this->now()]);
    }

    public function printAudits(int $limit = 50): array
    {
        return $this->pdo->query('SELECT * FROM restaurant_print_audit ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)))->fetchAll();
    }

    private function authorizedAgentPrinters(string $agentId, string $token): array
    {
        if ($agentId === '' || $token === '') {
            return [];
        }
        return array_values(array_filter($this->printers(), static function (array $printer) use ($agentId, $token): bool {
            $hash = (string) ($printer['config']['agent_token_hash'] ?? '');
            return ($printer['provider'] ?? '') === 'agent'
                && ($printer['config']['agent_id'] ?? '') === $agentId
                && ($printer['status'] ?? '') === 'enabled'
                && $hash !== ''
                && password_verify($token, $hash);
        }));
    }

    public function markPrintJobAttempt(int $jobId, string $status, string $error = ''): void
    {
        $status = in_array($status, ['queued', 'retry', 'printing', 'printed', 'failed', 'pending_manual'], true) ? $status : 'failed';
        $this->pdo->prepare('UPDATE restaurant_print_jobs SET status = :status, attempts = attempts + 1, last_error = :last_error, printed_at = CASE WHEN :status_printed = 1 THEN :printed_at ELSE printed_at END, updated_at = :updated_at WHERE id = :id')
            ->execute([
                ':id' => $jobId,
                ':status' => $status,
                ':status_printed' => $status === 'printed' ? 1 : 0,
                ':printed_at' => $this->now(),
                ':last_error' => mb_substr($error, 0, 500),
                ':updated_at' => $this->now(),
            ]);
    }

    /** @return array<string,int> */
    public function stats(): array
    {
        return [
            'stations' => (int) $this->pdo->query('SELECT COUNT(*) FROM restaurant_kitchen_stations')->fetchColumn(),
            'printers' => (int) $this->pdo->query('SELECT COUNT(*) FROM restaurant_print_devices')->fetchColumn(),
            'menu_items' => (int) $this->pdo->query('SELECT COUNT(*) FROM restaurant_menu_items')->fetchColumn(),
            'open_tickets' => (int) $this->pdo->query("SELECT COUNT(*) FROM restaurant_order_tickets WHERE status NOT IN ('completed','cancelled')")->fetchColumn(),
            'queued_print_jobs' => (int) $this->pdo->query("SELECT COUNT(*) FROM restaurant_print_jobs WHERE status IN ('queued','retry','pending_manual')")->fetchColumn(),
        ];
    }

    public function moneyToMinor(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        if (preg_match('/^\d+$/', $value) === 1 && strlen($value) > 3) {
            return (int) $value;
        }
        $normalised = preg_replace('/[^\d.]/', '', $value) ?: '0';
        return (int) round(((float) $normalised) * 100);
    }

    public function stationCode(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_\-]+/', '-', $value) ?: '';
        return trim($value, '-_');
    }

    public function minorToMoney(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }

    private function decodePrinter(array $row): array
    {
        $row['station_codes'] = $this->decodeJson((string) ($row['station_codes_json'] ?? '[]'));
        $row['config'] = $this->decodeJson((string) ($row['config_json'] ?? '{}'));
        return $row;
    }

    /** @return array<string,mixed> */
    private function menuItemPayload(array $row, string $locale): array
    {
        $metadata = $this->decodeJson((string) ($row['modifiers_json'] ?? '{}'));
        $metadata = is_array($metadata) ? $metadata : [];
        $category = $this->localizedValue($metadata['category'] ?? null, $locale, (string) ($row['category'] ?? 'Menu'));
        $searchTerms = [(string) ($row['name'] ?? ''), (string) ($row['category'] ?? '')];
        foreach (['zh-CN', 'en-US', 'es-US'] as $searchLocale) {
            foreach (['name', 'description', 'category'] as $field) {
                $searchTerms[] = $this->localizedValue($metadata[$field] ?? null, $searchLocale, '');
            }
            $searchTerms = array_merge($searchTerms, $this->localizedList($metadata['tags'] ?? [], $searchLocale));
        }
        foreach (array_slice(is_array($metadata['search_aliases'] ?? null) ? $metadata['search_aliases'] : [], 0, 30) as $alias) {
            if (is_string($alias)) {
                $searchTerms[] = mb_substr($alias, 0, 100);
            }
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => $this->localizedValue($metadata['name'] ?? null, $locale, (string) ($row['name'] ?? '')),
            'description' => $this->localizedValue($metadata['description'] ?? null, $locale, (string) ($metadata['short_description'] ?? '')),
            'category' => $category,
            'category_slug' => $this->slug($category),
            'search_terms' => array_values(array_unique(array_filter($searchTerms))),
            'menu_section' => in_array((string) ($metadata['menu_section'] ?? ''), ['lunch', 'dinner', 'kids'], true) ? $metadata['menu_section'] : 'all_day',
            'price' => $this->minorToMoney((int) ($row['price_minor'] ?? 0)),
            'price_minor' => (int) ($row['price_minor'] ?? 0),
            'currency' => (string) ($row['currency'] ?? 'CNY'),
            'image_url' => (string) ($metadata['image_url'] ?? ''),
            'gallery' => is_array($metadata['gallery'] ?? null) ? array_values($metadata['gallery']) : [],
            'tags' => $this->localizedList($metadata['tags'] ?? [], $locale),
            'featured' => (bool) ($metadata['featured'] ?? $metadata['house_favorite'] ?? false),
            'featured_order' => (int) ($metadata['featured_order'] ?? 0),
            'station_code' => (string) ($row['station_code'] ?? ''),
            'status' => (string) ($row['status'] ?? 'active'),
            'prep_minutes' => (int) ($row['prep_minutes'] ?? 0),
            'modifiers' => $this->localizedModifierGroups($metadata['modifiers'] ?? [], $locale),
            'ordering' => [
                'available' => (string) ($row['status'] ?? 'active') === 'active',
                'api_ready' => true,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function createCart(array $data = []): array
    {
        $now = $this->now();
        $token = (string) ($data['token'] ?? $this->cartToken());
        $serviceMode = $this->serviceMode((string) ($data['service_mode'] ?? 'pickup'));
        $currency = strtoupper(substr(trim((string) ($data['currency'] ?? 'CNY')), 0, 3)) ?: 'CNY';
        $this->pdo->prepare(
            'INSERT INTO restaurant_carts (token, location_id, service_mode, status, currency, subtotal_minor, fee_minor, total_minor, payload_json, created_at, updated_at)
             VALUES (:token, :location_id, :service_mode, :status, :currency, 0, 0, 0, :payload_json, :created_at, :updated_at)'
        )->execute([
            ':token' => $token,
            ':location_id' => ((int) ($data['location_id'] ?? 0)) > 0 ? (int) $data['location_id'] : null,
            ':service_mode' => $serviceMode,
            ':status' => 'open',
            ':currency' => $currency,
            ':payload_json' => $this->json($data['payload'] ?? []),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return $this->cartByToken($token) ?? [];
    }

    /** @return array<string,mixed>|null */
    public function cartByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM restaurant_carts WHERE token = :token LIMIT 1');
        $stmt->execute([':token' => $token]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        $row['payload'] = $this->decodeJson((string) ($row['payload_json'] ?? '{}'));
        return $row;
    }

    /** @return array<string,mixed>|null */
    public function cartByCommerceOrder(int $orderId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM restaurant_carts WHERE commerce_order_id = :order_id LIMIT 1');
        $stmt->execute([':order_id' => $orderId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        $row['payload'] = $this->decodeJson((string) ($row['payload_json'] ?? '{}'));
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function cartItems(int $cartId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM restaurant_cart_items WHERE cart_id = :cart_id ORDER BY id ASC');
        $stmt->execute([':cart_id' => $cartId]);
        $rows = $stmt->fetchAll();
        return array_map(function (array $row): array {
            $row['modifiers'] = $this->decodeJson((string) ($row['modifiers_json'] ?? '[]'));
            return $row;
        }, $rows);
    }

    /** @param list<array<string,mixed>> $items */
    public function assertCartSellable(array $cart, array $items): void
    {
        foreach ($items as $item) {
            $menu = $this->menuItem((int) ($item['menu_item_id'] ?? 0));
            if ($menu === null || (string) ($menu['status'] ?? '') !== 'active'
                || (string) ($menu['currency'] ?? '') !== (string) ($cart['currency'] ?? '')
                || (int) ($menu['price_minor'] ?? 0) !== (int) ($item['unit_price_minor'] ?? -1)) {
                throw new \RuntimeException('购物车商品已变更，请重新选择。');
            }
            $selections = array_map(static fn (array $modifier): array => [
                'group_id' => (string) ($modifier['group_id'] ?? ''),
                'option_id' => (string) ($modifier['option_id'] ?? ''),
            ], is_array($item['modifiers'] ?? null) ? $item['modifiers'] : []);
            $modifiers = $this->validatedModifiers($menu, $selections);
            $modifierTotal = $this->modifiersTotal($modifiers);
            if ($modifierTotal !== (int) ($item['modifiers_total_minor'] ?? -1)
                || ((int) $menu['price_minor'] + $modifierTotal) * (int) ($item['quantity'] ?? 0) !== (int) ($item['line_total_minor'] ?? -1)) {
                throw new \RuntimeException('购物车价格已变更，请重新选择。');
            }
        }
    }

    /** @return array<string,mixed> */
    public function addCartItem(string $token, int $menuItemId, int $quantity, array $modifiers = [], string $notes = ''): array
    {
        $menu = $this->menuItem($menuItemId);
        if ($menu === null || (string) ($menu['status'] ?? '') !== 'active') {
            throw new \RuntimeException('菜品不可点。');
        }
        $cart = $this->cartByToken($token) ?? $this->createCart(['token' => $token, 'currency' => (string) ($menu['currency'] ?? 'CNY')]);
        if ((string) ($cart['status'] ?? '') !== 'open') {
            throw new \RuntimeException('餐车已经提交，不能继续修改。');
        }
        if ((string) ($cart['currency'] ?? '') !== (string) ($menu['currency'] ?? '')) {
            throw new \RuntimeException('购物车商品币种不一致。');
        }
        $quantity = max(1, min(99, $quantity));
        $unit = (int) ($menu['price_minor'] ?? 0);
        if ($unit <= 0) {
            throw new \RuntimeException('菜品价格无效。');
        }
        $selectedModifiers = $this->validatedModifiers($menu, $modifiers);
        $modifiersTotal = $this->modifiersTotal($selectedModifiers);
        $lineTotal = ($unit + $modifiersTotal) * $quantity;
        $now = $this->now();
        $this->pdo->prepare(
            'INSERT INTO restaurant_cart_items (cart_id, menu_item_id, item_name, category, station_code, quantity, unit_price_minor, modifiers_total_minor, line_total_minor, currency, notes, modifiers_json, created_at, updated_at)
             VALUES (:cart_id, :menu_item_id, :item_name, :category, :station_code, :quantity, :unit_price_minor, :modifiers_total_minor, :line_total_minor, :currency, :notes, :modifiers_json, :created_at, :updated_at)'
        )->execute([
            ':cart_id' => (int) $cart['id'],
            ':menu_item_id' => $menuItemId,
            ':item_name' => (string) $menu['name'],
            ':category' => (string) ($menu['category'] ?? ''),
            ':station_code' => $this->stationCode((string) ($menu['station_code'] ?? 'front')),
            ':quantity' => $quantity,
            ':unit_price_minor' => $unit,
            ':modifiers_total_minor' => $modifiersTotal,
            ':line_total_minor' => $lineTotal,
            ':currency' => (string) ($menu['currency'] ?? $cart['currency'] ?? 'CNY'),
            ':notes' => mb_substr(trim($notes), 0, 500),
            ':modifiers_json' => $this->json($selectedModifiers),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $this->recalculateCart((int) $cart['id']);

        return $this->cartSummary($token);
    }

    public function updateCartCheckout(string $token, array $data): void
    {
        $cart = $this->cartByToken($token);
        if ($cart === null) {
            throw new \RuntimeException('餐车不存在。');
        }
        $status = in_array((string) ($data['status'] ?? 'checkout_started'), ['open', 'checkout_started', 'paid', 'ticketed', 'cancelled'], true) ? (string) $data['status'] : 'checkout_started';
        $this->pdo->prepare(
            'UPDATE restaurant_carts SET service_mode = :service_mode, status = :status, customer_name = :customer_name, customer_phone = :customer_phone, customer_email = :customer_email, delivery_address = :delivery_address, payment_provider_id = :payment_provider_id, commerce_product_id = :commerce_product_id, commerce_action_id = :commerce_action_id, commerce_order_id = :commerce_order_id, idempotency_key = :idempotency_key, completion_claim = :completion_claim, submitted_at = COALESCE(submitted_at, :submitted_at), updated_at = :updated_at WHERE token = :token'
        )->execute([
            ':token' => $token,
            ':service_mode' => $this->serviceMode((string) ($data['service_mode'] ?? $cart['service_mode'] ?? 'pickup')),
            ':status' => $status,
            ':customer_name' => $this->nullableText((string) ($data['customer_name'] ?? $cart['customer_name'] ?? ''), 191),
            ':customer_phone' => $this->nullableText((string) ($data['customer_phone'] ?? $cart['customer_phone'] ?? ''), 64),
            ':customer_email' => $this->nullableText((string) ($data['customer_email'] ?? $cart['customer_email'] ?? ''), 191),
            ':delivery_address' => $this->nullableText((string) ($data['delivery_address'] ?? $cart['delivery_address'] ?? ''), 500),
            ':payment_provider_id' => $this->nullableText((string) ($data['payment_provider_id'] ?? $cart['payment_provider_id'] ?? ''), 96),
            ':commerce_product_id' => ((int) ($data['commerce_product_id'] ?? $cart['commerce_product_id'] ?? 0)) > 0 ? (int) ($data['commerce_product_id'] ?? $cart['commerce_product_id']) : null,
            ':commerce_action_id' => ((int) ($data['commerce_action_id'] ?? $cart['commerce_action_id'] ?? 0)) > 0 ? (int) ($data['commerce_action_id'] ?? $cart['commerce_action_id']) : null,
            ':commerce_order_id' => ((int) ($data['commerce_order_id'] ?? $cart['commerce_order_id'] ?? 0)) > 0 ? (int) ($data['commerce_order_id'] ?? $cart['commerce_order_id']) : null,
            ':idempotency_key' => $this->nullableText((string) ($data['idempotency_key'] ?? $cart['idempotency_key'] ?? ''), 128),
            ':completion_claim' => $this->nullableText((string) ($data['completion_claim'] ?? $cart['completion_claim'] ?? ''), 128),
            ':submitted_at' => $this->now(),
            ':updated_at' => $this->now(),
        ]);
    }

    public function markCartPaid(string $token): void
    {
        $this->pdo->prepare("UPDATE restaurant_carts SET status = CASE WHEN status = 'ticketed' THEN status ELSE 'paid' END, paid_at = COALESCE(paid_at, :paid_at), updated_at = :updated_at WHERE token = :token")
            ->execute([':token' => $token, ':paid_at' => $this->now(), ':updated_at' => $this->now()]);
    }

    public function markCartTicketed(string $token, int $ticketId): void
    {
        $cart = $this->cartByToken($token);
        $payload = is_array($cart['payload'] ?? null) ? $cart['payload'] : [];
        $payload['restaurant_ticket_id'] = $ticketId;
        $this->pdo->prepare("UPDATE restaurant_carts SET status = 'ticketed', payload_json = :payload_json, updated_at = :updated_at WHERE token = :token")
            ->execute([':token' => $token, ':payload_json' => $this->json($payload), ':updated_at' => $this->now()]);
    }

    public function ticketIdForCommerceOrder(int $orderId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM restaurant_order_tickets WHERE commerce_order_id = :order_id ORDER BY id ASC LIMIT 1');
        $stmt->execute([':order_id' => $orderId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    /** @return array<string,mixed> */
    public function cartSummary(string $token, ?string $serviceMode = null): array
    {
        if ($serviceMode !== null && !in_array($serviceMode, ['pickup', 'delivery'], true)) {
            throw new \InvalidArgumentException('自取或配送方式无效。');
        }
        $cart = $this->cartByToken($token);
        $items = $cart === null ? [] : $this->cartItems((int) $cart['id']);
        $pricing = $this->cartPricing($cart ?? ['status' => 'open'], $serviceMode);
        $ticketId = (int) ($cart['payload']['restaurant_ticket_id'] ?? 0);
        $ticket = $ticketId > 0 ? $this->ticket($ticketId) : null;
        return [
            'ok' => true,
            'cart' => $cart ?? ['status' => 'open', 'token' => $token],
            'items' => $items,
            'count' => array_sum(array_map(static fn (array $item): int => (int) $item['quantity'], $items)),
            'currency' => (string) ($cart['currency'] ?? 'CNY'),
            'service_mode' => $pricing['service_mode'],
            'subtotal_minor' => $pricing['subtotal_minor'],
            'fee_minor' => $pricing['fee_minor'],
            'tax_minor' => $pricing['tax_minor'],
            'total_minor' => $pricing['total_minor'],
            'subtotal' => $this->minorToMoney($pricing['subtotal_minor']),
            'fee' => $this->minorToMoney($pricing['fee_minor']),
            'tax_amount' => $this->minorToMoney($pricing['tax_minor']),
            'tax' => $pricing['tax'],
            'total' => $this->minorToMoney($pricing['total_minor']),
            'order_status' => [
                'cart_status' => (string) ($cart['status'] ?? 'open'),
                'ticket_status' => is_array($ticket) ? (string) ($ticket['status'] ?? '') : '',
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function removeCartItem(string $token, int $itemId): array
    {
        $cart = $this->cartByToken($token);
        if ($cart === null || (string) ($cart['status'] ?? '') !== 'open') {
            throw new \RuntimeException('餐车不可修改。');
        }
        $this->pdo->prepare('DELETE FROM restaurant_cart_items WHERE id = :id AND cart_id = :cart_id')->execute([':id' => $itemId, ':cart_id' => (int) $cart['id']]);
        $this->recalculateCart((int) $cart['id']);
        return $this->cartSummary($token);
    }

    public function updateCartItem(string $token, int $itemId, int $quantity, array $modifiers, string $notes): array
    {
        if ($quantity < 1 || $quantity > 99) {
            throw new \InvalidArgumentException('数量必须在 1 至 99 之间。');
        }
        return $this->transaction(function () use ($token, $itemId, $quantity, $modifiers, $notes): array {
            $cart = $this->cartByToken($token);
            if ($cart === null || $cart['status'] !== 'open') {
                throw new \RuntimeException('餐车已经提交，不能继续修改。');
            }
            $statement = $this->pdo->prepare('SELECT menu_item_id FROM restaurant_cart_items WHERE id = :id AND cart_id = :cart_id');
            $statement->execute([':id' => $itemId, ':cart_id' => (int) $cart['id']]);
            $menu = $this->menuItem((int) $statement->fetchColumn());
            if ($menu === null || $menu['status'] !== 'active' || $menu['currency'] !== $cart['currency'] || (int) $menu['price_minor'] <= 0) {
                throw new \RuntimeException('购物车商品不可修改，请移除后重新选择。');
            }
            $selected = $this->validatedModifiers($menu, $modifiers);
            $modifierTotal = $this->modifiersTotal($selected);
            $update = $this->pdo->prepare("UPDATE restaurant_cart_items SET quantity = :quantity, unit_price_minor = :unit, modifiers_total_minor = :modifiers_total, line_total_minor = :total, modifiers_json = :modifiers, notes = :notes, updated_at = :now WHERE id = :id AND cart_id = :cart_id AND EXISTS (SELECT 1 FROM restaurant_carts WHERE id = :open_cart_id AND status = 'open')");
            $update->execute([':quantity' => $quantity, ':unit' => (int) $menu['price_minor'], ':modifiers_total' => $modifierTotal, ':total' => ((int) $menu['price_minor'] + $modifierTotal) * $quantity, ':modifiers' => $this->json($selected), ':notes' => mb_substr(trim($notes), 0, 500), ':now' => $this->now(), ':id' => $itemId, ':cart_id' => (int) $cart['id'], ':open_cart_id' => (int) $cart['id']]);
            $this->recalculateCart((int) $cart['id']);
            return $this->cartSummary($token);
        });
    }

    /** @return list<array<string,mixed>> */
    public function paidCartsNeedingTickets(int $limit = 50, int $afterId = 0): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM restaurant_carts WHERE commerce_order_id IS NOT NULL AND status IN ('checkout_started','paid','ticketed') AND id > :after_id ORDER BY id ASC LIMIT :limit");
        $stmt->bindValue(':after_id', max(0, $afterId), PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private function recalculateCart(int $cartId): void
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(line_total_minor), 0) FROM restaurant_cart_items WHERE cart_id = :cart_id');
        $stmt->execute([':cart_id' => $cartId]);
        $subtotal = (int) $stmt->fetchColumn();
        $cartQuery = $this->pdo->prepare('SELECT * FROM restaurant_carts WHERE id = :id');
        $cartQuery->execute([':id' => $cartId]);
        $cart = $cartQuery->fetch();
        $cart['subtotal_minor'] = $subtotal;
        $pricing = $this->cartPricing($cart);
        $this->pdo->prepare('UPDATE restaurant_carts SET subtotal_minor = :subtotal, fee_minor = :fee, total_minor = :total, updated_at = :updated_at WHERE id = :id')
            ->execute([':id' => $cartId, ':subtotal' => $subtotal, ':fee' => $pricing['fee_minor'], ':total' => $pricing['total_minor'], ':updated_at' => $this->now()]);
    }

    public function withTaxSettings(array $settings, string $rate, string $deliveryTaxable): array
    {
        $rate = trim($rate);
        if ($rate === '') {
            unset($settings['tax_rate_basis_points'], $settings['delivery_taxable']);
            return $settings;
        }
        if (preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $rate) !== 1 || !in_array($deliveryTaxable, ['yes', 'no'], true)) {
            throw new \InvalidArgumentException('税率须为 0–100 的百分比，最多两位小数；请明确选择配送费是否计税。');
        }
        $parts = explode('.', $rate, 2);
        $settings['tax_rate_basis_points'] = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
        $settings['delivery_taxable'] = $deliveryTaxable === 'yes';
        $this->taxSettings($settings);
        return $settings;
    }

    public function taxSettings(?array $settings = null): array
    {
        $settings ??= $this->decodeJson((string) ($this->storefrontSettings()['settings_json'] ?? '{}'));
        if (!array_key_exists('tax_rate_basis_points', $settings)) {
            return ['configured' => false, 'rate_basis_points' => 0, 'rate_percent' => '', 'delivery_taxable' => false];
        }
        $rate = $settings['tax_rate_basis_points'];
        if (!is_int($rate) || $rate < 0 || $rate > 10000 || !is_bool($settings['delivery_taxable'] ?? null)) {
            throw new \InvalidArgumentException('税率或配送费计税设置无效，不能按零税率处理。');
        }
        return ['configured' => true, 'rate_basis_points' => $rate, 'rate_percent' => $this->minorToMoney($rate), 'delivery_taxable' => $settings['delivery_taxable']];
    }

    private function cartPricing(array $cart, ?string $serviceMode = null, ?int $feeMinor = null): array
    {
        $mode = $serviceMode ?? (string) ($cart['service_mode'] ?? 'pickup');
        $subtotal = max(0, (int) ($cart['subtotal_minor'] ?? 0));
        if ((string) ($cart['status'] ?? 'open') !== 'open') {
            $snapshot = $cart['payload']['pricing_snapshot'] ?? null;
            if (is_array($snapshot)) {
                return $snapshot;
            }
            return ['service_mode' => (string) ($cart['service_mode'] ?? 'pickup'), 'subtotal_minor' => $subtotal, 'fee_minor' => (int) ($cart['fee_minor'] ?? 0), 'tax_minor' => 0, 'total_minor' => (int) ($cart['total_minor'] ?? 0), 'tax' => ['configured' => false, 'rate_basis_points' => 0, 'rate_percent' => '', 'delivery_taxable' => false]];
        }
        $settings = $this->decodeJson((string) ($this->storefrontSettings()['settings_json'] ?? '{}'));
        $tax = $this->taxSettings($settings);
        $fee = $subtotal === 0 || $mode !== 'delivery' ? 0 : max(0, $feeMinor ?? (int) ($settings['delivery_fee_minor'] ?? 0));
        $taxable = $subtotal + ($tax['delivery_taxable'] ? $fee : 0);
        $rate = $tax['rate_basis_points'];
        $taxMinor = intdiv($taxable, 10000) * $rate + intdiv(($taxable % 10000) * $rate + 5000, 10000);
        return ['service_mode' => $mode, 'subtotal_minor' => $subtotal, 'fee_minor' => $fee, 'tax_minor' => $taxMinor, 'total_minor' => $subtotal + $fee + $taxMinor, 'tax' => $tax];
    }

    private function modifiersTotal(array $modifiers): int
    {
        $total = 0;
        foreach ($modifiers as $modifier) {
            if (!is_array($modifier)) {
                continue;
            }
            $total += $this->moneyToMinor((string) ($modifier['price'] ?? $modifier['price_minor'] ?? '0'));
        }
        return max(0, $total);
    }

    /** @return list<array<string,mixed>> */
    private function validatedModifiers(array $menu, array $selected): array
    {
        $metadata = $this->decodeJson((string) ($menu['modifiers_json'] ?? '{}'));
        $groups = is_array($metadata) && is_array($metadata['modifiers'] ?? null) ? $metadata['modifiers'] : [];
        $chosen = [];
        foreach ($selected as $selection) {
            if (!is_array($selection)) {
                throw new \InvalidArgumentException('无效商品规格。');
            }
            $groupId = (string) ($selection['group_id'] ?? '');
            $optionId = (string) ($selection['option_id'] ?? '');
            if ($groupId === '' || $optionId === '' || isset($chosen[$groupId][$optionId])) {
                throw new \InvalidArgumentException('无效商品规格。');
            }
            $chosen[$groupId][$optionId] = true;
        }
        $result = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            $groupId = (string) ($group['id'] ?? '');
            $picked = $chosen[$groupId] ?? [];
            $count = count($picked);
            $minimum = max(0, (int) ($group['min'] ?? (($group['required'] ?? false) ? 1 : 0)));
            $maximum = max($minimum, (int) ($group['max'] ?? 1));
            if ($count < $minimum || $count > $maximum) {
                throw new \InvalidArgumentException('商品规格选择不完整。');
            }
            foreach ($group['options'] ?? [] as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $optionId = (string) ($option['id'] ?? '');
                if ($optionId === '' || !isset($picked[$optionId])) {
                    continue;
                }
                $price = (string) ($option['price'] ?? '0');
                if (preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', $price) !== 1) {
                    throw new \InvalidArgumentException('商品规格价格无效。');
                }
                $price = number_format((float) $price, 2, '.', '');
                $result[] = ['group_id' => $groupId, 'option_id' => $optionId, 'name' => $this->localizedValue($option['name'] ?? null, 'zh-CN', $optionId), 'price' => $price];
                unset($chosen[$groupId][$optionId]);
            }
            if (($chosen[$groupId] ?? []) !== []) {
                throw new \InvalidArgumentException('商品规格不存在。');
            }
            unset($chosen[$groupId]);
        }
        if ($chosen !== []) {
            throw new \InvalidArgumentException('商品规格不存在。');
        }
        return $result;
    }

    private function serviceMode(string $mode): string
    {
        return in_array($mode, ['pickup', 'delivery', 'dine_in'], true) ? $mode : 'pickup';
    }

    /** @return array{status:string,today_hours:string} */
    private function openingState(array $settings, string $timezone, ?\DateTimeImmutable $now): array
    {
        $manual = (string) ($settings['status'] ?? 'closed');
        if (in_array($manual, ['paused', 'closed'], true)) {
            return ['status' => $manual, 'today_hours' => ''];
        }
        $schedule = is_array($settings['hours'] ?? null) ? $settings['hours'] : [];
        $days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        if (array_intersect($days, array_keys($schedule)) === []) {
            return ['status' => in_array($manual, ['open', 'closing_soon'], true) ? $manual : 'closed', 'today_hours' => ''];
        }
        try {
            $zone = new \DateTimeZone($timezone);
        } catch (\Throwable) {
            $zone = new \DateTimeZone('UTC');
        }
        $local = ($now ?? new \DateTimeImmutable('now', $zone))->setTimezone($zone);
        $dayIndex = (int) $local->format('N') - 1;
        $minutes = (int) $local->format('H') * 60 + (int) $local->format('i');
        $today = is_array($schedule[$days[$dayIndex]] ?? null) ? $schedule[$days[$dayIndex]] : [];
        $yesterday = is_array($schedule[$days[($dayIndex + 6) % 7]] ?? null) ? $schedule[$days[($dayIndex + 6) % 7]] : [];
        $label = [];
        $remaining = null;
        foreach ($today as $period) {
            if (!is_array($period)) {
                continue;
            }
            $open = $this->clockMinutes((string) ($period['open'] ?? ''));
            $close = $this->clockMinutes((string) ($period['close'] ?? ''));
            if ($open === null || $close === null || $open === $close) {
                continue;
            }
            $label[] = (string) $period['open'] . '–' . (string) $period['close'];
            $end = $close <= $open ? $close + 1440 : $close;
            if ($minutes >= $open && $minutes < $end) {
                $remaining = $end - $minutes;
            }
        }
        foreach ($yesterday as $period) {
            if (!is_array($period)) {
                continue;
            }
            $open = $this->clockMinutes((string) ($period['open'] ?? ''));
            $close = $this->clockMinutes((string) ($period['close'] ?? ''));
            if ($open !== null && $close !== null && $close < $open && $minutes < $close) {
                $remaining = $close - $minutes;
            }
        }
        return ['status' => $remaining === null ? 'closed' : ($remaining <= 30 ? 'closing_soon' : 'open'), 'today_hours' => implode(', ', $label)];
    }

    private function clockMinutes(string $time): ?int
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $matches) !== 1) {
            return null;
        }
        return ((int) $matches[1] * 60) + (int) $matches[2];
    }

    private function nullableText(string $value, int $max): ?string
    {
        $value = trim($value);
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function cartToken(): string
    {
        return 'cart_' . bin2hex(random_bytes(16));
    }

    private function localizedValue(mixed $value, string $locale, string $fallback = ''): string
    {
        if (is_array($value)) {
            $locale = $this->normalizeLocale($locale);
            foreach ([$locale, substr($locale, 0, 2), 'zh-CN', 'en-US', 'es-US', 'default'] as $key) {
                if (isset($value[$key]) && trim((string) $value[$key]) !== '') {
                    return trim((string) $value[$key]);
                }
            }
        }
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        return $fallback;
    }

    /** @return list<string> */
    private function localizedList(mixed $items, string $locale): array
    {
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            $value = $this->localizedValue($item, $locale, '');
            if ($value !== '') {
                $out[] = $value;
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function localizedModifierGroups(mixed $groups, string $locale): array
    {
        if (!is_array($groups)) {
            return [];
        }
        $result = [];
        foreach ($groups as $group) {
            if (!is_array($group) || !is_array($group['options'] ?? null)) {
                continue;
            }
            $options = [];
            foreach ($group['options'] as $option) {
                if (is_array($option) && isset($option['id'])) {
                    $options[] = ['id' => (string) $option['id'], 'name' => $this->localizedValue($option['name'] ?? null, $locale, (string) $option['id']), 'price' => (string) ($option['price'] ?? '0')];
                }
            }
            $result[] = ['id' => (string) ($group['id'] ?? ''), 'name' => $this->localizedValue($group['name'] ?? null, $locale, ''), 'min' => max(0, (int) ($group['min'] ?? (($group['required'] ?? false) ? 1 : 0))), 'max' => max(1, (int) ($group['max'] ?? 1)), 'options' => $options];
        }
        return $result;
    }

    /** @return list<array<string,string>> */
    private function normalizeGallery(mixed $items, string $locale): array
    {
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = trim((string) ($item['url'] ?? $item['image_url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $out[] = [
                'url' => $url,
                'alt' => $this->localizedValue($item['alt'] ?? null, $locale, ''),
            ];
        }
        return $out;
    }

    private function normalizeLocale(string $locale): string
    {
        return in_array($locale, ['zh-CN', 'en-US', 'es-US'], true) ? $locale : 'zh-CN';
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9\x{4e00}-\x{9fff}]+/u', '-', $value) ?: 'menu';
        return trim($slug, '-') ?: 'menu';
    }

    /** @return list<string> */
    private function normaliseCodes(array $codes): array
    {
        $out = [];
        foreach ($codes as $code) {
            $normalised = $this->stationCode((string) $code);
            if ($normalised !== '') {
                $out[$normalised] = $normalised;
            }
        }
        return array_values($out);
    }

    /** @return mixed */
    private function decodeJson(string $json): mixed
    {
        $decoded = json_decode($json, true);
        return $decoded ?? [];
    }

    /** @param mixed $value */
    private function json($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null';
    }

    private function ticketNumber(): string
    {
        return 'R' . gmdate('YmdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
