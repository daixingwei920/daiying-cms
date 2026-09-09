<?php

declare(strict_types=1);

namespace Official\Mail;

interface MailboxClientInterface
{
    /** @return list<array<string,mixed>> */
    public function listMessages(string $query = '', int $limit = 25): array;

    /** @return array<string,mixed> */
    public function getMessage(string $messageId): array;

    /** @return array{filename:string,mime_type:string,content:string} */
    public function getAttachment(string $messageId, string $attachmentId): array;

    public function sendMessage(string $to, string $subject, string $body, string $replyToMessageId = ''): string;

    public function markRead(string $messageId, bool $read): void;
}
