<?php

declare(strict_types=1);

namespace Daiying\AffiliateHub;

use PDO;

final class AffiliateRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{products:int,active_products:int,offers:int,active_offers:int,clicks:int} */
    public function stats(): array
    {
        return [
            'products' => (int) $this->pdo->query('SELECT COUNT(*) FROM affiliate_products')->fetchColumn(),
            'active_products' => (int) $this->pdo->query("SELECT COUNT(*) FROM affiliate_products WHERE status = 'active'")->fetchColumn(),
            'offers' => (int) $this->pdo->query('SELECT COUNT(*) FROM affiliate_offers')->fetchColumn(),
            'active_offers' => (int) $this->pdo->query("SELECT COUNT(*) FROM affiliate_offers WHERE status = 'active'")->fetchColumn(),
            'clicks' => (int) $this->pdo->query('SELECT COUNT(*) FROM affiliate_clicks')->fetchColumn(),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function products(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM affiliate_products ORDER BY updated_at DESC, id DESC LIMIT :limit');
        $stmt->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function product(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM affiliate_products WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function offer(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM affiliate_offers WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function offersForProduct(int $productId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM affiliate_offers WHERE product_id = :product_id ORDER BY sort_order ASC, id ASC');
        $stmt->execute([':product_id' => $productId]);

        return $stmt->fetchAll();
    }

    /** @param array<string,mixed> $input */
    public function saveManualProduct(array $input): int
    {
        $now = gmdate('c');
        $name = $this->text($input['name'] ?? '', 500);
        $destinationUrl = $this->httpsUrl($input['destination_url'] ?? '');
        $affiliateUrl = $this->httpsUrl($input['affiliate_url'] ?? $destinationUrl);
        if ($name === '' || $destinationUrl === '' || $affiliateUrl === '') {
            throw new \InvalidArgumentException('商品名称、目标 URL 和 Affiliate URL 不能为空。');
        }
        $currency = strtoupper($this->text($input['currency'] ?? '', 3));
        if ($currency !== '' && !preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('币种必须是 ISO 4217 三位代码。');
        }

        $payload = [
            'name' => $name,
            'brand' => $this->text($input['brand'] ?? '', 191),
            'advertiser_name' => $this->text($input['advertiser_name'] ?? '', 191),
            'destination_url' => $destinationUrl,
            'affiliate_url' => $affiliateUrl,
            'price_current' => $this->decimalOrNull($input['price_current'] ?? null),
            'currency' => $currency !== '' ? $currency : null,
            'image_url' => $this->httpsUrl($input['image_url'] ?? ''),
            'disclosure_text' => $this->text($input['disclosure_text'] ?? '本文包含联盟推广链接，成交后站点可能获得佣金。', 500),
        ];
        $identityHash = hash('sha256', strtolower($destinationUrl) . '|' . strtolower($affiliateUrl));
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $externalId = 'manual:' . substr($identityHash, 0, 24);

        $existing = $this->findByProviderExternalId('affiliate.manual', $externalId);
        if ($existing !== null) {
            $productId = (int) $existing['id'];
            $this->pdo->prepare('UPDATE affiliate_products SET name_original = :name, display_title = :display_title, brand = :brand, advertiser_name = :advertiser_name, image_url = :image_url, price_current = :price_current, currency = :currency, destination_url = :destination_url, status = :status, indexable = :indexable, source_payload_hash = :hash, last_synced_at = :now, last_seen_at = :now, updated_at = :now WHERE id = :id')
                ->execute([
                    ':id' => $productId,
                    ':name' => $name,
                    ':display_title' => $name,
                    ':brand' => $payload['brand'] ?: null,
                    ':advertiser_name' => $payload['advertiser_name'] ?: null,
                    ':image_url' => $payload['image_url'] ?: null,
                    ':price_current' => $payload['price_current'],
                    ':currency' => $payload['currency'],
                    ':destination_url' => $destinationUrl,
                    ':status' => $this->status($input['status'] ?? 'draft'),
                    ':indexable' => !empty($input['indexable']) ? 1 : 0,
                    ':hash' => $hash,
                    ':now' => $now,
                ]);
        } else {
            $this->pdo->prepare('INSERT INTO affiliate_products (uuid, provider_id, connection_id, advertiser_name, external_product_id, name_original, display_title, brand, image_url, price_current, currency, destination_url, country, language, status, indexable, source_payload_hash, source_updated_at, last_synced_at, last_seen_at, created_at, updated_at) VALUES (:uuid, :provider_id, NULL, :advertiser_name, :external_product_id, :name, :display_title, :brand, :image_url, :price_current, :currency, :destination_url, :country, :language, :status, :indexable, :hash, :now, :now, :now, :now, :now)')
                ->execute([
                    ':uuid' => $this->uuid(),
                    ':provider_id' => 'affiliate.manual',
                    ':advertiser_name' => $payload['advertiser_name'] ?: null,
                    ':external_product_id' => $externalId,
                    ':name' => $name,
                    ':display_title' => $name,
                    ':brand' => $payload['brand'] ?: null,
                    ':image_url' => $payload['image_url'] ?: null,
                    ':price_current' => $payload['price_current'],
                    ':currency' => $payload['currency'],
                    ':destination_url' => $destinationUrl,
                    ':country' => $this->text($input['country'] ?? '', 8) ?: null,
                    ':language' => $this->text($input['language'] ?? '', 16) ?: null,
                    ':status' => $this->status($input['status'] ?? 'draft'),
                    ':indexable' => !empty($input['indexable']) ? 1 : 0,
                    ':hash' => $hash,
                    ':now' => $now,
                ]);
            $productId = (int) $this->pdo->lastInsertId();
        }

        $this->upsertOffer($productId, $payload, $hash, $now);

        return $productId;
    }

    /** @param list<array<string,mixed>> $items @param array<string,mixed> $defaults @return array{processed:int,created:int,updated:int,failed:int,errors:list<string>} */
    public function importFeedProducts(array $items, array $defaults = []): array
    {
        return $this->importProviderProducts('affiliate.feed', $items, $defaults);
    }

    /** @param list<array<string,mixed>> $items @param array<string,mixed> $defaults @return array{processed:int,created:int,updated:int,failed:int,errors:list<string>} */
    public function importProviderProducts(string $providerId, array $items, array $defaults = []): array
    {
        if (!preg_match('/^affiliate\.[a-z0-9_.-]+$/', $providerId)) {
            throw new \InvalidArgumentException('Affiliate provider id is invalid.');
        }
        $result = ['processed' => 0, 'created' => 0, 'updated' => 0, 'failed' => 0, 'errors' => []];
        foreach ($items as $index => $item) {
            $result['processed']++;
            try {
                $saved = $this->saveProviderProduct($providerId, $item, $defaults);
                $result[$saved['created'] ? 'created' : 'updated']++;
            } catch (\Throwable $exception) {
                $result['failed']++;
                if (count($result['errors']) < 20) {
                    $result['errors'][] = '第 ' . ($index + 1) . ' 项：' . $exception->getMessage();
                }
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $defaults @return array{id:int,created:bool} */
    public function saveFeedProduct(array $input, array $defaults = []): array
    {
        return $this->saveProviderProduct('affiliate.feed', $input, $defaults);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $defaults @return array{id:int,created:bool} */
    public function saveProviderProduct(string $providerId, array $input, array $defaults = []): array
    {
        $now = gmdate('c');
        $name = $this->text($input['name'] ?? '', 500);
        $destinationUrl = $this->httpsUrl($input['destination_url'] ?? '');
        $affiliateUrl = $this->httpsUrl($input['affiliate_url'] ?? $destinationUrl);
        if ($name === '' || $destinationUrl === '' || $affiliateUrl === '') {
            throw new \InvalidArgumentException('联盟商品缺少名称、目标 URL 或 Affiliate URL。');
        }
        $currency = strtoupper($this->text($input['currency'] ?? ($defaults['currency'] ?? ''), 3));
        if ($currency !== '' && !preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('币种必须是 ISO 4217 三位代码。');
        }
        $sourceName = $this->text($defaults['source_name'] ?? $providerId, 96);
        $external = $this->text($input['external_product_id'] ?? '', 191);
        if ($external === '') {
            $external = substr(hash('sha256', strtolower($destinationUrl) . '|' . strtolower($affiliateUrl)), 0, 32);
        }
        $externalId = substr($providerId, strlen('affiliate.')) . ':' . substr(hash('sha256', strtolower($sourceName) . '|' . strtolower($external)), 0, 40);
        $price = $this->decimalOrNull($input['price_current'] ?? null);
        $payload = [
            'source_name' => $sourceName,
            'external_product_id' => $external,
            'advertiser_external_id' => $this->text($input['advertiser_external_id'] ?? '', 191),
            'name' => $name,
            'description' => $this->text($input['description'] ?? '', 5000),
            'brand' => $this->text($input['brand'] ?? '', 191),
            'advertiser_name' => $this->text($input['advertiser_name'] ?? '', 191),
            'category_original' => $this->text($input['category'] ?? '', 191),
            'sku' => $this->text($input['sku'] ?? '', 191),
            'image_url' => $this->httpsUrl($input['image_url'] ?? ''),
            'price_current' => $price,
            'currency' => $currency !== '' ? $currency : null,
            'availability' => $this->text($input['availability'] ?? 'unknown', 64) ?: 'unknown',
            'destination_url' => $destinationUrl,
            'affiliate_url' => $affiliateUrl,
        ];
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $status = $this->status($defaults['status'] ?? 'draft');
        $indexable = !empty($defaults['indexable']) ? 1 : 0;
        $connectionId = isset($defaults['connection_id']) ? max(0, (int) $defaults['connection_id']) : 0;

        $existing = $this->findByProviderExternalId($providerId, $externalId);
        if ($existing !== null) {
            $productId = (int) $existing['id'];
            $this->pdo->prepare('UPDATE affiliate_products SET connection_id = :connection_id, advertiser_external_id = :advertiser_external_id, advertiser_name = :advertiser_name, external_parent_id = :external_parent_id, name_original = :name, description_original = :description, display_title = COALESCE(display_title, :display_title), brand = :brand, sku = :sku, category_original = :category_original, image_url = :image_url, price_current = :price_current, currency = :currency, availability = :availability, destination_url = :destination_url, country = :country, language = :language, status = :status, indexable = :indexable, source_payload_hash = :hash, source_updated_at = :now, last_synced_at = :now, last_seen_at = :now, updated_at = :now WHERE id = :id')
                ->execute([
                    ':id' => $productId,
                    ':connection_id' => $connectionId > 0 ? $connectionId : null,
                    ':advertiser_external_id' => $payload['advertiser_external_id'] ?: null,
                    ':advertiser_name' => $payload['advertiser_name'] ?: null,
                    ':external_parent_id' => $this->text($input['external_parent_id'] ?? '', 191) ?: null,
                    ':name' => $name,
                    ':description' => $payload['description'] ?: null,
                    ':display_title' => $name,
                    ':brand' => $payload['brand'] ?: null,
                    ':sku' => $payload['sku'] ?: null,
                    ':category_original' => $payload['category_original'] ?: null,
                    ':image_url' => $payload['image_url'] ?: null,
                    ':price_current' => $price,
                    ':currency' => $payload['currency'],
                    ':availability' => $payload['availability'],
                    ':destination_url' => $destinationUrl,
                    ':country' => $this->text($input['country'] ?? ($defaults['country'] ?? ''), 8) ?: null,
                    ':language' => $this->text($input['language'] ?? ($defaults['language'] ?? ''), 16) ?: null,
                    ':status' => $status,
                    ':indexable' => $indexable,
                    ':hash' => $hash,
                    ':now' => $now,
                ]);
            $created = false;
        } else {
            $this->pdo->prepare('INSERT INTO affiliate_products (uuid, provider_id, connection_id, advertiser_external_id, advertiser_name, catalog_external_id, external_product_id, external_parent_id, name_original, description_original, display_title, brand, sku, category_original, image_url, price_current, currency, availability, destination_url, country, language, status, indexable, source_payload_hash, source_updated_at, last_synced_at, last_seen_at, created_at, updated_at) VALUES (:uuid, :provider_id, :connection_id, :advertiser_external_id, :advertiser_name, :catalog_external_id, :external_product_id, :external_parent_id, :name, :description, :display_title, :brand, :sku, :category_original, :image_url, :price_current, :currency, :availability, :destination_url, :country, :language, :status, :indexable, :hash, :now, :now, :now, :now, :now)')
                ->execute([
                    ':uuid' => $this->uuid(),
                    ':provider_id' => $providerId,
                    ':connection_id' => $connectionId > 0 ? $connectionId : null,
                    ':advertiser_external_id' => $payload['advertiser_external_id'] ?: null,
                    ':advertiser_name' => $payload['advertiser_name'] ?: null,
                    ':catalog_external_id' => $sourceName,
                    ':external_product_id' => $externalId,
                    ':external_parent_id' => $this->text($input['external_parent_id'] ?? '', 191) ?: null,
                    ':name' => $name,
                    ':description' => $payload['description'] ?: null,
                    ':display_title' => $name,
                    ':brand' => $payload['brand'] ?: null,
                    ':sku' => $payload['sku'] ?: null,
                    ':category_original' => $payload['category_original'] ?: null,
                    ':image_url' => $payload['image_url'] ?: null,
                    ':price_current' => $price,
                    ':currency' => $payload['currency'],
                    ':availability' => $payload['availability'],
                    ':destination_url' => $destinationUrl,
                    ':country' => $this->text($input['country'] ?? ($defaults['country'] ?? ''), 8) ?: null,
                    ':language' => $this->text($input['language'] ?? ($defaults['language'] ?? ''), 16) ?: null,
                    ':status' => $status,
                    ':indexable' => $indexable,
                    ':hash' => $hash,
                    ':now' => $now,
                ]);
            $productId = (int) $this->pdo->lastInsertId();
            $created = true;
        }

        $this->upsertGenericOffer($productId, $providerId, $payload, $hash, $now, $connectionId);

        return ['id' => $productId, 'created' => $created];
    }

    /** @return array<string,mixed>|null */
    private function findByProviderExternalId(string $provider, string $externalId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM affiliate_products WHERE provider_id = :provider AND external_product_id = :external_id LIMIT 1');
        $stmt->execute([':provider' => $provider, ':external_id' => $externalId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $payload */
    private function upsertOffer(int $productId, array $payload, string $hash, string $now): void
    {
        $existing = $this->offersForProduct($productId)[0] ?? null;
        $params = [
            ':product_id' => $productId,
            ':provider_id' => 'affiliate.manual',
            ':advertiser_name' => $payload['advertiser_name'] ?: null,
            ':affiliate_url' => $payload['affiliate_url'],
            ':destination_url' => $payload['destination_url'],
            ':price_current' => $payload['price_current'],
            ':currency' => $payload['currency'],
            ':availability' => 'unknown',
            ':disclosure_text' => $payload['disclosure_text'],
            ':hash' => $hash,
            ':now' => $now,
        ];
        if (is_array($existing)) {
            $updateParams = $params;
            unset($updateParams[':product_id'], $updateParams[':provider_id']);
            $updateParams[':id'] = (int) $existing['id'];
            $this->pdo->prepare('UPDATE affiliate_offers SET advertiser_name = :advertiser_name, affiliate_url = :affiliate_url, destination_url = :destination_url, price_current = :price_current, currency = :currency, availability = :availability, disclosure_text = :disclosure_text, source_payload_hash = :hash, last_synced_at = :now, updated_at = :now WHERE id = :id')
                ->execute($updateParams);
            return;
        }

        $params[':uuid'] = $this->uuid();
        $this->pdo->prepare('INSERT INTO affiliate_offers (uuid, product_id, provider_id, connection_id, advertiser_name, affiliate_url, destination_url, price_current, currency, availability, disclosure_text, status, sort_order, source_payload_hash, last_synced_at, created_at, updated_at) VALUES (:uuid, :product_id, :provider_id, NULL, :advertiser_name, :affiliate_url, :destination_url, :price_current, :currency, :availability, :disclosure_text, \'active\', 0, :hash, :now, :now, :now)')
            ->execute($params);
    }

    /** @param array<string,mixed> $payload */
    private function upsertGenericOffer(int $productId, string $providerId, array $payload, string $hash, string $now, int $connectionId = 0): void
    {
        $stmt = $this->pdo->prepare('SELECT * FROM affiliate_offers WHERE product_id = :product_id AND provider_id = :provider_id ORDER BY id ASC LIMIT 1');
        $stmt->execute([':product_id' => $productId, ':provider_id' => $providerId]);
        $existing = $stmt->fetch();
        $params = [
            ':product_id' => $productId,
            ':provider_id' => $providerId,
            ':connection_id' => $connectionId > 0 ? $connectionId : null,
            ':advertiser_external_id' => $payload['advertiser_external_id'] ?: null,
            ':advertiser_name' => $payload['advertiser_name'] ?: null,
            ':affiliate_url' => $payload['affiliate_url'],
            ':destination_url' => $payload['destination_url'],
            ':price_current' => $payload['price_current'],
            ':currency' => $payload['currency'],
            ':availability' => $payload['availability'] ?: 'unknown',
            ':hash' => $hash,
            ':now' => $now,
        ];
        if (is_array($existing)) {
            $updateParams = $params;
            unset($updateParams[':product_id'], $updateParams[':provider_id']);
            $updateParams[':id'] = (int) $existing['id'];
            $this->pdo->prepare('UPDATE affiliate_offers SET connection_id = :connection_id, advertiser_external_id = :advertiser_external_id, advertiser_name = :advertiser_name, affiliate_url = :affiliate_url, destination_url = :destination_url, price_current = :price_current, currency = :currency, availability = :availability, source_payload_hash = :hash, last_synced_at = :now, updated_at = :now WHERE id = :id')
                ->execute($updateParams);
            return;
        }

        $params[':uuid'] = $this->uuid();
        $this->pdo->prepare('INSERT INTO affiliate_offers (uuid, product_id, provider_id, connection_id, advertiser_external_id, advertiser_name, affiliate_url, destination_url, price_current, currency, availability, status, sort_order, source_payload_hash, last_synced_at, created_at, updated_at) VALUES (:uuid, :product_id, :provider_id, :connection_id, :advertiser_external_id, :advertiser_name, :affiliate_url, :destination_url, :price_current, :currency, :availability, \'active\', 0, :hash, :now, :now, :now)')
            ->execute($params);
    }

    /** @param array<string,mixed> $server */
    public function recordClick(array $offer, array $server, string $path = ''): string
    {
        $clickRef = bin2hex(random_bytes(16));
        $ip = (string) ($server['REMOTE_ADDR'] ?? '');
        $ua = (string) ($server['HTTP_USER_AGENT'] ?? '');
        $this->pdo->prepare('INSERT INTO affiliate_clicks (offer_id, product_id, provider_id, click_ref, path, referrer, ip_hash, user_agent_hash, created_at) VALUES (:offer_id, :product_id, :provider_id, :click_ref, :path, :referrer, :ip_hash, :ua_hash, :created_at)')
            ->execute([
                ':offer_id' => (int) $offer['id'],
                ':product_id' => (int) $offer['product_id'],
                ':provider_id' => (string) $offer['provider_id'],
                ':click_ref' => $clickRef,
                ':path' => substr($path, 0, 500),
                ':referrer' => substr((string) ($server['HTTP_REFERER'] ?? ''), 0, 1024) ?: null,
                ':ip_hash' => $ip !== '' ? hash('sha256', $ip . '|' . gmdate('Y-m-d')) : null,
                ':ua_hash' => $ua !== '' ? hash('sha256', $ua) : null,
                ':created_at' => gmdate('c'),
            ]);

        return $clickRef;
    }

    private function status(mixed $value): string
    {
        $status = (string) $value;
        return in_array($status, ['draft', 'active', 'inactive', 'missing_pending'], true) ? $status : 'draft';
    }

    private function decimalOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^\d+(?:\.\d{1,4})?$/', $value)) {
            throw new \InvalidArgumentException('金额必须是普通小数，例如 29.99。');
        }

        return $value;
    }

    private function httpsUrl(mixed $value): string
    {
        $url = trim((string) $value);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            throw new \InvalidArgumentException('联盟 URL 必须是 HTTPS。');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('联盟 URL 不能包含用户名或密码。');
        }

        return $url;
    }

    private function text(mixed $value, int $max): string
    {
        return mb_substr(trim((string) $value), 0, $max);
    }

    private function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
