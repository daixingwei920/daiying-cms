<?php

declare(strict_types=1);

namespace Cms\Core\Notification;

use Cms\Core\Security\SecretRedactor;
use InvalidArgumentException;
use PDO;

final class NotificationService
{
    private const API_VERSION = '1.0';
    private const SOURCE_TYPES = ['core', 'plugin', 'system', 'payment', 'commerce', 'mail', 'media', 'storage', 'ai', 'distribution', 'security', 'update'];
    private const SEVERITIES = ['info', 'success', 'warning', 'error'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $sourceOwner = 'core',
    ) {
    }

    public function forPlugin(string $pluginId): self
    {
        $pluginId = $this->cleanIdentifier($pluginId, 120);
        if ($pluginId === '') {
            throw new InvalidArgumentException('Notification plugin owner is invalid.');
        }

        return new self($this->pdo, $pluginId);
    }

    /** @return array<string,mixed> */
    public function capabilities(): array
    {
        return [
            'api_version' => self::API_VERSION,
            'create' => true,
            'dedupe' => true,
            'read_archive' => true,
            'source_owner' => $this->sourceOwner,
            'source_types' => self::SOURCE_TYPES,
            'severities' => self::SEVERITIES,
        ];
    }

    /** @param array<string,mixed> $options */
    public function create(string $title, string $body = '', array $options = []): int
    {
        $data = $this->normalize($title, $body, $options);
        $repo = $this->repo();
        $existing = $repo->findByDedupeKey((string) $data['dedupe_key']);
        if (is_array($existing)) {
            $repo->updateDedupe((int) $existing['id'], $data);
            return (int) $existing['id'];
        }

        return $repo->create($data);
    }

    /** @param array<string,mixed> $options */
    public function createFromArray(array $options): int
    {
        return $this->create((string) ($options['title'] ?? ''), (string) ($options['body'] ?? $options['summary'] ?? ''), $options);
    }

    /** @param array{status?:string,limit?:int,include_archived?:bool} $filters @return list<array<string,mixed>> */
    public function recent(array $filters = []): array
    {
        return $this->repo()->recent($filters);
    }

    public function unreadCount(): int
    {
        return $this->repo()->unreadCount();
    }

    public function markRead(int $id): void
    {
        $this->repo()->markRead($id);
    }

    public function markAllRead(): int
    {
        return $this->repo()->markAllRead();
    }

    public function archive(int $id): void
    {
        $this->repo()->archive($id);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->repo()->find($id);
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private function normalize(string $title, string $body, array $options): array
    {
        $sourceOwner = $this->sourceOwner === 'core' ? $this->cleanIdentifier((string) ($options['source_owner'] ?? 'core'), 120) : $this->sourceOwner;
        if ($sourceOwner === '') {
            $sourceOwner = $this->sourceOwner === 'core' ? 'core' : $this->sourceOwner;
        }
        $sourceType = $this->cleanIdentifier((string) ($options['source_type'] ?? ($sourceOwner === 'core' ? 'system' : 'plugin')), 64);
        if (!in_array($sourceType, self::SOURCE_TYPES, true)) {
            $sourceType = $sourceOwner === 'core' ? 'system' : 'plugin';
        }
        $severity = $this->cleanIdentifier((string) ($options['severity'] ?? 'info'), 32);
        if (!in_array($severity, self::SEVERITIES, true)) {
            $severity = 'info';
        }
        $safeTitle = $this->safeText($title, 191);
        if ($safeTitle === '') {
            throw new InvalidArgumentException('Notification title is required.');
        }
        $payload = SecretRedactor::redact(is_array($options['payload'] ?? null) ? $options['payload'] : []);
        $payloadJson = $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return [
            'uuid' => 'ntf_' . bin2hex(random_bytes(16)),
            'site_id' => $this->cleanIdentifier((string) ($options['site_id'] ?? 'default'), 96) ?: 'default',
            'source_type' => $sourceType,
            'source_owner' => $sourceOwner,
            'source_id' => $this->safeText((string) ($options['source_id'] ?? ''), 191),
            'severity' => $severity,
            'title' => $safeTitle,
            'body' => $this->safeText((string) SecretRedactor::redact($body), 2000),
            'action_url' => $this->safeActionUrl((string) ($options['action_url'] ?? '')),
            'status' => 'unread',
            'dedupe_key' => $this->safeText((string) ($options['dedupe_key'] ?? ''), 191),
            'payload_json' => $payloadJson,
            'created_at' => gmdate('c'),
            'read_at' => null,
            'archived_at' => null,
        ];
    }

    private function safeActionUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $url) === 1 || str_starts_with($url, '//')) {
            throw new InvalidArgumentException('Notification action URL is invalid.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Notification action URL must be a local admin path.');
        }
        $path = (string) ($parts['path'] ?? '');
        if ($path === '' || !str_starts_with($path, '/admin')) {
            throw new InvalidArgumentException('Notification action URL must be a local admin path.');
        }
        $query = isset($parts['query']) ? '?' . (string) $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . (string) $parts['fragment'] : '';
        $safe = $path . $query . $fragment;
        if (strlen($safe) > 500) {
            throw new InvalidArgumentException('Notification action URL is too long.');
        }
        if (SecretRedactor::redact($safe) !== $safe) {
            throw new InvalidArgumentException('Notification action URL must not contain secrets.');
        }

        return $safe;
    }

    private function safeText(string $value, int $max): string
    {
        $value = trim(strip_tags($value));
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?: '';
        $value = preg_replace('/\s+/u', ' ', $value) ?: $value;

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }

    private function cleanIdentifier(string $value, int $max): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,' . ($max - 1) . '}$/', $value) !== 1) {
            return '';
        }

        return $value;
    }

    private function repo(): NotificationRepository
    {
        return new NotificationRepository($this->pdo);
    }
}
