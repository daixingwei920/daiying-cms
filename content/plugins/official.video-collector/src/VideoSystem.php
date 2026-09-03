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
        $host = strtolower((string) ($parts['host'] ?? ''));
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
            $items = $items['list']['video'] ?? $items['video'] ?? $items;
        }
        return array_map(fn ($item): array => $this->normalizeVideo((array) $item), is_array($items) && array_is_list($items) ? $items : [$items]);
    }

    public function normalizeVideo(array $raw): array
    {
        $title = (string) ($raw['vod_name'] ?? $raw['title'] ?? '');
        $typeName = (string) ($raw['type_name'] ?? $raw['category'] ?? '');
        return [
            'external_id' => (string) ($raw['vod_id'] ?? $raw['id'] ?? ''),
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

    private function mapType(string $typeName, string $fallback): string
    {
        $source = $typeName . ' ' . $fallback;
        return match (true) {
            str_contains($source, '短剧') => 'short_drama',
            str_contains($source, '动漫') || str_contains($source, '动画') => 'anime',
            str_contains($source, '综艺') => 'variety',
            str_contains($source, '剧') => 'tv',
            default => 'movie',
        };
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
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function saveProvider(array $provider): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO video_resource_providers (name,provider_type,base_url,api_url,enabled,priority,request_interval,timeout,retry_count,config_encrypted) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$provider['name'], $provider['provider_type'], $provider['base_url'], $provider['api_url'], (int) ($provider['enabled'] ?? 1), (int) ($provider['priority'] ?? 100), (int) ($provider['request_interval'] ?? 3), (int) ($provider['timeout'] ?? 10), (int) ($provider['retry_count'] ?? 2), $provider['config_encrypted'] ?? null]);
        return (int) $this->pdo->lastInsertId();
    }

    public function ensurePlaySource(string $code, string $protocol): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM video_play_sources WHERE code = ?');
        $stmt->execute([$code]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
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
        $now = gmdate('Y-m-d H:i:s');
        $slug = $this->slug($video['title'] . '-' . ($video['year'] ?: substr($video['external_id'], 0, 8)));
        $stmt = $this->pdo->prepare('SELECT id FROM videos WHERE title = ? AND year = ?');
        $stmt->execute([$video['title'], $video['year'] ?: null]);
        $videoId = $stmt->fetchColumn();
        if ($videoId === false) {
            $this->pdo->prepare('INSERT INTO videos (uuid,type,title,original_title,slug,description,year,region,language,status,season_count,episode_count,external_ids,visibility,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$this->uuid(), $video['type'], $video['title'], $video['original_title'], $slug, $video['description'], $video['year'] ?: null, $video['region'], $video['language'], $video['status'], 1, 0, json_encode(['provider:' . $providerId => $video['external_id']], JSON_UNESCAPED_SLASHES), 'public', $now, $now]);
            $videoId = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO video_seasons (video_id,season_number,title,episode_count,sort_order) VALUES (?,?,?,?,?)')->execute([$videoId, 1, 'Season 1', 0, 1]);
        } else {
            $videoId = (int) $videoId;
        }
        $seasonId = (int) $this->pdo->query('SELECT id FROM video_seasons WHERE video_id = ' . $videoId . ' AND season_number = 1')->fetchColumn();
        foreach ($video['play_groups'] as $group) {
            foreach ($group['episodes'] as $episode) {
                $episodeId = $this->ensureEpisode($videoId, $seasonId, $episode);
                $sourceId = $this->ensurePlaySource($group['play_source_code'], $episode['url_type']);
                $hash = hash('sha256', $group['play_source_code'] . '|' . $episode['url']);
                $exists = $this->pdo->prepare('SELECT id FROM video_episode_play_urls WHERE episode_id = ? AND play_source_id = ? AND content_hash = ?');
                $exists->execute([$episodeId, $sourceId, $hash]);
                if ($exists->fetchColumn() === false) {
                    $this->pdo->prepare('INSERT INTO video_episode_play_urls (video_id,episode_id,resource_provider_id,play_source_id,source_episode_id,url,url_type,priority,enabled,content_hash,health_status) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$videoId, $episodeId, $providerId, $sourceId, (string) $episode['episode_number'], $episode['url'], $episode['url_type'], 100, 1, $hash, 'unknown']);
                }
            }
        }
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM video_episodes WHERE video_id = ' . $videoId)->fetchColumn();
        $latest = (int) $this->pdo->query('SELECT id FROM video_episodes WHERE video_id = ' . $videoId . ' ORDER BY sort_order DESC LIMIT 1')->fetchColumn();
        $this->pdo->prepare('UPDATE videos SET episode_count = ?, latest_episode_id = ?, latest_episode_at = ?, updated_at = ? WHERE id = ?')->execute([$count, $latest ?: null, $now, $now, $videoId]);
        $this->pdo->prepare('UPDATE video_seasons SET episode_count = ? WHERE id = ?')->execute([$count, $seasonId]);
        return $videoId;
    }

    private function ensureEpisode(int $videoId, int $seasonId, array $episode): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM video_episodes WHERE video_id = ? AND season_id = ? AND episode_number = ?');
        $stmt->execute([$videoId, $seasonId, $episode['episode_number']]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $this->pdo->prepare('INSERT INTO video_episodes (video_id,season_id,episode_number,title,sort_order) VALUES (?,?,?,?,?)')->execute([$videoId, $seasonId, $episode['episode_number'], $episode['title'], $episode['episode_number']]);
        return (int) $this->pdo->lastInsertId();
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9\x{4e00}-\x{9fa5}]+/u', '-', $value) ?? '', '-'));
        return $slug !== '' ? mb_substr($slug, 0, 160) : 'video-' . substr(hash('sha256', $value), 0, 12);
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
