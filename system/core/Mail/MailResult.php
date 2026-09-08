<?php

declare(strict_types=1);

namespace Cms\Core\Mail;

final class MailResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $providerId,
        public readonly string $messageId = '',
        public readonly string $error = '',
    ) {
    }

    public static function success(string $providerId, string $messageId = ''): self
    {
        return new self(true, $providerId, $messageId);
    }

    public static function failure(string $providerId, string $error): self
    {
        return new self(false, $providerId, '', Redactor::redact($error));
    }

    /** @return array{success:bool,provider_id:string,message_id:string,error:string} */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'provider_id' => $this->providerId,
            'message_id' => $this->messageId,
            'error' => $this->error,
        ];
    }
}
