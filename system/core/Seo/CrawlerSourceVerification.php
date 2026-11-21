<?php

declare(strict_types=1);

namespace Cms\Core\Seo;

use Closure;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Scheduler\{ScheduledTask, SchedulerService, SchedulerTaskRegistry};

/** Best-effort source evidence. Nothing here grants crawler access or changes responses. */
final class CrawlerSourceVerification
{
    public const TASK_ID = 'core.seo.crawlers.verify';
    public const QUEUE_TTL = 3600;
    private const MAX_BYTES = 524288;
    private const MAX_SUBJECTS = 256;
    private readonly Closure $fetch;
    private readonly Closure $dns;
    private readonly Closure $clock;

    public function __construct(
        private readonly string $root,
        private readonly Settings $settings,
        ?callable $fetch = null,
        ?callable $dns = null,
        ?callable $clock = null,
    ) {
        $this->fetch = Closure::fromCallable($fetch ?? [CrawlerVerificationNetwork::class, 'fetch']);
        $this->dns = Closure::fromCallable($dns ?? [CrawlerVerificationNetwork::class, 'dns']);
        $this->clock = Closure::fromCallable($clock ?? 'time');
    }

    /** No network, process creation or blocking lock on the request path. */
    public function classify(string $agent, string $peer): array
    {
        $policy = CrawlerSourcePolicy::forAgent($agent);
        if ($policy === null) { return ['verification' => 'source_unsupported']; }
        $ip = CrawlerSourcePolicy::publicIp($peer);
        if ($ip === null) { return ['verification' => 'source_unavailable', 'verification_reason' => 'no_public_peer']; }
        $now = ($this->clock)();
        return $this->state(function (array &$state) use ($agent, $policy, $ip, $now): array {
            if ($this->matchesFeed($state, $policy['feed'], $ip, $now)) { return self::result('source_verified_ip', 'official_ip', (string) $policy['feed'], $now); }
            $master = $this->master();
            if ($master === '') { return ['verification' => 'source_unavailable', 'verification_reason' => 'missing_key']; }
            $key = hash_hmac('sha256', $agent . "\0" . $ip, $master);
            $subject = $state['subjects'][$key] ?? [];
            if (($subject['agent'] ?? '') === $agent && is_array($subject['result'] ?? null)
                && ($subject['expires_at'] ?? 0) > $now && ($subject['checked_at'] ?? PHP_INT_MAX) <= $now
                && ($subject['expires_at'] ?? PHP_INT_MAX) <= ($subject['checked_at'] ?? 0) + 3600) {
                $status = self::status(['agent' => $agent] + $subject['result']);
                if (in_array($status, ['source_verified_ip', 'source_verified_dns', 'source_not_verified'], true)
                    && $status === ($subject['result']['verification'] ?? null)) { return $subject['result']; }
            }
            if (($subject['agent'] ?? '') !== $agent || !isset($subject['ciphertext'], $subject['batch'], $subject['queued_at'])) {
                if (count($state['subjects']) >= self::MAX_SUBJECTS && !isset($state['subjects'][$key])) {
                    return ['verification' => 'source_unavailable', 'verification_reason' => 'queue_full'];
                }
                $batch = bin2hex(random_bytes(16)); $nonce = random_bytes(12);
                $cipher = openssl_encrypt($ip, 'aes-256-gcm', $master, OPENSSL_RAW_DATA, $nonce, $tag, $key . $agent . $batch);
                if (!is_string($cipher)) { return ['verification' => 'source_unavailable']; }
                $subject = ['agent' => $agent, 'batch' => $batch, 'queued_at' => $now, 'expires_at' => $now + self::QUEUE_TTL,
                    'ciphertext' => base64_encode($nonce . $tag . $cipher)];
                $state['subjects'][$key] = $subject;
            }
            return ['verification' => 'source_pending', 'source_key' => $subject['batch'], 'verification_queued_at' => $subject['queued_at']];
        }) ?? ['verification' => 'source_unavailable', 'verification_reason' => 'storage_unavailable'];
    }

    /** CLI-only worker: at most four sources, two feed fetches and eight seconds per run. */
    public function run(int $limit = 4): array
    {
        $counts = ['processed' => 0, 'verified' => 0, 'not_verified' => 0, 'pending' => 0];
        if (PHP_SAPI !== 'cli') { return $counts; }
        $lock = $this->open('seo-crawler-sources.worker.lock');
        if ($lock === null) { return $counts; }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) { return $counts; }
            $snapshot = $this->state(static fn (array &$state): array => $state);
            if (!is_array($snapshot)) { return $counts; }
            if (!(bool) $this->settings->get('seo.crawler_log_enabled', false)) {
                $this->state(static function (array &$state): bool { $state['subjects'] = []; return true; });
                $cancelled = [];
                foreach ($snapshot['subjects'] as $subject) {
                    if (is_string($subject['batch'] ?? null)) { $cancelled[$subject['batch']] = ['verification' => 'source_unavailable']; }
                }
                (new CrawlerLog($this->root, $this->settings))->applySourceResults($cancelled);
                return $counts;
            }
            $deadline = hrtime(true) + 8000000000; $fetches = 0;
            foreach ($snapshot['subjects'] as $key => $subject) {
                $now = ($this->clock)();
                if ($counts['processed'] >= max(1, min(4, $limit)) || hrtime(true) >= $deadline) { break; }
                if (!is_array($subject) || !isset($subject['ciphertext'], $subject['batch'], $subject['agent'])
                    || ($subject['retry_at'] ?? 0) > $now || ($subject['expires_at'] ?? 0) <= $now) { continue; }
                $agent = (string) $subject['agent']; $policy = CrawlerSourcePolicy::forAgent($agent);
                $ip = $this->decrypt($key, $subject);
                if ($policy === null || $ip === null) { continue; }
                $counts['processed']++;
                $feedId = $policy['feed'];
                if ($feedId !== null && $fetches < 2 && hrtime(true) + 2000000000 <= $deadline) {
                    $feed = $snapshot['feeds'][$feedId] ?? [];
                    if (($feed['fetched_at'] ?? 0) < $now - 3600 && ($feed['attempted_at'] ?? 0) < $now - 300) {
                        $fetches++;
                        try { $raw = ($this->fetch)(CrawlerSourcePolicy::FEEDS[$feedId]); } catch (\Throwable) { $raw = null; }
                        $prefixes = is_string($raw) ? CrawlerSourcePolicy::parseFeed($raw) : null;
                        $saved = $this->state(static function (array &$state) use ($feedId, $prefixes, $now): array {
                            $state['feeds'][$feedId]['attempted_at'] = $now;
                            if ($prefixes !== null) { $state['feeds'][$feedId] = ['prefixes' => $prefixes, 'fetched_at' => $now, 'expires_at' => $now + 86400, 'attempted_at' => $now]; }
                            return $state['feeds'][$feedId];
                        });
                        if (is_array($saved)) { $snapshot['feeds'][$feedId] = $saved; }
                    }
                }
                $result = null;
                if ($this->matchesFeed($snapshot, $feedId, $ip, $now)) {
                    $result = self::result('source_verified_ip', 'official_ip', (string) $feedId, $now);
                } elseif ($policy['suffixes'] !== []) {
                    $verified = null;
                    if (hrtime(true) + 1500000000 <= $deadline) {
                        try { $verified = ($this->dns)($ip, $policy['suffixes']); } catch (\Throwable) { $verified = null; }
                    }
                    if ($verified === true) { $result = self::result('source_verified_dns', 'forward_confirmed_dns', $agent, $now); }
                    elseif ($verified === false) { $result = ['verification' => 'source_not_verified']; }
                } elseif ($this->freshFeed($snapshot, $feedId, $now) !== null) {
                    $result = ['verification' => 'source_not_verified'];
                }
                $saved = $this->state(static function (array &$state) use ($key, $subject, $result, $now): bool {
                    if (($state['subjects'][$key]['batch'] ?? null) !== $subject['batch']) { return false; }
                    if ($result === null) {
                        // A slow/unreachable source must not monopolize every minute's small batch.
                        $retry = $state['subjects'][$key]; $retry['retry_at'] = $now + 60;
                        unset($state['subjects'][$key]); $state['subjects'][$key] = $retry;
                        return true;
                    }
                    $ttl = str_starts_with($result['verification'], 'source_verified_') ? 3600 : 300;
                    $state['subjects'][$key] = ['agent' => $subject['agent'], 'checked_at' => $now, 'expires_at' => $now + $ttl, 'result' => $result];
                    $state['completed'][$subject['batch']] = ['result' => $result, 'expires_at' => $now + self::QUEUE_TTL];
                    while (count($state['completed']) > 512) { unset($state['completed'][array_key_first($state['completed'])]); }
                    return true;
                });
                if ($result === null || $saved !== true) { $counts['pending']++; }
                elseif (str_starts_with($result['verification'], 'source_verified_')) { $counts['verified']++; }
                else { $counts['not_verified']++; }
            }
            $finished = ($this->clock)();
            $completed = $this->state(static function (array &$state) use ($counts, $finished): array {
                $state['last_run_at'] = $finished; $state['last_result'] = $counts;
                return array_map(static fn (array $item): array => $item['result'], $state['completed']);
            });
            if (is_array($completed)) { (new CrawlerLog($this->root, $this->settings))->applySourceResults($completed); }
            return $counts;
        } finally { fclose($lock); }
    }

    public function prune(): void { $this->state(static fn (array &$state): bool => true); }

    /** Keep an operator-paused existing task paused. No IP goes into task payloads. */
    public static function register(string $root, Settings $settings): void
    {
        try {
            SchedulerTaskRegistry::register(self::TASK_ID, static function () use ($root, $settings): void { (new self($root, $settings))->run(); });
            // A previously scheduled task still needs its cleanup handler after collection is disabled.
            if (!(bool) $settings->get('seo.crawler_log_enabled', false)) { return; }
            $service = new SchedulerService(ConnectionFactory::make($settings));
            if ($service->find(self::TASK_ID) === null) { $service->register(new ScheduledTask(self::TASK_ID, 'core.seo.crawlers', 60)); }
        } catch (\Throwable) { /* Optional telemetry cannot prevent application startup. */ }
    }

    /** Validate proof shape when rendering older/malformed ledgers; never upgrade an unknown string to verified. */
    public static function status(array $row): string
    {
        $value = $row['verification'] ?? 'source_unverified';
        if (in_array($value, ['source_verified_ip', 'source_verified_dns'], true)) {
            $policy = CrawlerSourcePolicy::forAgent((string) ($row['agent'] ?? ''));
            $checked = $row['verification_checked_at'] ?? null;
            if ($policy === null || !is_int($checked) || $checked < 1 || $checked > time() + 60) { return 'source_unverified'; }
            if ($value === 'source_verified_ip' && ($row['verification_method'] ?? '') === 'official_ip'
                && ($row['verification_evidence'] ?? '') === $policy['feed'] && $policy['feed'] !== null) { return $value; }
            if ($value === 'source_verified_dns' && ($row['verification_method'] ?? '') === 'forward_confirmed_dns'
                && ($row['verification_evidence'] ?? '') === $row['agent'] && $policy['suffixes'] !== []) { return $value; }
            return 'source_unverified';
        }
        if ($value === 'source_pending') {
            $queued = $row['verification_queued_at'] ?? null;
            return is_int($queued) && $queued <= time() && $queued > time() - self::QUEUE_TTL ? $value : 'source_unavailable';
        }
        return in_array($value, ['source_not_verified', 'source_unavailable', 'source_unsupported'], true) ? $value : 'source_unverified';
    }

    public static function label(string $status): string
    {
        return match ($status) {
            'source_verified_ip' => '已验证 · 官方 IP 段',
            'source_verified_dns' => '已验证 · 双向 DNS',
            'source_pending' => '待后台核验',
            'source_not_verified' => '未通过核验',
            'source_unsupported' => '暂不支持来源核验',
            'source_unavailable' => '暂无法核验',
            default => '历史未验证',
        };
    }

    private static function result(string $status, string $method, string $evidence, int $now): array
    {
        return ['verification' => $status, 'verification_method' => $method, 'verification_evidence' => $evidence, 'verification_checked_at' => $now];
    }

    private function freshFeed(array $state, ?string $id, int $now): ?array
    {
        $feed = $id !== null ? ($state['feeds'][$id] ?? null) : null;
        return is_array($feed) && is_int($feed['fetched_at'] ?? null) && $feed['fetched_at'] <= $now
            && ($feed['expires_at'] ?? 0) > $now && $feed['expires_at'] <= $feed['fetched_at'] + 86400
            && is_array($feed['prefixes'] ?? null) && count($feed['prefixes']) <= 2048 ? $feed : null;
    }

    private function matchesFeed(array $state, ?string $id, string $ip, int $now): bool
    {
        foreach (($this->freshFeed($state, $id, $now)['prefixes'] ?? []) as $cidr) {
            if (is_string($cidr) && CrawlerSourcePolicy::matches($ip, $cidr)) { return true; }
        }
        return false;
    }

    private function master(): string
    {
        $key = (string) $this->settings->get('security.encryption_key', '');
        return $key === '' ? '' : hash('sha256', "cms.crawler.source.v1\0" . $key, true);
    }

    private function decrypt(string $key, array $subject): ?string
    {
        if ($this->master() === '' || !preg_match('/^[a-f0-9]{64}$/D', $key)
            || !is_string($subject['batch'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $subject['batch'])
            || !is_string($subject['ciphertext'] ?? null) || strlen($subject['ciphertext']) > 128) { return null; }
        $raw = base64_decode($subject['ciphertext'], true);
        if (!is_string($raw) || strlen($raw) < 29) { return null; }
        $ip = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->master(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $key . $subject['agent'] . $subject['batch']);
        return is_string($ip) && CrawlerSourcePolicy::publicIp($ip) === $ip
            && hash_equals($key, hash_hmac('sha256', $subject['agent'] . "\0" . $ip, $this->master())) ? $ip : null;
    }

    /** @return resource|null */
    private function open(string $name)
    {
        $directory = $this->root . '/storage/cache'; $path = $directory . '/' . $name;
        if (is_link($this->root . '/storage') || is_link($directory) || is_link($path)
            || (file_exists($path) && !is_file($path))) { return null; }
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) { return null; }
        $file = @fopen($path, 'c+b');
        if ($file === false) { return null; }
        @chmod($path, 0600); return $file;
    }

    /** State is bounded, private and nonblocking; never hold this lock during network operations. */
    private function state(callable $callback): mixed
    {
        $file = $this->open('seo-crawler-sources.json');
        if ($file === null) { return null; }
        try {
            if (!flock($file, LOCK_EX | LOCK_NB) || (fstat($file)['size'] ?? PHP_INT_MAX) > self::MAX_BYTES) { return null; }
            $raw = stream_get_contents($file, self::MAX_BYTES + 1);
            $state = $raw === '' ? ['version' => 1, 'feeds' => [], 'subjects' => [], 'completed' => []] : json_decode((string) $raw, true, 32);
            if (!is_array($state) || ($state['version'] ?? null) !== 1 || !is_array($state['feeds'] ?? null)
                || count($state['feeds']) > count(CrawlerSourcePolicy::FEEDS) || !is_array($state['subjects'] ?? null)
                || count($state['subjects']) > self::MAX_SUBJECTS || !is_array($state['completed'] ?? null) || count($state['completed']) > 512) { return null; }
            $now = ($this->clock)();
            foreach (['subjects', 'completed'] as $list) {
                foreach ($state[$list] as $key => $item) {
                    if (!is_array($item) || !is_int($item['expires_at'] ?? null) || $item['expires_at'] <= $now || $item['expires_at'] > $now + self::QUEUE_TTL) { unset($state[$list][$key]); }
                }
            }
            $value = $callback($state);
            $encoded = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (strlen($encoded) > self::MAX_BYTES) { return null; }
            if ($encoded !== $raw) {
                rewind($file);
                if (fwrite($file, $encoded) !== strlen($encoded) || !ftruncate($file, strlen($encoded)) || !fflush($file)) { return null; }
            }
            return $value;
        } catch (\Throwable) { return null; }
        finally { fclose($file); }
    }
}
