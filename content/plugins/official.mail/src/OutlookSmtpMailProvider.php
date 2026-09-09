<?php

declare(strict_types=1);

namespace Official\Mail;

use Cms\Core\Mail\MailMessage;
use Cms\Core\Mail\MailProviderInterface;
use Cms\Core\Mail\MailResult;
use Cms\Core\Mail\SmtpMailProvider;

final class OutlookSmtpMailProvider implements MailProviderInterface
{
    public function id(): string
    {
        return 'official.mail.outlook';
    }

    public function label(): string
    {
        return 'Outlook SMTP';
    }

    public function apiVersion(): string
    {
        return '1.0';
    }

    public function capabilities(): array
    {
        return ['send', 'html', 'plain_text', 'attachments', 'cc', 'bcc', 'reply_to', 'test_connection', 'provider_preset'];
    }

    /** @param array<string,mixed> $config */
    public function send(MailMessage $message, array $config): MailResult
    {
        return $this->relay('send', $message, $config);
    }

    /** @param array<string,mixed> $config */
    public function testConnection(array $config): MailResult
    {
        return $this->relay('test', null, $config);
    }

    /** @param array<string,mixed> $config */
    private function relay(string $action, ?MailMessage $message, array $config): MailResult
    {
        $smtp = new SmtpMailProvider();
        $runtime = $this->presetConfig($config);
        $result = $action === 'send' && $message !== null
            ? $smtp->send($message, $runtime)
            : $smtp->testConnection($runtime);
        if (!$result->success) {
            return MailResult::failure($this->id(), $result->error);
        }

        return MailResult::success($this->id(), $result->messageId);
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    private function presetConfig(array $config): array
    {
        $username = trim((string) ($config['smtp_username'] ?? ''));
        if ($username === '') {
            $username = trim((string) ($config['from_email'] ?? ''));
        }

        $runtime = $config;
        $runtime['smtp_host'] = 'smtp-mail.outlook.com';
        $runtime['smtp_port'] = 587;
        $runtime['smtp_encryption'] = 'tls';
        $runtime['smtp_username'] = $username;

        return $runtime;
    }
}
