<?php

declare(strict_types=1);

namespace Official\Mail;

use RuntimeException;

final class OutlookMailboxClient implements MailboxClientInterface
{
    public function __construct(private readonly MailHttpClient $http, private readonly string $accessToken)
    {
    }

    public function listMessages(string $query = '', int $limit = 25): array
    {
        $params = [
            '$top' => (string) max(1, min($limit, 50)),
            '$select' => 'id,conversationId,subject,bodyPreview,from,receivedDateTime,isRead,hasAttachments',
        ];
        if ($query !== '') {
            $params['$search'] = '"' . str_replace('"', '', $query) . '"';
        } else {
            $params['$orderby'] = 'receivedDateTime desc';
        }
        $data = $this->json('GET', 'https://graph.microsoft.com/v1.0/me/mailFolders/Inbox/messages?' . http_build_query($params));

        return array_map(fn (array $item): array => $this->normalize($item), array_values(array_filter((array) ($data['value'] ?? []), 'is_array')));
    }

    public function getMessage(string $messageId): array
    {
        $data = $this->json('GET', 'https://graph.microsoft.com/v1.0/me/messages/' . rawurlencode($messageId) . '?$select=id,conversationId,subject,bodyPreview,from,toRecipients,receivedDateTime,isRead,hasAttachments,body');
        $message = $this->normalize($data);
        $message['to'] = implode(', ', array_map(static fn (array $row): string => (string) (($row['emailAddress'] ?? [])['address'] ?? ''), array_values(array_filter((array) ($data['toRecipients'] ?? []), 'is_array'))));
        $message['body_html'] = (string) (($data['body'] ?? [])['content'] ?? '');
        $message['body_text'] = strip_tags($message['body_html']);
        $message['attachments'] = $this->attachments($messageId);

        return $message;
    }

    public function getAttachment(string $messageId, string $attachmentId): array
    {
        $data = $this->json('GET', 'https://graph.microsoft.com/v1.0/me/messages/' . rawurlencode($messageId) . '/attachments/' . rawurlencode($attachmentId));
        $content = base64_decode((string) ($data['contentBytes'] ?? ''), true);

        return [
            'filename' => (string) ($data['name'] ?? 'attachment'),
            'mime_type' => (string) ($data['contentType'] ?? 'application/octet-stream'),
            'content' => is_string($content) ? $content : '',
        ];
    }

    public function sendMessage(string $to, string $subject, string $body, string $replyToMessageId = ''): string
    {
        if ($replyToMessageId !== '') {
            $this->json('POST', 'https://graph.microsoft.com/v1.0/me/messages/' . rawurlencode($replyToMessageId) . '/reply', [
                'comment' => $body,
            ], false);

            return '';
        }
        $this->json('POST', 'https://graph.microsoft.com/v1.0/me/sendMail', [
            'message' => [
                'subject' => $this->cleanHeader($subject),
                'body' => ['contentType' => 'Text', 'content' => $body],
                'toRecipients' => [['emailAddress' => ['address' => $to]]],
            ],
            'saveToSentItems' => true,
        ], false);

        return '';
    }

    public function markRead(string $messageId, bool $read): void
    {
        $this->json('PATCH', 'https://graph.microsoft.com/v1.0/me/messages/' . rawurlencode($messageId), ['isRead' => $read]);
    }

    /** @param array<string,mixed>|null $body @return array<string,mixed> */
    private function json(string $method, string $url, ?array $body = null, bool $expectJson = true): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->accessToken, 'Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response = match ($method) {
            'POST' => $this->http->post($url, $headers, $payload),
            'PATCH' => $this->http->patch($url, $headers, $payload),
            default => $this->http->get($url, $headers),
        };
        if ($response['status'] >= 200 && $response['status'] < 300 && !$expectJson) {
            return [];
        }
        $data = json_decode($response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($data)) {
            throw new RuntimeException('Outlook API request failed: HTTP ' . $response['status']);
        }

        return $data;
    }

    /** @return list<array<string,mixed>> */
    private function attachments(string $messageId): array
    {
        $data = $this->json('GET', 'https://graph.microsoft.com/v1.0/me/messages/' . rawurlencode($messageId) . '/attachments?$select=id,name,contentType,size,isInline');
        $attachments = [];
        foreach ((array) ($data['value'] ?? []) as $item) {
            if (!is_array($item) || !empty($item['isInline'])) {
                continue;
            }
            $attachments[] = [
                'id' => (string) ($item['id'] ?? ''),
                'filename' => (string) ($item['name'] ?? 'attachment'),
                'mime_type' => (string) ($item['contentType'] ?? 'application/octet-stream'),
                'size' => (int) ($item['size'] ?? 0),
            ];
        }

        return $attachments;
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function normalize(array $item): array
    {
        $from = is_array($item['from'] ?? null) ? $item['from'] : [];
        $address = is_array($from['emailAddress'] ?? null) ? $from['emailAddress'] : [];

        return [
            'id' => (string) ($item['id'] ?? ''),
            'thread_id' => (string) ($item['conversationId'] ?? ''),
            'folder' => 'inbox',
            'sender_name' => (string) ($address['name'] ?? ''),
            'sender_email' => strtolower((string) ($address['address'] ?? '')),
            'subject' => (string) ($item['subject'] ?? ''),
            'snippet' => (string) ($item['bodyPreview'] ?? ''),
            'received_at' => (string) ($item['receivedDateTime'] ?? ''),
            'is_read' => !empty($item['isRead']),
            'has_attachments' => !empty($item['hasAttachments']),
        ];
    }

    private function cleanHeader(string $value): string
    {
        return trim(preg_replace('/[\r\n\x00-\x1F\x7F]+/', ' ', $value) ?? '');
    }
}
