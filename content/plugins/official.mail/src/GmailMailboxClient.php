<?php

declare(strict_types=1);

namespace Official\Mail;

use RuntimeException;

final class GmailMailboxClient implements MailboxClientInterface
{
    public function __construct(private readonly MailHttpClient $http, private readonly string $accessToken)
    {
    }

    public function listMessages(string $query = '', int $limit = 25): array
    {
        $params = ['maxResults' => (string) max(1, min($limit, 50)), 'labelIds' => 'INBOX'];
        if ($query !== '') {
            $params['q'] = $query;
        }
        $list = $this->json('GET', 'https://gmail.googleapis.com/gmail/v1/users/me/messages?' . http_build_query($params));
        $messages = [];
        foreach ((array) ($list['messages'] ?? []) as $item) {
            if (!is_array($item) || empty($item['id'])) {
                continue;
            }
            $messages[] = $this->getMessageSummary((string) $item['id']);
        }

        return $messages;
    }

    public function getMessage(string $messageId): array
    {
        $data = $this->json('GET', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . rawurlencode($messageId) . '?format=full');
        $headers = $this->headers((array) (($data['payload'] ?? [])['headers'] ?? []));
        [$bodyText, $bodyHtml, $attachments] = $this->parts((array) ($data['payload'] ?? []));
        $from = $this->parseAddress((string) ($headers['from'] ?? ''));

        return [
            'id' => (string) ($data['id'] ?? $messageId),
            'thread_id' => (string) ($data['threadId'] ?? ''),
            'folder' => 'inbox',
            'sender_name' => $from['name'],
            'sender_email' => $from['email'],
            'to' => (string) ($headers['to'] ?? ''),
            'subject' => (string) ($headers['subject'] ?? ''),
            'snippet' => (string) ($data['snippet'] ?? ''),
            'received_at' => $this->gmailDate((string) ($headers['date'] ?? ''), (int) ($data['internalDate'] ?? 0)),
            'is_read' => !in_array('UNREAD', (array) ($data['labelIds'] ?? []), true),
            'has_attachments' => $attachments !== [],
            'body_text' => $bodyText,
            'body_html' => $bodyHtml,
            'attachments' => $attachments,
        ];
    }

    public function getAttachment(string $messageId, string $attachmentId): array
    {
        $decoded = json_decode(base64_decode($attachmentId, true) ?: '', true);
        $decoded = is_array($decoded) ? $decoded : [];
        $partId = (string) ($decoded['id'] ?? '');
        $filename = (string) ($decoded['filename'] ?? '');
        $mime = (string) ($decoded['mime_type'] ?? '');
        if ($partId === '') {
            throw new RuntimeException('Attachment id is invalid.');
        }
        $data = $this->json('GET', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . rawurlencode($messageId) . '/attachments/' . rawurlencode($partId));
        $content = $this->base64UrlDecode((string) ($data['data'] ?? ''));

        return ['filename' => $filename !== '' ? $filename : 'attachment', 'mime_type' => $mime !== '' ? $mime : 'application/octet-stream', 'content' => $content];
    }

    public function sendMessage(string $to, string $subject, string $body, string $replyToMessageId = ''): string
    {
        $headers = [
            'To: ' . $this->cleanHeader($to),
            'Subject: ' . $this->cleanHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ];
        if ($replyToMessageId !== '') {
            $original = $this->getMessage($replyToMessageId);
            if (($original['thread_id'] ?? '') !== '') {
                $headers[] = 'In-Reply-To: ' . $this->cleanHeader($replyToMessageId);
                $headers[] = 'References: ' . $this->cleanHeader($replyToMessageId);
            }
        }
        $raw = $this->base64UrlEncode(implode("\r\n", $headers) . "\r\n\r\n" . $body);
        $payload = ['raw' => $raw];
        if ($replyToMessageId !== '') {
            $original = $this->getMessage($replyToMessageId);
            if (($original['thread_id'] ?? '') !== '') {
                $payload['threadId'] = (string) $original['thread_id'];
            }
        }
        $response = $this->json('POST', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send', $payload);

        return (string) ($response['id'] ?? '');
    }

    public function markRead(string $messageId, bool $read): void
    {
        $payload = $read ? ['removeLabelIds' => ['UNREAD']] : ['addLabelIds' => ['UNREAD']];
        $this->json('POST', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . rawurlencode($messageId) . '/modify', $payload);
    }

    private function getMessageSummary(string $messageId): array
    {
        $data = $this->json('GET', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . rawurlencode($messageId) . '?format=metadata&metadataHeaders=From&metadataHeaders=Subject&metadataHeaders=Date');
        $headers = $this->headers((array) (($data['payload'] ?? [])['headers'] ?? []));
        $from = $this->parseAddress((string) ($headers['from'] ?? ''));

        return [
            'id' => (string) ($data['id'] ?? $messageId),
            'thread_id' => (string) ($data['threadId'] ?? ''),
            'folder' => 'inbox',
            'sender_name' => $from['name'],
            'sender_email' => $from['email'],
            'subject' => (string) ($headers['subject'] ?? ''),
            'snippet' => (string) ($data['snippet'] ?? ''),
            'received_at' => $this->gmailDate((string) ($headers['date'] ?? ''), (int) ($data['internalDate'] ?? 0)),
            'is_read' => !in_array('UNREAD', (array) ($data['labelIds'] ?? []), true),
            'has_attachments' => in_array('HasAttachment', (array) ($data['labelIds'] ?? []), true),
        ];
    }

    /** @param array<string,mixed>|null $body @return array<string,mixed> */
    private function json(string $method, string $url, ?array $body = null): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->accessToken];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $response = $method === 'POST'
            ? $this->http->post($url, $headers, $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES))
            : $this->http->get($url, $headers);
        $data = json_decode($response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($data)) {
            throw new RuntimeException('Gmail API request failed: HTTP ' . $response['status']);
        }

        return $data;
    }

    /** @param list<array<string,mixed>> $headers @return array<string,string> */
    private function headers(array $headers): array
    {
        $out = [];
        foreach ($headers as $header) {
            $name = strtolower((string) ($header['name'] ?? ''));
            if ($name !== '') {
                $out[$name] = (string) ($header['value'] ?? '');
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $part @return array{0:string,1:string,2:list<array<string,mixed>>} */
    private function parts(array $part): array
    {
        $text = '';
        $html = '';
        $attachments = [];
        $walk = function (array $node) use (&$walk, &$text, &$html, &$attachments): void {
            $mime = (string) ($node['mimeType'] ?? '');
            $filename = (string) ($node['filename'] ?? '');
            $body = is_array($node['body'] ?? null) ? $node['body'] : [];
            if ($filename !== '' && isset($body['attachmentId'])) {
                $attachments[] = [
                    'id' => base64_encode(json_encode([
                        'id' => (string) $body['attachmentId'],
                        'filename' => $filename,
                        'mime_type' => $mime,
                    ], JSON_UNESCAPED_SLASHES) ?: ''),
                    'filename' => $filename,
                    'mime_type' => $mime,
                    'size' => (int) ($body['size'] ?? 0),
                ];
            } elseif (isset($body['data'])) {
                $decoded = $this->base64UrlDecode((string) $body['data']);
                if ($mime === 'text/html') {
                    $html .= $decoded;
                } elseif ($mime === 'text/plain') {
                    $text .= $decoded;
                }
            }
            foreach ((array) ($node['parts'] ?? []) as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($part);

        return [$text, $html, $attachments];
    }

    /** @return array{name:string,email:string} */
    private function parseAddress(string $value): array
    {
        if (preg_match('/^(.*?)<([^>]+)>$/', $value, $match)) {
            return ['name' => trim(trim($match[1]), '" '), 'email' => strtolower(trim($match[2]))];
        }

        return ['name' => '', 'email' => strtolower(trim($value))];
    }

    private function gmailDate(string $date, int $internalDate): string
    {
        $timestamp = $date !== '' ? strtotime($date) : false;
        if ($timestamp === false && $internalDate > 0) {
            $timestamp = (int) floor($internalDate / 1000);
        }

        return $timestamp !== false ? gmdate('c', $timestamp) : '';
    }

    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : '';
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function cleanHeader(string $value): string
    {
        return trim(preg_replace('/[\r\n\x00-\x1F\x7F]+/', ' ', $value) ?? '');
    }
}
