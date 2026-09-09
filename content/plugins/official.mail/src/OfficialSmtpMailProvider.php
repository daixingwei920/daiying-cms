<?php

declare(strict_types=1);

namespace Official\Mail;

use Cms\Core\Mail\MailMessage;
use Cms\Core\Mail\MailProviderInterface;
use Cms\Core\Mail\MailResult;
use Cms\Core\Mail\SmtpMailProvider;

final class OfficialSmtpMailProvider implements MailProviderInterface
{
    public function id(): string
    {
        return 'official.mail.smtp';
    }

    public function label(): string
    {
        return 'Official SMTP';
    }

    public function apiVersion(): string
    {
        return '1.0';
    }

    public function capabilities(): array
    {
        return ['send', 'html', 'plain_text', 'attachments', 'cc', 'bcc', 'reply_to', 'test_connection'];
    }

    /** @param array<string,mixed> $config */
    public function send(MailMessage $message, array $config): MailResult
    {
        $result = (new SmtpMailProvider())->send($message, $config);
        if (!$result->success) {
            return MailResult::failure($this->id(), $result->error);
        }

        return MailResult::success($this->id(), $result->messageId);
    }

    /** @param array<string,mixed> $config */
    public function testConnection(array $config): MailResult
    {
        $result = (new SmtpMailProvider())->testConnection($config);
        if (!$result->success) {
            return MailResult::failure($this->id(), $result->error);
        }

        return MailResult::success($this->id(), $result->messageId);
    }
}
