<?php

declare(strict_types=1);

namespace Cms\Core\Mail;

final class MailAttachment
{
    public function __construct(
        public readonly string $filename,
        public readonly string $content,
        public readonly string $contentType = 'application/octet-stream',
    ) {
        if ($filename === '' || strlen($filename) > 191 || preg_match('/[\r\n\x00-\x1F\x7F]/', $filename) === 1 || str_contains($filename, '/') || str_contains($filename, '\\')) {
            throw new MailException('Mail attachment filename is invalid.');
        }
        if ($contentType === '' || strlen($contentType) > 120 || preg_match('/[\r\n\x00-\x1F\x7F]/', $contentType) === 1) {
            throw new MailException('Mail attachment content type is invalid.');
        }
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $content = base64_decode((string) ($data['content_base64'] ?? ''), true);
        if (!is_string($content)) {
            throw new MailException('Mail attachment content is invalid.');
        }

        return new self(
            (string) ($data['filename'] ?? ''),
            $content,
            (string) ($data['content_type'] ?? 'application/octet-stream'),
        );
    }

    /** @return array{filename:string,content_base64:string,content_type:string} */
    public function toArray(): array
    {
        return [
            'filename' => $this->filename,
            'content_base64' => base64_encode($this->content),
            'content_type' => $this->contentType,
        ];
    }
}
