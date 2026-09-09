<?php

declare(strict_types=1);

namespace Official\Mail;

use RuntimeException;

interface MailTransport
{
    /** @param array<string,mixed> $settings @param array<string,mixed> $message */
    public function send(array $settings, string $password, array $message): string;
}

final class SmtpTransport implements MailTransport
{
    /** @param array<string,mixed> $settings @param array<string,mixed> $message */
    public function send(array $settings, string $password, array $message): string
    {
        $host = trim((string) ($settings['host'] ?? ''));
        $port = (int) ($settings['port'] ?? 587);
        $timeout = max(3, min(60, (int) ($settings['timeout_seconds'] ?? 10)));
        $encryption = (string) ($settings['encryption'] ?? 'starttls');
        if ($host === '' || $port < 1 || $port > 65535) {
            throw new RuntimeException('SMTP Host 或端口未配置。');
        }

        $remote = ($encryption === 'ssl' || $encryption === 'tls' ? 'tls://' : 'tcp://') . $host . ':' . $port;
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!is_resource($stream)) {
            throw new RuntimeException('SMTP 连接失败：' . $this->safeError($errstr !== '' ? $errstr : (string) $errno));
        }
        stream_set_timeout($stream, $timeout);

        try {
            $this->expect($stream, [220]);
            $this->command($stream, 'EHLO daiying-cms.local', [250]);
            if ($encryption === 'starttls') {
                $this->command($stream, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('SMTP STARTTLS 握手失败。');
                }
                $this->command($stream, 'EHLO daiying-cms.local', [250]);
            }

            $username = (string) ($settings['username'] ?? '');
            $authMode = (string) ($settings['auth_mode'] ?? 'auto');
            if ($authMode !== 'none' && $username !== '') {
                $this->authenticate($stream, $username, $password, $authMode);
            }

            $from = (string) ($settings['from_email'] ?? '');
            $to = (string) ($message['to_email'] ?? '');
            $this->command($stream, 'MAIL FROM:<' . $this->mailbox($from) . '>', [250]);
            $this->command($stream, 'RCPT TO:<' . $this->mailbox($to) . '>', [250, 251]);
            $this->command($stream, 'DATA', [354]);
            $this->write($stream, $this->rfc822($settings, $message) . "\r\n.");
            $response = $this->expect($stream, [250]);
            $this->command($stream, 'QUIT', [221, 250]);

            return $this->messageIdFromResponse($response);
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    private function authenticate($stream, string $username, string $password, string $authMode): void
    {
        if ($password === '') {
            throw new RuntimeException('SMTP 密码未配置。');
        }
        $mode = $authMode === 'plain' ? 'plain' : 'login';
        if ($mode === 'plain') {
            $payload = base64_encode("\0" . $username . "\0" . $password);
            $this->command($stream, 'AUTH PLAIN ' . $payload, [235]);
            return;
        }
        $this->command($stream, 'AUTH LOGIN', [334]);
        $this->command($stream, base64_encode($username), [334], false);
        $this->command($stream, base64_encode($password), [235], false);
    }

    /** @param resource $stream @param list<int> $codes */
    private function command($stream, string $command, array $codes, bool $redact = true): string
    {
        $this->write($stream, $command);
        try {
            return $this->expect($stream, $codes);
        } catch (RuntimeException $exception) {
            $label = $redact ? $command : '[redacted smtp credential]';
            throw new RuntimeException('SMTP 命令失败：' . $this->safeError($label) . '，' . $exception->getMessage());
        }
    }

    /** @param resource $stream */
    private function write($stream, string $line): void
    {
        fwrite($stream, $line . "\r\n");
    }

    /** @param resource $stream @param list<int> $codes */
    private function expect($stream, array $codes): string
    {
        $response = '';
        do {
            $line = fgets($stream, 2048);
            if (!is_string($line)) {
                throw new RuntimeException('SMTP 响应读取失败。');
            }
            $response .= $line;
            $continue = isset($line[3]) && $line[3] === '-';
        } while ($continue);

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('SMTP 返回异常：' . $this->safeError(trim($response)));
        }

        return $response;
    }

    /** @param array<string,mixed> $settings @param array<string,mixed> $message */
    private function rfc822(array $settings, array $message): string
    {
        $fromEmail = $this->mailbox((string) ($settings['from_email'] ?? ''));
        $fromName = $this->headerValue((string) ($settings['from_name'] ?? ''));
        $toEmail = $this->mailbox((string) ($message['to_email'] ?? ''));
        $toName = $this->headerValue((string) ($message['to_name'] ?? ''));
        $subject = $this->encodedHeader($this->headerValue((string) ($message['subject'] ?? '')));
        $bodyText = str_replace(["\r\n", "\r"], "\n", (string) ($message['body_text'] ?? ''));
        $bodyText = str_replace("\n", "\r\n", $bodyText);
        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s O'),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@daiying-cms.local>',
            'From: ' . ($fromName !== '' ? $this->encodedHeader($fromName) . ' ' : '') . '<' . $fromEmail . '>',
            'To: ' . ($toName !== '' ? $this->encodedHeader($toName) . ' ' : '') . '<' . $toEmail . '>',
            'Subject: ' . $subject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        $replyTo = trim((string) ($settings['reply_to'] ?? ''));
        if ($replyTo !== '') {
            $headers[] = 'Reply-To: <' . $this->mailbox($replyTo) . '>';
        }

        return implode("\r\n", $headers) . "\r\n\r\n" . $this->dotStuff($bodyText);
    }

    private function dotStuff(string $body): string
    {
        return preg_replace('/^\./m', '..', $body) ?: $body;
    }

    private function mailbox(string $email): string
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('邮件地址格式无效。');
        }

        return $email;
    }

    private function headerValue(string $value): string
    {
        return trim(preg_replace('/[\r\n]+/', ' ', $value) ?: '');
    }

    private function encodedHeader(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('/^[\\x20-\\x7E]+$/', $value) === 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function messageIdFromResponse(string $response): string
    {
        if (preg_match('/\b(?:queued as|id)\s+([A-Za-z0-9._-]+)/i', $response, $m) === 1) {
            return $m[1];
        }

        return '';
    }

    private function safeError(string $value): string
    {
        return preg_replace('/(?:bearer\s+|smtp[_-]?pass(?:word)?=|password=|api[_-]?key=|secret=|token=|authorization=)[^\s"\']*/i', '$1[redacted]', $value) ?: $value;
    }
}
