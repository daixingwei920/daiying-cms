<?php

declare(strict_types=1);

namespace Cms\Core\Mail;

interface MailProviderInterface
{
    public function id(): string;

    public function label(): string;

    public function apiVersion(): string;

    /** @return list<string> */
    public function capabilities(): array;

    /** @param array<string,mixed> $config */
    public function send(MailMessage $message, array $config): MailResult;

    /** @param array<string,mixed> $config */
    public function testConnection(array $config): MailResult;
}
