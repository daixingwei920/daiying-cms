<?php

declare(strict_types=1);

namespace Cms\Core\Mail;

final class PhpMailProvider implements MailProviderInterface
{
    public function id(): string
    {
        return 'php_mail';
    }

    public function label(): string
    {
        return 'PHP mail()';
    }

    public function apiVersion(): string
    {
        return '1.0';
    }

    public function capabilities(): array
    {
        return ['send', 'html', 'plain_text', 'cc', 'bcc', 'reply_to'];
    }

    public function send(MailMessage $message, array $config): MailResult
    {
        if ($message->attachments !== []) {
            return MailResult::failure($this->id(), 'PHP mail() provider does not support attachments.');
        }

        $headers = [];
        $from = SiteMailSettingsRepository::addressFromConfig($config);
        $headers[] = 'From: ' . $from->headerValue();
        if ($message->replyTo !== null) {
            $headers[] = 'Reply-To: ' . $message->replyTo->headerValue();
        } elseif (($config['reply_to'] ?? '') !== '') {
            $headers[] = 'Reply-To: ' . (new MailAddress((string) $config['reply_to']))->headerValue();
        }
        if ($message->cc !== []) {
            $headers[] = 'Cc: ' . implode(', ', array_map(static fn (MailAddress $address): string => $address->headerValue(), $message->cc));
        }
        if ($message->bcc !== []) {
            $headers[] = 'Bcc: ' . implode(', ', array_map(static fn (MailAddress $address): string => $address->headerValue(), $message->bcc));
        }
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: ' . ($message->html !== '' ? 'text/html' : 'text/plain') . '; charset=UTF-8';

        $body = $message->html !== '' ? $message->html : $message->text;
        $to = implode(', ', array_map(static fn (MailAddress $address): string => $address->headerValue(), $message->to));
        $ok = @mail($to, mb_encode_mimeheader($message->subject, 'UTF-8'), $body, implode("\r\n", $headers));

        return $ok ? MailResult::success($this->id()) : MailResult::failure($this->id(), 'PHP mail() returned false.');
    }

    public function testConnection(array $config): MailResult
    {
        return function_exists('mail')
            ? MailResult::success($this->id())
            : MailResult::failure($this->id(), 'PHP mail() is unavailable.');
    }
}
