<?php

declare(strict_types=1);

namespace Official\Mail;

use RuntimeException;

final class MailApiClientFactory
{
    public function __construct(
        private readonly MailAccountRepository $repository,
        private readonly MailOAuthService $oauth,
        private readonly MailHttpClient $http,
    ) {
    }

    /** @param array<string,mixed> $account */
    public function forAccount(array $account): MailboxClientInterface
    {
        $token = $this->oauth->accessToken($account);
        return match ((string) ($account['provider'] ?? '')) {
            'gmail' => new GmailMailboxClient($this->http, $token),
            'outlook' => new OutlookMailboxClient($this->http, $token),
            default => throw new RuntimeException('Unsupported mail account provider.'),
        };
    }
}
