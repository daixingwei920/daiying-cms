<?php

declare(strict_types=1);

namespace Official\VideoCollector;

final class SecurityException extends \RuntimeException
{
}

final class SafeHttpClient
{
    public function __construct(
        private readonly int $timeoutSeconds = 10,
        private readonly int $maxBytes = 4194304,
        private readonly int $maxRedirects = 3
    ) {
    }

    public function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = $this->normalizeHost((string) ($parts['host'] ?? ''));
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new SecurityException('Userinfo in URLs is blocked.');
        }
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new SecurityException('Blocked URL scheme or localhost host.');
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : gethostbynamel($host);
        if ($ips === false || $ips === []) {
            throw new SecurityException('Host resolution failed.');
        }
        foreach ($ips as $ip) {
            if (in_array($ip, ['169.254.169.254', '100.100.100.200'], true) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new SecurityException('Private, reserved, link-local, and metadata endpoints are blocked.');
            }
        }
    }

    public function isSafePublicUrl(string $url): bool
    {
        try {
            $this->assertSafeUrl($url);
            return true;
        } catch (SecurityException) {
            return false;
        }
    }

    public function get(string $url): array
    {
        $current = $url;
        for ($i = 0; $i <= $this->maxRedirects; $i++) {
            $this->assertSafeUrl($current);
            $ctx = stream_context_create(['http' => ['timeout' => $this->timeoutSeconds, 'ignore_errors' => true, 'follow_location' => 0, 'header' => "User-Agent: DaiyingVideoCollectorLocalDev/0.1\r\n"], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
            $fh = @fopen($current, 'rb', false, $ctx);
            if (!is_resource($fh)) {
                throw new \RuntimeException('HTTP request failed.');
            }
            $body = stream_get_contents($fh, $this->maxBytes + 1);
            fclose($fh);
            if (strlen((string) $body) > $this->maxBytes) {
                throw new SecurityException('Response size limit exceeded.');
            }
            $headers = $http_response_header ?? [];
            $status = $this->status($headers);
            $location = $this->location($headers);
            if ($status >= 300 && $status < 400 && $location !== null) {
                if ($i === $this->maxRedirects) {
                    throw new SecurityException('Redirect limit exceeded.');
                }
                $current = $this->resolve($current, $location);
                continue;
            }
            return ['url' => $current, 'status' => $status, 'headers' => $headers, 'body' => (string) $body];
        }
        throw new SecurityException('Redirect limit exceeded.');
    }

    private function status(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', (string) $header, $m)) {
                return (int) $m[1];
            }
        }
        return 0;
    }

    private function location(array $headers): ?string
    {
        foreach ($headers as $header) {
            if (stripos((string) $header, 'Location:') === 0) {
                return trim(substr((string) $header, 9));
            }
        }
        return null;
    }

    private function resolve(string $base, string $location): string
    {
        if (parse_url($location, PHP_URL_SCHEME)) {
            return $location;
        }
        $parts = parse_url($base);
        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (str_starts_with($location, '/') ? $location : rtrim(dirname((string) ($parts['path'] ?? '/')), '/') . '/' . $location);
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower(rtrim(trim($host), '.'));
        if ($host !== '' && function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $host) === 1) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                return strtolower(rtrim($ascii, '.'));
            }
        }
        return $host;
    }
}

final class SecretVault
{
    public function mask(array $config): array
    {
        $masked = $config;
        foreach ($masked as $key => $value) {
            if (preg_match('/token|key|secret|password/i', (string) $key) && $value !== '') {
                $masked[$key] = '[configured]';
            }
        }
        return $masked;
    }

    public function redactLog(string $message): string
    {
        return preg_replace('/(token|key|secret|password)=([^&\s]+)/i', '$1=[redacted]', $message) ?? $message;
    }
}

final class CategoryMapper
{
    /** @return array{cms_type:string,cms_category:string} */
    public function map(string $sourceName, string $fallbackType = ''): array
    {
        $source = mb_strtolower($sourceName . ' ' . $fallbackType, 'UTF-8');
        $type = match (true) {
            str_contains($source, '短剧') || str_contains($source, '微短') => 'short_drama',
            str_contains($source, '动漫') || str_contains($source, '动画') || str_contains($source, 'anime') => 'anime',
            str_contains($source, '综艺') || str_contains($source, 'variety') => 'variety',
            str_contains($source, '纪录') || str_contains($source, 'documentary') => 'documentary',
            str_contains($source, '电视剧') || str_contains($source, '连续剧') || str_contains($source, '剧集') || preg_match('/(^|\s)tv($|\s)/i', $source) === 1 => 'tv',
            str_contains($source, '电影') || str_contains($source, 'movie') || str_contains($source, 'film') => 'movie',
            default => $fallbackType !== '' ? $this->normalizeFallback($fallbackType) : 'uncategorized',
        };

        return ['cms_type' => $type, 'cms_category' => $this->label($type)];
    }

    public function label(string $type): string
    {
        return match ($type) {
            'movie' => '电影',
            'tv' => '电视剧',
            'short_drama' => '短剧',
            'anime' => '动漫',
            'variety' => '综艺',
            'documentary' => '纪录片',
            default => '未分类',
        };
    }

    private function normalizeFallback(string $fallback): string
    {
        return in_array($fallback, ['movie', 'tv', 'short_drama', 'anime', 'variety', 'documentary'], true) ? $fallback : 'uncategorized';
    }
}

final class ResourceProviderParser
{
    private const TYPES = ['maccms_json', 'maccms_xml', 'm3u8_json', 'm3u8_xml', 'custom_json', 'custom_xml', 'authorized_api'];

    public function normalizeProvider(array $provider): array
    {
        $type = (string) ($provider['provider_type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported resource provider type.');
        }
        return $provider + ['enabled' => true, 'priority' => 100, 'request_interval' => 3, 'timeout' => 10, 'retry_count' => 2];
    }

    public function parsePayload(string $type, string $payload): array
    {
        if (str_contains($type, 'json')) {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $items = $decoded['list'] ?? $decoded['data'] ?? $decoded;
        } else {
            $xml = simplexml_load_string($payload, 'SimpleXMLElement', LIBXML_NOCDATA);
            if (!$xml) {
                throw new \RuntimeException('Invalid XML provider payload.');
            }
            $items = json_decode(json_encode($xml), true);
            $items = $items['list']['video'] ?? $items['list']['vod'] ?? $items['video'] ?? $items['vod'] ?? $items;
        }
        return array_map(fn ($item): array => $this->normalizeVideo((array) $item), is_array($items) && array_is_list($items) ? $items : [$items]);
    }

    public function parseCategories(string $type, string $payload): array
    {
        $categories = [];
        if (str_contains($type, 'json')) {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $raw = $decoded['class'] ?? $decoded['categories'] ?? [];
        } else {
            $xml = simplexml_load_string($payload, 'SimpleXMLElement', LIBXML_NOCDATA);
            if (!$xml) {
                return [];
            }
            $decoded = json_decode(json_encode($xml), true);
            $raw = $decoded['class']['ty'] ?? $decoded['class'] ?? $decoded['categories'] ?? [];
        }
        foreach (is_array($raw) && array_is_list($raw) ? $raw : [$raw] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['type_id'] ?? $row['id'] ?? $row['@attributes']['id'] ?? $row['@attributes']['type_id'] ?? '');
            $name = trim((string) ($row['type_name'] ?? $row['name'] ?? $row['#text'] ?? ''));
            if ($name !== '') {
                $categories[] = ['id' => $id !== '' ? $id : hash('crc32b', $name), 'name' => $name];
            }
        }
        return $categories;
    }

    public function normalizeVideo(array $raw): array
    {
        $title = (string) ($raw['vod_name'] ?? $raw['title'] ?? '');
        $typeName = (string) ($raw['type_name'] ?? $raw['category'] ?? '');
        return [
            'external_id' => (string) ($raw['vod_id'] ?? $raw['id'] ?? ''),
            'source_category_id' => (string) ($raw['type_id'] ?? $raw['category_id'] ?? ''),
            'source_category_name' => $typeName,
            'type' => $this->mapType($typeName, (string) ($raw['type'] ?? '')),
            'title' => $title,
            'original_title' => (string) ($raw['vod_en'] ?? $raw['original_title'] ?? ''),
            'description' => strip_tags((string) ($raw['vod_content'] ?? $raw['description'] ?? '')),
            'poster' => (string) ($raw['vod_pic'] ?? $raw['poster'] ?? ''),
            'year' => (int) ($raw['vod_year'] ?? $raw['year'] ?? 0),
            'region' => (string) ($raw['vod_area'] ?? $raw['region'] ?? ''),
            'language' => (string) ($raw['vod_lang'] ?? $raw['language'] ?? ''),
            'status' => str_contains((string) ($raw['vod_remarks'] ?? ''), '完结') ? 'completed' : 'serializing',
            'actors' => array_filter(array_map('trim', explode(',', (string) ($raw['vod_actor'] ?? '')))),
            'directors' => array_filter(array_map('trim', explode(',', (string) ($raw['vod_director'] ?? '')))),
            'play_groups' => $this->parsePlayGroups((string) ($raw['vod_play_from'] ?? ''), (string) ($raw['vod_play_url'] ?? '')),
        ];
    }

    private function parsePlayGroups(string $from, string $url): array
    {
        $sources = $from !== '' ? explode('$$$', $from) : ['default'];
        $groups = explode('$$$', $url);
        $out = [];
        foreach ($groups as $idx => $group) {
            $code = $sources[$idx] ?? ('source' . ($idx + 1));
            $episodes = [];
            foreach (array_filter(explode('#', $group)) as $row) {
                [$title, $playUrl] = array_pad(explode('$', $row, 2), 2, '');
                $episodes[] = ['title' => $title !== '' ? $title : '第' . (count($episodes) + 1) . '集', 'url' => $playUrl, 'url_type' => $this->urlType($playUrl), 'episode_number' => count($episodes) + 1];
            }
            $out[] = ['play_source_code' => $code, 'episodes' => $episodes];
        }
        return $out;
    }

    public function urlType(string $url): string
    {
        if (preg_match('/\.m3u8(?:\?|$)/i', $url)) {
            return 'hls';
        }
        if (preg_match('/\.mp4(?:\?|$)/i', $url)) {
            return 'mp4';
        }
        return 'embed';
    }

    public function mapType(string $typeName, string $fallback): string
    {
        $source = $typeName . ' ' . $fallback;
        return match (true) {
            str_contains($source, '短剧') => 'short_drama',
            str_contains($source, '动漫') || str_contains($source, '动画') => 'anime',
            str_contains($source, '综艺') => 'variety',
            str_contains($source, '纪录') => 'documentary',
            str_contains($source, '剧') => 'tv',
            trim($source) === '' => 'uncategorized',
            default => 'movie',
        };
    }
}

final class ProviderDetector
{
    public function __construct(
        private readonly ResourceProviderParser $parser,
        private readonly CategoryMapper $categories,
        private readonly ?SafeHttpClient $http = null
    ) {
    }

    /** @return array<string,mixed> */
    public function detect(string $apiUrl, string $payload): array
    {
        $type = $this->detectType($payload);
        $items = $this->parser->parsePayload($type, $payload);
        $sourceCategories = $this->parser->parseCategories($type, $payload);
        $typeSummary = [];
        $playSources = [];
        $episodeCount = 0;
        $safeSamples = 0;
        foreach ($items as $item) {
            $mapped = $this->categories->map((string) ($item['source_category_name'] ?? ''), (string) ($item['type'] ?? ''));
            $typeSummary[$mapped['cms_type']] = ($typeSummary[$mapped['cms_type']] ?? 0) + 1;
            foreach (($item['play_groups'] ?? []) as $group) {
                $code = (string) ($group['play_source_code'] ?? 'default');
                $playSources[$code] = ($playSources[$code] ?? 0) + count($group['episodes'] ?? []);
                foreach (($group['episodes'] ?? []) as $episode) {
                    $episodeCount++;
                    if ($safeSamples < 5 && is_string($episode['url'] ?? null) && $episode['url'] !== '' && ($this->http?->isSafePublicUrl($episode['url']) ?? true)) {
                        $safeSamples++;
                    }
                }
            }
        }
        arsort($typeSummary);
        ksort($playSources);
        $host = parse_url($apiUrl, PHP_URL_HOST);
        $name = is_string($host) && $host !== '' ? $host : '视频资源站';

        return [
            'provider_type' => $type,
            'name' => $name,
            'resource_count' => count($items),
            'episode_count' => $episodeCount,
            'type_summary' => $typeSummary,
            'play_sources' => $playSources,
            'categories' => $sourceCategories,
            'health_status' => count($items) > 0 ? ($episodeCount > 0 ? 'healthy' : 'degraded') : 'failed',
            'safe_sample_count' => $safeSamples,
            'items' => $items,
        ];
    }

    public function detectType(string $payload): string
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $probe = $decoded['list'][0] ?? $decoded['data'][0] ?? $decoded[0] ?? $decoded;
            return is_array($probe) && (isset($probe['vod_id']) || isset($probe['vod_name']) || isset($decoded['class'])) ? 'maccms_json' : 'custom_json';
        } catch (\JsonException) {
        }

        $xml = @simplexml_load_string($payload, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($xml instanceof \SimpleXMLElement) {
            $decoded = json_decode(json_encode($xml), true);
            $probe = $decoded['list']['video'] ?? $decoded['list']['vod'] ?? $decoded['video'] ?? $decoded['vod'] ?? [];
            return is_array($probe) && (isset($probe['vod_id']) || isset($probe['vod_name']) || isset($decoded['class'])) ? 'maccms_xml' : 'custom_xml';
        }

        throw new \RuntimeException('Provider response is not recognized as JSON or XML.');
    }
}

final class VideoMerger
{
    public function matchConfidence(array $incoming, array $existing): float
    {
        $score = 0.0;
        if (($incoming['external_id'] ?? '') !== '' && ($incoming['external_id'] ?? '') === ($existing['external_id'] ?? '')) {
            $score += 0.55;
        }
        if ($this->norm((string) ($incoming['title'] ?? '')) === $this->norm((string) ($existing['title'] ?? ''))) {
            $score += 0.25;
        }
        if ((int) ($incoming['year'] ?? 0) > 0 && (int) ($incoming['year'] ?? 0) === (int) ($existing['year'] ?? 0)) {
            $score += 0.15;
        }
        if (($incoming['original_title'] ?? '') !== '' && $this->norm((string) $incoming['original_title']) === $this->norm((string) ($existing['original_title'] ?? ''))) {
            $score += 0.15;
        }
        return min(1.0, round($score, 3));
    }

    public function canAutoMerge(float $confidence): bool
    {
        return $confidence >= 0.80;
    }

    private function norm(string $value): string
    {
        return strtolower(preg_replace('/[\s\pP]+/u', '', $value) ?? '');
    }
}

final class HlsChecker
{
    public function __construct(private readonly SafeHttpClient $http)
    {
    }

    public function inspect(string $url): array
    {
        $start = microtime(true);
        $response = $this->http->get($url);
        $elapsedMs = (int) round((microtime(true) - $start) * 1000);
        $body = $response['body'];
        if (!str_starts_with(trim($body), '#EXTM3U')) {
            return ['health_status' => 'failed', 'response_time' => $elapsedMs, 'reason' => 'not_m3u8'];
        }
        $isMaster = str_contains($body, '#EXT-X-STREAM-INF');
        return [
            'health_status' => $response['status'] >= 200 && $response['status'] < 400 ? ($elapsedMs > 3000 ? 'degraded' : 'healthy') : 'failed',
            'response_time' => $elapsedMs,
            'playlist_type' => $isMaster ? 'master' : 'media',
            'has_multibitrate' => $isMaster,
            'has_subtitles' => str_contains($body, 'TYPE=SUBTITLES'),
            'has_audio_tracks' => str_contains($body, 'TYPE=AUDIO'),
        ];
    }
}

final class VideoRepository
{
    private readonly CategoryMapper $categories;

    public function __construct(private readonly \PDO $pdo, private readonly ?SafeHttpClient $http = null, ?CategoryMapper $categories = null)
    {
        $this->categories = $categories ?? new CategoryMapper();
    }

    /** @return list<array<string,mixed>> */
    public function listProviders(): array
    {
        return $this->pdo->query('SELECT * FROM video_resource_providers ORDER BY enabled DESC, priority ASC, id DESC')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null */
    public function provider(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM video_resource_providers WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function saveProvider(array $provider): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $id = (int) ($provider['id'] ?? 0);
        $name = trim((string) ($provider['name'] ?? ''));
        $apiUrl = trim((string) ($provider['api_url'] ?? ''));
        $baseUrl = trim((string) ($provider['base_url'] ?? ''));
        if ($name === '') {
            $host = parse_url($apiUrl, PHP_URL_HOST);
            $name = is_string($host) && $host !== '' ? $host : '视频资源站';
        }
        if ($baseUrl === '') {
            $baseUrl = $this->baseUrl($apiUrl);
        }
        $row = [
            'name' => $name,
            'slug' => $this->slug($name),
            'provider_type' => (string) ($provider['provider_type'] ?? 'maccms_json'),
            'base_url' => $baseUrl,
            'api_url' => $apiUrl,
            'enabled' => (int) ($provider['enabled'] ?? 1),
            'auto_sync_enabled' => (int) ($provider['auto_sync_enabled'] ?? 0),
            'priority' => (int) ($provider['priority'] ?? 100),
            'request_interval' => (int) ($provider['request_interval'] ?? 3),
            'timeout' => (int) ($provider['timeout'] ?? 10),
            'retry_count' => (int) ($provider['retry_count'] ?? 2),
            'config_encrypted' => $provider['config_encrypted'] ?? null,
        ];
        if ($id > 0) {
            $stmt = $this->pdo->prepare('UPDATE video_resource_providers SET name = :name, slug = :slug, provider_type = :provider_type, base_url = :base_url, api_url = :api_url, enabled = :enabled, auto_sync_enabled = :auto_sync_enabled, priority = :priority, request_interval = :request_interval, timeout = :timeout, retry_count = :retry_count, config_encrypted = :config_encrypted WHERE id = :id');
            $stmt->execute($row + ['id' => $id]);
            return $id;
        }

        $stmt = $this->pdo->prepare('INSERT INTO video_resource_providers (name,slug,provider_type,base_url,api_url,enabled,auto_sync_enabled,priority,request_interval,timeout,retry_count,health_status,config_encrypted,detected_at) VALUES (:name,:slug,:provider_type,:base_url,:api_url,:enabled,:auto_sync_enabled,:priority,:request_interval,:timeout,:retry_count,:health_status,:config_encrypted,:detected_at)');
        $stmt->execute($row + ['health_status' => 'unknown', 'detected_at' => $now]);
        return (int) $this->pdo->lastInsertId();
    }

    public function deleteProvider(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM video_resource_providers WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function recordProviderDetection(int $providerId, array $detection): void
    {
        $stmt = $this->pdo->prepare('UPDATE video_resource_providers SET provider_type = ?, resource_count = ?, type_summary_json = ?, category_summary_json = ?, health_status = ?, detected_at = ?, last_error = NULL WHERE id = ?');
        $stmt->execute([
            (string) ($detection['provider_type'] ?? 'maccms_json'),
            (int) ($detection['resource_count'] ?? 0),
            json_encode($detection['type_summary'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($detection['categories'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            (string) ($detection['health_status'] ?? 'unknown'),
            gmdate('Y-m-d H:i:s'),
            $providerId,
        ]);
        foreach (($detection['categories'] ?? []) as $category) {
            if (!is_array($category)) {
                continue;
            }
            $mapped = $this->categories->map((string) ($category['name'] ?? ''));
            $this->upsertCategoryMapping($providerId, (string) ($category['id'] ?? ''), (string) ($category['name'] ?? ''), $mapped['cms_type'], $mapped['cms_category']);
        }
    }

    public function ensurePlaySource(string $code, string $protocol): int
    {
        $code = $this->safeCode($code);
        $stmt = $this->pdo->prepare('SELECT id FROM video_play_sources WHERE code = ?');
        $stmt->execute([$code]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            $this->pdo->prepare('UPDATE video_play_sources SET protocol = ? WHERE id = ? AND protocol <> ?')->execute([$protocol, (int) $id, $protocol]);
            return (int) $id;
        }
        $display = match ($code) {
            'dbm3u8' => '高清线路1',
            'ffm3u8' => '高清线路2',
            'wolong' => '备用线路',
            'selfcdn' => '官方线路',
            default => '播放线路' . substr(hash('crc32b', $code), 0, 3),
        };
        $this->pdo->prepare('INSERT INTO video_play_sources (code,display_name,protocol,priority,enabled,is_default,allow_frontend_switch,health_check_enabled,health_check_interval) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$code, $display, $protocol, 100, 1, 0, 1, 1, 3600]);
        return (int) $this->pdo->lastInsertId();
    }

    public function saveVideoGraph(int $providerId, array $video): int
    {
        $result = $this->importVideoGraph($providerId, $video);
        return (int) ($result['video_id'] ?? 0);
    }

    /** @return array<string,mixed> */
    public function importVideoGraph(int $providerId, array $video): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $title = trim((string) ($video['title'] ?? ''));
        if ($title === '') {
            return ['status' => 'skipped', 'reason' => 'missing_title'];
        }
        $groups = $this->filterPlayGroups(is_array($video['play_groups'] ?? null) ? $video['play_groups'] : []);
        if ($groups === []) {
            return ['status' => 'skipped', 'reason' => 'missing_safe_play_url'];
        }

        $externalId = trim((string) ($video['external_id'] ?? ''));
        $year = (int) ($video['year'] ?? 0);
        $normalizedTitle = $this->norm($title);
        $sourceHash = hash('sha256', $providerId . '|' . $externalId . '|' . $title);
        $videoId = $this->findVideoId($providerId, $externalId, $normalizedTitle, $year);
        $mapped = $this->categories->map((string) ($video['source_category_name'] ?? ''), (string) ($video['type'] ?? ''));
        $slug = $this->uniqueSlug($this->slug($title . '-' . ($year ?: ($externalId !== '' ? substr($externalId, 0, 8) : substr($sourceHash, 0, 8)))), $videoId !== false ? (int) $videoId : null);
        if ($videoId === false) {
            $this->pdo->prepare('INSERT INTO videos (uuid,type,title,original_title,slug,description,year,region,language,status,season_count,episode_count,external_ids,source_provider_id,source_external_id,source_url_hash,normalized_title,category_name,visibility,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$this->uuid(), $mapped['cms_type'], $title, (string) ($video['original_title'] ?? ''), $slug, (string) ($video['description'] ?? ''), $year ?: null, (string) ($video['region'] ?? ''), (string) ($video['language'] ?? ''), (string) ($video['status'] ?? 'serializing'), 1, 0, json_encode(['provider:' . $providerId => $externalId], JSON_UNESCAPED_SLASHES), $providerId, $externalId !== '' ? $externalId : null, $sourceHash, $normalizedTitle, $mapped['cms_category'], 'public', $now, $now]);
            $videoId = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO video_seasons (video_id,season_number,title,episode_count,sort_order) VALUES (?,?,?,?,?)')->execute([$videoId, 1, 'Season 1', 0, 1]);
        } else {
            $videoId = (int) $videoId;
            $this->pdo->prepare('UPDATE videos SET type = ?, title = ?, original_title = ?, description = ?, year = ?, region = ?, language = ?, status = ?, source_provider_id = ?, source_external_id = ?, source_url_hash = ?, normalized_title = ?, category_name = ?, updated_at = ? WHERE id = ?')
                ->execute([$mapped['cms_type'], $title, (string) ($video['original_title'] ?? ''), (string) ($video['description'] ?? ''), $year ?: null, (string) ($video['region'] ?? ''), (string) ($video['language'] ?? ''), (string) ($video['status'] ?? 'serializing'), $providerId, $externalId !== '' ? $externalId : null, $sourceHash, $normalizedTitle, $mapped['cms_category'], $now, $videoId]);
        }
        $seasonId = $this->ensureSeason($videoId);
        $insertedUrls = 0;
        $updatedUrls = 0;
        foreach ($groups as $group) {
            foreach ($group['episodes'] as $episode) {
                $episodeId = $this->ensureEpisode($videoId, $seasonId, $episode);
                $sourceId = $this->ensurePlaySource($group['play_source_code'], $episode['url_type']);
                $hash = hash('sha256', $group['play_source_code'] . '|' . $episode['url']);
                $sourceEpisodeId = (string) $episode['episode_number'];
                $exists = $this->pdo->prepare('SELECT id, content_hash FROM video_episode_play_urls WHERE episode_id = ? AND play_source_id = ? AND source_episode_id = ? ORDER BY id ASC LIMIT 1');
                $exists->execute([$episodeId, $sourceId, $sourceEpisodeId]);
                $row = $exists->fetch(\PDO::FETCH_ASSOC);
                if (!is_array($row)) {
                    $this->pdo->prepare('INSERT INTO video_episode_play_urls (video_id,episode_id,resource_provider_id,play_source_id,source_episode_id,url,url_type,priority,enabled,content_hash,health_status) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$videoId, $episodeId, $providerId, $sourceId, $sourceEpisodeId, $episode['url'], $episode['url_type'], 100, 1, $hash, 'unknown']);
                    $insertedUrls++;
                } elseif ((string) ($row['content_hash'] ?? '') !== $hash) {
                    $this->pdo->prepare("UPDATE video_episode_play_urls SET url = ?, url_type = ?, content_hash = ?, health_status = 'unknown', last_checked_at = NULL WHERE id = ?")
                        ->execute([$episode['url'], $episode['url_type'], $hash, (int) $row['id']]);
                    $updatedUrls++;
                }
            }
        }
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM video_episodes WHERE video_id = ?');
        $countStmt->execute([$videoId]);
        $count = (int) $countStmt->fetchColumn();
        $latestStmt = $this->pdo->prepare('SELECT id FROM video_episodes WHERE video_id = ? ORDER BY sort_order DESC LIMIT 1');
        $latestStmt->execute([$videoId]);
        $latest = (int) $latestStmt->fetchColumn();
        $this->pdo->prepare('UPDATE videos SET episode_count = ?, latest_episode_id = ?, latest_episode_at = ?, updated_at = ? WHERE id = ?')->execute([$count, $latest ?: null, $now, $now, $videoId]);
        $this->pdo->prepare('UPDATE video_seasons SET episode_count = ? WHERE id = ?')->execute([$count, $seasonId]);
        return ['status' => 'imported', 'video_id' => $videoId, 'episode_count' => $count, 'inserted_play_urls' => $insertedUrls, 'updated_play_urls' => $updatedUrls];
    }

    /** @param list<array<string,mixed>> $items */
    public function createJob(int $providerId, string $mode, array $items, int $batchSize = 20): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('INSERT INTO video_collector_jobs (resource_provider_id,status,mode,checkpoint_json,total_items,processed_items,success_count,failed_count,skipped_count,cursor,batch_size,attempts,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$providerId, 'pending', $mode, json_encode(['next_index' => 0], JSON_UNESCAPED_SLASHES), count($items), 0, 0, 0, 0, 0, max(1, min(100, $batchSize)), 0, $now, $now]);
            $jobId = (int) $this->pdo->lastInsertId();
            $insert = $this->pdo->prepare('INSERT INTO video_collector_job_items (job_id,resource_provider_id,source_external_id,source_url_hash,item_index,title,status,payload_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
            foreach (array_values($items) as $index => $item) {
                $identity = $this->itemIdentity($providerId, $item);
                $insert->execute([$jobId, $providerId, $identity['external_id'], $identity['hash'], $index, (string) ($item['title'] ?? ''), 'pending', json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now, $now]);
            }
            $this->pdo->commit();
            return $jobId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function runJob(int $jobId, int $batchLimit = 20): array
    {
        $job = $this->job($jobId);
        if ($job === null || in_array((string) ($job['status'] ?? ''), ['paused', 'cancelled', 'completed'], true)) {
            return ['processed' => 0, 'status' => $job['status'] ?? 'missing'];
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare("UPDATE video_collector_jobs SET status = 'running', started_at = COALESCE(started_at, ?), updated_at = ? WHERE id = ?")->execute([$now, $now, $jobId]);
        $items = $this->pendingJobItems($jobId, min($batchLimit, (int) ($job['batch_size'] ?? $batchLimit)));
        $processed = 0;
        foreach ($items as $item) {
            $processed++;
            $payload = json_decode((string) ($item['payload_json'] ?? '{}'), true);
            if (!is_array($payload)) {
                $this->markJobItem((int) $item['id'], 'failed', ['reason' => 'invalid_payload']);
                continue;
            }
            try {
                $result = $this->importVideoGraph((int) ($item['resource_provider_id'] ?? 0), $payload);
                $this->markJobItem((int) $item['id'], (string) ($result['status'] ?? '') === 'skipped' ? 'skipped' : 'completed', $result);
            } catch (\Throwable $e) {
                $this->markJobItem((int) $item['id'], 'failed', ['reason' => $e->getMessage()]);
            }
        }
        $this->refreshJobProgress($jobId);
        $fresh = $this->job($jobId) ?? [];
        return ['processed' => $processed, 'status' => $fresh['status'] ?? 'unknown', 'job' => $fresh];
    }

    public function setJobStatus(int $jobId, string $status): void
    {
        $allowed = ['paused', 'pending', 'cancelled'];
        if (!in_array($status, $allowed, true)) {
            return;
        }
        $column = $status === 'cancelled' ? ', cancelled_at = :now' : '';
        $stmt = $this->pdo->prepare('UPDATE video_collector_jobs SET status = :status, updated_at = :now' . $column . ' WHERE id = :id');
        $stmt->execute([':status' => $status, ':now' => gmdate('Y-m-d H:i:s'), ':id' => $jobId]);
    }

    /** @return list<array<string,mixed>> */
    public function latestJobs(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare('SELECT j.*, p.name AS provider_name FROM video_collector_jobs j LEFT JOIN video_resource_providers p ON p.id = j.resource_provider_id ORDER BY j.id DESC LIMIT ?');
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null */
    public function job(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM video_collector_jobs WHERE id = ? LIMIT 1');
        $stmt->execute([$jobId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function publicVideos(int $limit = 24): array
    {
        $stmt = $this->pdo->prepare("SELECT id,type,title,slug,description,year,region,language,status,episode_count,latest_episode_at,category_name FROM videos WHERE visibility = 'public' ORDER BY latest_episode_at DESC, id DESC LIMIT ?");
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null */
    public function publicVideo(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM videos WHERE id = ? AND visibility = 'public' LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function episodesForVideo(int $videoId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM video_episodes WHERE video_id = ? ORDER BY sort_order ASC, id ASC');
        $stmt->execute([$videoId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null */
    public function episodeWithVideo(int $episodeId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT e.*, v.title AS video_title, v.id AS video_id, v.visibility FROM video_episodes e INNER JOIN videos v ON v.id = e.video_id WHERE e.id = ? AND v.visibility = 'public' LIMIT 1");
        $stmt->execute([$episodeId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function playUrlsForEpisode(int $episodeId): array
    {
        $stmt = $this->pdo->prepare("SELECT u.*, s.display_name, s.code, s.protocol FROM video_episode_play_urls u INNER JOIN video_play_sources s ON s.id = u.play_source_id WHERE u.episode_id = ? AND u.enabled = 1 AND s.enabled = 1 ORDER BY CASE u.health_status WHEN 'healthy' THEN 0 WHEN 'unknown' THEN 1 WHEN 'degraded' THEN 2 ELSE 3 END, s.priority ASC, u.priority ASC, u.id ASC");
        $stmt->execute([$episodeId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array{videos:int,episodes:int,play_urls:int,providers:int,running_jobs:int} */
    public function stats(): array
    {
        return [
            'videos' => (int) $this->pdo->query('SELECT COUNT(*) FROM videos')->fetchColumn(),
            'episodes' => (int) $this->pdo->query('SELECT COUNT(*) FROM video_episodes')->fetchColumn(),
            'play_urls' => (int) $this->pdo->query('SELECT COUNT(*) FROM video_episode_play_urls')->fetchColumn(),
            'providers' => (int) $this->pdo->query('SELECT COUNT(*) FROM video_resource_providers')->fetchColumn(),
            'running_jobs' => (int) $this->pdo->query("SELECT COUNT(*) FROM video_collector_jobs WHERE status IN ('pending','running')")->fetchColumn(),
        ];
    }

    private function ensureEpisode(int $videoId, int $seasonId, array $episode): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM video_episodes WHERE video_id = ? AND season_id = ? AND episode_number = ?');
        $stmt->execute([$videoId, $seasonId, $episode['episode_number']]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            $this->pdo->prepare('UPDATE video_episodes SET title = ?, sort_order = ? WHERE id = ?')->execute([(string) ($episode['title'] ?? ('第' . (int) $episode['episode_number'] . '集')), (int) $episode['episode_number'], (int) $id]);
            return (int) $id;
        }
        $this->pdo->prepare('INSERT INTO video_episodes (video_id,season_id,episode_number,title,sort_order) VALUES (?,?,?,?,?)')->execute([$videoId, $seasonId, $episode['episode_number'], $episode['title'], $episode['episode_number']]);
        return (int) $this->pdo->lastInsertId();
    }

    private function ensureSeason(int $videoId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM video_seasons WHERE video_id = ? AND season_number = 1');
        $stmt->execute([$videoId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $this->pdo->prepare('INSERT INTO video_seasons (video_id,season_number,title,episode_count,sort_order) VALUES (?,?,?,?,?)')->execute([$videoId, 1, 'Season 1', 0, 1]);
        return (int) $this->pdo->lastInsertId();
    }

    private function findVideoId(int $providerId, string $externalId, string $normalizedTitle, int $year): int|false
    {
        if ($externalId !== '') {
            $stmt = $this->pdo->prepare('SELECT id FROM videos WHERE source_provider_id = ? AND source_external_id = ? LIMIT 1');
            $stmt->execute([$providerId, $externalId]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
        }
        $stmt = $this->pdo->prepare('SELECT id FROM videos WHERE normalized_title = ? AND (year = ? OR (? = 0 AND year IS NULL)) ORDER BY id ASC LIMIT 1');
        $stmt->execute([$normalizedTitle, $year ?: null, $year]);
        $id = $stmt->fetchColumn();
        return $id === false ? false : (int) $id;
    }

    /** @param list<array<string,mixed>> $groups @return list<array<string,mixed>> */
    private function filterPlayGroups(array $groups): array
    {
        $safe = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            $episodes = [];
            foreach (is_array($group['episodes'] ?? null) ? $group['episodes'] : [] as $episode) {
                if (!is_array($episode)) {
                    continue;
                }
                $url = trim((string) ($episode['url'] ?? ''));
                if ($url === '' || ($this->http !== null && !$this->http->isSafePublicUrl($url))) {
                    continue;
                }
                $number = max(1, (int) ($episode['episode_number'] ?? (count($episodes) + 1)));
                $episodes[] = [
                    'title' => trim((string) ($episode['title'] ?? '')) !== '' ? (string) $episode['title'] : '第' . $number . '集',
                    'url' => $url,
                    'url_type' => in_array((string) ($episode['url_type'] ?? ''), ['hls', 'mp4', 'embed'], true) ? (string) $episode['url_type'] : $this->urlType($url),
                    'episode_number' => $number,
                ];
            }
            if ($episodes !== []) {
                $safe[] = ['play_source_code' => $this->safeCode((string) ($group['play_source_code'] ?? 'default')), 'episodes' => $episodes];
            }
        }
        return $safe;
    }

    private function upsertCategoryMapping(int $providerId, string $sourceId, string $sourceName, string $cmsType, string $cmsCategory): void
    {
        if ($sourceName === '') {
            return;
        }
        $sourceId = $sourceId !== '' ? $sourceId : hash('crc32b', $sourceName);
        $stmt = $this->pdo->prepare('SELECT id FROM video_category_mappings WHERE resource_provider_id = ? AND source_category_id = ? LIMIT 1');
        $stmt->execute([$providerId, $sourceId]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            $this->pdo->prepare('INSERT INTO video_category_mappings (resource_provider_id,source_category_id,source_category_name,cms_type,cms_category,enabled) VALUES (?,?,?,?,?,?)')->execute([$providerId, $sourceId, $sourceName, $cmsType, $cmsCategory, 1]);
            return;
        }
        $this->pdo->prepare('UPDATE video_category_mappings SET source_category_name = ?, cms_type = ?, cms_category = ?, enabled = 1 WHERE id = ?')->execute([$sourceName, $cmsType, $cmsCategory, (int) $id]);
    }

    /** @return list<array<string,mixed>> */
    private function pendingJobItems(int $jobId, int $limit): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM video_collector_job_items WHERE job_id = ? AND status IN ('pending','retry') ORDER BY item_index ASC LIMIT ?");
        $stmt->bindValue(1, $jobId, \PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, $limit), \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<string,mixed> $result */
    private function markJobItem(int $itemId, string $status, array $result): void
    {
        $this->pdo->prepare('UPDATE video_collector_job_items SET status = ?, attempts = attempts + 1, result_json = ?, last_error = ?, updated_at = ? WHERE id = ?')
            ->execute([$status, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (string) ($result['reason'] ?? ''), gmdate('Y-m-d H:i:s'), $itemId]);
    }

    private function refreshJobProgress(int $jobId): void
    {
        $counts = ['completed' => 0, 'failed' => 0, 'skipped' => 0, 'pending' => 0, 'retry' => 0];
        $stmt = $this->pdo->prepare('SELECT status, COUNT(*) AS total FROM video_collector_job_items WHERE job_id = ? GROUP BY status');
        $stmt->execute([$jobId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }
        $processed = $counts['completed'] + $counts['failed'] + $counts['skipped'];
        $total = array_sum($counts);
        $status = ($counts['pending'] + $counts['retry']) > 0 ? 'running' : 'completed';
        $completedAt = $status === 'completed' ? gmdate('Y-m-d H:i:s') : null;
        $checkpoint = json_encode(['next_index' => $processed], JSON_UNESCAPED_SLASHES);
        $this->pdo->prepare('UPDATE video_collector_jobs SET status = ?, checkpoint_json = ?, total_items = ?, processed_items = ?, success_count = ?, failed_count = ?, skipped_count = ?, cursor = ?, completed_at = COALESCE(completed_at, ?), updated_at = ? WHERE id = ?')
            ->execute([$status, $checkpoint, $total, $processed, $counts['completed'], $counts['failed'], $counts['skipped'], $processed, $completedAt, gmdate('Y-m-d H:i:s'), $jobId]);
    }

    /** @return array{external_id:?string,hash:string} */
    private function itemIdentity(int $providerId, array $item): array
    {
        $externalId = trim((string) ($item['external_id'] ?? ''));
        $firstUrl = '';
        foreach (($item['play_groups'] ?? []) as $group) {
            foreach (($group['episodes'] ?? []) as $episode) {
                $firstUrl = (string) ($episode['url'] ?? '');
                break 2;
            }
        }
        return ['external_id' => $externalId !== '' ? $externalId : null, 'hash' => hash('sha256', $providerId . '|' . $externalId . '|' . $this->norm((string) ($item['title'] ?? '')) . '|' . $firstUrl)];
    }

    private function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $slug = $base;
        for ($i = 2; $i < 200; $i++) {
            $stmt = $this->pdo->prepare('SELECT id FROM videos WHERE slug = ? LIMIT 1');
            $stmt->execute([$slug]);
            $id = $stmt->fetchColumn();
            if ($id === false || ($ignoreId !== null && (int) $id === $ignoreId)) {
                return $slug;
            }
            $slug = mb_substr($base, 0, 150) . '-' . $i;
        }
        return mb_substr($base, 0, 140) . '-' . substr(hash('sha256', $base . microtime(true)), 0, 10);
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9\x{4e00}-\x{9fa5}]+/u', '-', $value) ?? '', '-'));
        return $slug !== '' ? mb_substr($slug, 0, 160) : 'video-' . substr(hash('sha256', $value), 0, 12);
    }

    private function norm(string $value): string
    {
        return mb_strtolower(preg_replace('/[\s\pP]+/u', '', $value) ?? '', 'UTF-8');
    }

    private function urlType(string $url): string
    {
        if (preg_match('/\.m3u8(?:\?|$)/i', $url)) {
            return 'hls';
        }
        if (preg_match('/\.mp4(?:\?|$)/i', $url)) {
            return 'mp4';
        }
        return 'embed';
    }

    private function safeCode(string $code): string
    {
        $code = strtolower(trim(preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $code) ?? '', '-'));
        return $code !== '' ? mb_substr($code, 0, 64) : 'default';
    }

    private function baseUrl(string $url): string
    {
        $parts = parse_url($url);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');
        return $host !== '' ? $scheme . '://' . $host : '';
    }

    private function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}

final class IncrementalPlanner
{
    public function planEpisodes(array $remoteEpisodes, array $localEpisodes, string $urlPolicy = 'auto_update'): array
    {
        $localByNumber = [];
        foreach ($localEpisodes as $episode) {
            $localByNumber[(int) $episode['episode_number']] = $episode;
        }
        $new = [];
        $urlUpdates = [];
        foreach ($remoteEpisodes as $episode) {
            $number = (int) $episode['episode_number'];
            if (!isset($localByNumber[$number])) {
                $new[] = $episode;
                continue;
            }
            if (($episode['content_hash'] ?? hash('sha256', (string) ($episode['url'] ?? ''))) !== ($localByNumber[$number]['content_hash'] ?? '')) {
                $urlUpdates[] = ['episode_number' => $number, 'policy' => $urlPolicy, 'remote' => $episode, 'local' => $localByNumber[$number]];
            }
        }
        return ['new_episodes' => $new, 'url_updates' => $urlUpdates, 'delete_missing' => false];
    }
}
