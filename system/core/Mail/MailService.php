<?php

declare(strict_types=1);

namespace Cms\Core\Mail;

use Cms\Core\Config\Settings;
use PDO;

final class MailService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Settings $settings,
        private readonly ?MailProviderInterface $providerOverride = null,
    ) {
    }

    public function isEnabled(): bool
    {
        try {
            $config = $this->repository()->current();
            return !empty($config['enabled']) && (string) ($config['from_email'] ?? '') !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    public function getConfig(): array
    {
        return $this->repository()->current();
    }

    public function send(MailMessage $message): MailResult
    {
        $config = $this->runtimeConfig();
        if (empty($config['enabled'])) {
            return MailResult::failure((string) ($config['provider_id'] ?? 'unknown'), 'Mail is disabled.');
        }
        if ((string) ($config['from_email'] ?? '') === '') {
            return MailResult::failure((string) ($config['provider_id'] ?? 'unknown'), 'Mail from address is not configured.');
        }

        try {
            return $this->provider($config)->send($message, $config);
        } catch (\Throwable $exception) {
            return MailResult::failure((string) ($config['provider_id'] ?? 'unknown'), Redactor::redact($exception->getMessage()));
        }
    }

    public function sendHtml(string $to, string $subject, string $html, string $text = ''): MailResult
    {
        return $this->send(MailMessage::html($to, $subject, $html, $text));
    }

    /** @param array<string,string|int|float|bool|null> $variables */
    public function sendTemplate(string $templateId, string $to, array $variables = [], string $locale = 'default'): MailResult
    {
        $template = (new MailTemplateRepository($this->pdo))->find($templateId, $locale);
        if ($template === null || (int) ($template['enabled'] ?? 0) !== 1) {
            return MailResult::failure($this->providerId(), 'Mail template is unavailable.');
        }
        $renderer = new MailTemplateRepository($this->pdo);
        $message = new MailMessage(
            [new MailAddress($to)],
            $renderer->replace((string) $template['subject'], $variables),
            $renderer->replace((string) $template['html_body'], $variables),
            $renderer->replace((string) $template['text_body'], $variables),
            [],
            [],
            null,
            [],
            $locale,
            $templateId,
            $variables,
        );

        return $this->send($message);
    }

    public function queue(MailMessage $message, string $eventId = ''): int
    {
        return (new MailQueueRepository($this->pdo))->enqueue($message, $this->providerId(), $eventId);
    }

    /** @return array{provider:string,status:string,message:string,error?:string} */
    public function testConnection(?string $testEmail = null): array
    {
        $config = $this->runtimeConfig();
        try {
            $provider = $this->provider($config);
            $result = $testEmail !== null && $testEmail !== ''
                ? $this->send(new MailMessage([new MailAddress($testEmail)], 'Daiying CMS test email', '<p>Daiying CMS mail test succeeded.</p>', 'Daiying CMS mail test succeeded.'))
                : $provider->testConnection($config);
        } catch (\Throwable $exception) {
            $providerId = (string) ($config['provider_id'] ?? 'unknown');
            $result = MailResult::failure($providerId, Redactor::redact($exception->getMessage()));
            $provider = new class ($providerId) implements MailProviderInterface {
                public function __construct(private readonly string $providerId) {}
                public function id(): string { return $this->providerId; }
                public function label(): string { return $this->providerId; }
                public function apiVersion(): string { return '1.0'; }
                public function capabilities(): array { return []; }
                public function send(MailMessage $message, array $config): MailResult { return MailResult::failure($this->providerId, 'Provider unavailable.'); }
                public function testConnection(array $config): MailResult { return MailResult::failure($this->providerId, 'Provider unavailable.'); }
            };
        }

        return [
            'provider' => $provider->id(),
            'status' => $result->success ? 'success' : 'failed',
            'message' => $result->success ? '连接成功' : '连接失败',
            'error' => $result->success ? '' : $result->error,
        ];
    }

    /** @return array{processed:int,sent:int,failed:int} */
    public function processQueue(int $limit = 10): array
    {
        $queue = new MailQueueRepository($this->pdo);
        $processed = 0;
        $sent = 0;
        $failed = 0;
        foreach ($queue->pending($limit) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $processed++;
            $queue->markSending($id);
            try {
                $payload = json_decode((string) ($row['payload_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
                $message = MailMessage::fromArray(is_array($payload) ? $payload : []);
                $result = $this->send($message);
                if ($result->success) {
                    $sent++;
                    $queue->markSent($id, $result->messageId);
                } else {
                    $failed++;
                    $queue->markFailed($id, $result->error);
                }
            } catch (\Throwable $exception) {
                $failed++;
                $queue->markFailed($id, $exception->getMessage());
            }
        }

        return ['processed' => $processed, 'sent' => $sent, 'failed' => $failed];
    }

    /** @return array<string,mixed> */
    public function capabilities(): array
    {
        return [
            'api_version' => '1.0',
            'send' => true,
            'send_html' => true,
            'send_template' => true,
            'queue' => true,
            'test_connection' => true,
            'providers' => array_keys(MailProviderRegistry::all()),
            'events' => array_keys(MailEventRegistry::all()),
        ];
    }

    private function providerId(): string
    {
        return (string) ($this->repository()->current()['provider_id'] ?? 'smtp');
    }

    private function repository(): SiteMailSettingsRepository
    {
        return new SiteMailSettingsRepository($this->pdo, (string) $this->settings->get('security.encryption_key', ''));
    }

    /** @return array<string,mixed> */
    private function runtimeConfig(): array
    {
        $config = $this->repository()->runtimeConfig();
        if (($config['from_email'] ?? '') === '') {
            $fallback = (string) ($this->settings->get('mail.from', '') ?: $this->settings->get('site.email', ''));
            if ($fallback !== '') {
                $config['from_email'] = $fallback;
            }
        }

        return $config;
    }

    /** @param array<string,mixed> $config */
    private function provider(array $config): MailProviderInterface
    {
        if ($this->providerOverride !== null) {
            return $this->providerOverride;
        }
        $providerId = (string) ($config['provider_id'] ?? 'smtp');
        $provider = MailProviderRegistry::get($providerId);
        if ($provider === null) {
            throw new MailException('Mail provider is not registered.');
        }

        return $provider;
    }
}
