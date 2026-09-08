<?php

declare(strict_types=1);

namespace Cms\Core\Mail;

final class MailMessage
{
    /** @param list<MailAddress> $to @param list<MailAddress> $cc @param list<MailAddress> $bcc @param list<MailAttachment> $attachments @param array<string,string|int|float|bool|null> $variables */
    public function __construct(
        public readonly array $to,
        public readonly string $subject,
        public readonly string $html = '',
        public readonly string $text = '',
        public readonly array $cc = [],
        public readonly array $bcc = [],
        public readonly ?MailAddress $replyTo = null,
        public readonly array $attachments = [],
        public readonly string $locale = 'default',
        public readonly string $templateId = '',
        public readonly array $variables = [],
    ) {
        if ($to === []) {
            throw new MailException('Mail message must have at least one recipient.');
        }
        $this->assertAddressList($to);
        $this->assertAddressList($cc);
        $this->assertAddressList($bcc);
        foreach ($attachments as $attachment) {
            if (!$attachment instanceof MailAttachment) {
                throw new MailException('Mail attachment is invalid.');
            }
        }
        if ($subject === '' || strlen($subject) > 998 || preg_match('/[\r\n\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $subject) === 1) {
            throw new MailException('Mail subject is invalid.');
        }
        if ($html === '' && $text === '') {
            throw new MailException('Mail message body is empty.');
        }
        if (strlen($html) > 1048576 || strlen($text) > 1048576) {
            throw new MailException('Mail message body is too large.');
        }
    }

    /** @return self */
    public static function html(string $to, string $subject, string $html, string $text = ''): self
    {
        return new self([new MailAddress($to)], $subject, $html, $text);
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            array_map(static fn (array $row): MailAddress => MailAddress::fromArray($row), (array) ($data['to'] ?? [])),
            (string) ($data['subject'] ?? ''),
            (string) ($data['html'] ?? ''),
            (string) ($data['text'] ?? ''),
            array_map(static fn (array $row): MailAddress => MailAddress::fromArray($row), (array) ($data['cc'] ?? [])),
            array_map(static fn (array $row): MailAddress => MailAddress::fromArray($row), (array) ($data['bcc'] ?? [])),
            is_array($data['reply_to'] ?? null) ? MailAddress::fromArray($data['reply_to']) : null,
            array_map(static fn (array $row): MailAttachment => MailAttachment::fromArray($row), (array) ($data['attachments'] ?? [])),
            (string) ($data['locale'] ?? 'default'),
            (string) ($data['template_id'] ?? ''),
            is_array($data['variables'] ?? null) ? $data['variables'] : [],
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'to' => array_map(static fn (MailAddress $address): array => $address->toArray(), $this->to),
            'cc' => array_map(static fn (MailAddress $address): array => $address->toArray(), $this->cc),
            'bcc' => array_map(static fn (MailAddress $address): array => $address->toArray(), $this->bcc),
            'reply_to' => $this->replyTo?->toArray(),
            'subject' => $this->subject,
            'html' => $this->html,
            'text' => $this->text,
            'attachments' => array_map(static fn (MailAttachment $attachment): array => $attachment->toArray(), $this->attachments),
            'locale' => $this->locale,
            'template_id' => $this->templateId,
            'variables' => $this->variables,
        ];
    }

    private function assertAddressList(array $addresses): void
    {
        foreach ($addresses as $address) {
            if (!$address instanceof MailAddress) {
                throw new MailException('Mail recipient is invalid.');
            }
        }
    }
}
