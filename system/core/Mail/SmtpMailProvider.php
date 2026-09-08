<?php

declare(strict_types=1);

namespace Cms\Core\Mail;

final class SmtpMailProvider implements MailProviderInterface
{
    public function id(): string
    {
        return 'smtp';
    }

    public function label(): string
    {
        return 'SMTP';
    }

    public function apiVersion(): string
    {
        return '1.0';
    }

    public function capabilities(): array
    {
        return ['send', 'html', 'plain_text', 'attachments', 'cc', 'bcc', 'reply_to', 'test_connection'];
    }

    public function send(MailMessage $message, array $config): MailResult
    {
        try {
            $smtp = $this->connect($config);
            try {
                $from = SiteMailSettingsRepository::addressFromConfig($config);
                $this->command($smtp, 'MAIL FROM:<' . $from->email . '>', [250]);
                foreach (array_merge($message->to, $message->cc, $message->bcc) as $address) {
                    $this->command($smtp, 'RCPT TO:<' . $address->email . '>', [250, 251]);
                }
                $this->command($smtp, 'DATA', [354]);
                fwrite($smtp, $this->formatMessage($message, $from, $config) . "\r\n.\r\n");
                $this->read($smtp, [250]);
                $this->command($smtp, 'QUIT', [221]);
            } finally {
                fclose($smtp);
            }

            return MailResult::success($this->id());
        } catch (\Throwable $exception) {
            return MailResult::failure($this->id(), $exception->getMessage());
        }
    }

    public function testConnection(array $config): MailResult
    {
        try {
            $smtp = $this->connect($config);
            $this->command($smtp, 'QUIT', [221]);
            fclose($smtp);
            return MailResult::success($this->id());
        } catch (\Throwable $exception) {
            return MailResult::failure($this->id(), $exception->getMessage());
        }
    }

    /** @return resource */
    private function connect(array $config)
    {
        $host = (string) ($config['smtp_host'] ?? '');
        $port = (int) ($config['smtp_port'] ?? 587);
        $encryption = strtolower((string) ($config['smtp_encryption'] ?? 'tls'));
        $username = (string) ($config['smtp_username'] ?? '');
        $password = (string) ($config['smtp_password'] ?? '');
        $timeout = max(1, min(120, (int) ($config['timeout_seconds'] ?? 20)));
        if ($host === '' || strlen($host) > 255 || preg_match('/[\r\n\x00-\x1F\x7F]/', $host) === 1) {
            throw new MailException('SMTP host is invalid.');
        }
        if ($port < 1 || $port > 65535) {
            throw new MailException('SMTP port is invalid.');
        }

        $target = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $smtp = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!is_resource($smtp)) {
            throw new MailException('SMTP connection failed: ' . $errstr);
        }
        stream_set_timeout($smtp, $timeout);
        $this->read($smtp, [220]);
        $this->command($smtp, 'EHLO daiying-cms.local', [250]);
        if ($encryption === 'tls') {
            $this->command($smtp, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($smtp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new MailException('SMTP STARTTLS failed.');
            }
            $this->command($smtp, 'EHLO daiying-cms.local', [250]);
        }
        if ($username !== '') {
            $this->command($smtp, 'AUTH LOGIN', [334]);
            $this->command($smtp, base64_encode($username), [334]);
            $this->command($smtp, base64_encode($password), [235]);
        }

        return $smtp;
    }

    /** @param resource $smtp @param list<int> $ok */
    private function command($smtp, string $command, array $ok): string
    {
        fwrite($smtp, $command . "\r\n");
        return $this->read($smtp, $ok);
    }

    /** @param resource $smtp @param list<int> $ok */
    private function read($smtp, array $ok): string
    {
        $response = '';
        do {
            $line = fgets($smtp, 2048);
            if (!is_string($line)) {
                throw new MailException('SMTP response read failed.');
            }
            $response .= $line;
        } while (isset($line[3]) && $line[3] === '-');
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $ok, true)) {
            throw new MailException('SMTP server rejected request: ' . Redactor::redact(trim($response)));
        }

        return $response;
    }

    private function formatMessage(MailMessage $message, MailAddress $from, array $config): string
    {
        $headers = [
            'From: ' . $from->headerValue(),
            'To: ' . implode(', ', array_map(static fn (MailAddress $address): string => $address->headerValue(), $message->to)),
            'Subject: ' . mb_encode_mimeheader($message->subject, 'UTF-8'),
            'MIME-Version: 1.0',
            'Date: ' . date(DATE_RFC2822),
        ];
        if ($message->replyTo !== null) {
            $headers[] = 'Reply-To: ' . $message->replyTo->headerValue();
        } elseif (($config['reply_to'] ?? '') !== '') {
            $headers[] = 'Reply-To: ' . (new MailAddress((string) $config['reply_to']))->headerValue();
        }
        if ($message->cc !== []) {
            $headers[] = 'Cc: ' . implode(', ', array_map(static fn (MailAddress $address): string => $address->headerValue(), $message->cc));
        }
        $body = $message->html !== '' ? $message->html : $message->text;
        if ($message->attachments === []) {
            $headers[] = 'Content-Type: ' . ($message->html !== '' ? 'text/html' : 'text/plain') . '; charset=UTF-8';
            return implode("\r\n", $headers) . "\r\n\r\n" . $this->dotStuff($body);
        }

        $boundary = 'daiying-' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $parts = ["--{$boundary}", 'Content-Type: ' . ($message->html !== '' ? 'text/html' : 'text/plain') . '; charset=UTF-8', '', $this->dotStuff($body)];
        foreach ($message->attachments as $attachment) {
            $parts[] = "--{$boundary}";
            $parts[] = 'Content-Type: ' . $attachment->contentType . '; name="' . addslashes($attachment->filename) . '"';
            $parts[] = 'Content-Transfer-Encoding: base64';
            $parts[] = 'Content-Disposition: attachment; filename="' . addslashes($attachment->filename) . '"';
            $parts[] = '';
            $parts[] = chunk_split(base64_encode($attachment->content));
        }
        $parts[] = "--{$boundary}--";

        return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts);
    }

    private function dotStuff(string $body): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $body);
        $stuffed = preg_replace('/^\./m', '..', $normalized);

        return str_replace("\n", "\r\n", is_string($stuffed) ? $stuffed : $normalized);
    }
}
