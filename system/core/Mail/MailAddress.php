<?php

declare(strict_types=1);

namespace Cms\Core\Mail;

final class MailAddress
{
    public function __construct(
        public readonly string $email,
        public readonly string $name = '',
    ) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new MailException('Mail address is invalid.');
        }
        if ($name !== '' && (strlen($name) > 191 || preg_match('/[\r\n\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $name) === 1)) {
            throw new MailException('Mail address name is invalid.');
        }
    }

    public function headerValue(): string
    {
        if ($this->name === '') {
            return $this->email;
        }

        return mb_encode_mimeheader($this->name, 'UTF-8') . ' <' . $this->email . '>';
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((string) ($data['email'] ?? ''), (string) ($data['name'] ?? ''));
    }

    /** @return array{email:string,name:string} */
    public function toArray(): array
    {
        return ['email' => $this->email, 'name' => $this->name];
    }
}
