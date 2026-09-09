<?php

declare(strict_types=1);

namespace Official\Mail;

use Cms\Core\Mail\MailAddress;
use Cms\Core\Mail\MailMessage;
use Cms\Core\Mail\MailProviderInterface;
use Cms\Core\Mail\MailResult;
use RuntimeException;

final class OAuthMailboxMailProvider implements MailProviderInterface
{
    public function __construct(
        private readonly string $provider,
        private readonly MailAccountRepository $repository,
        private readonly MailOAuthService $oauth,
        private readonly MailApiClientFactory $factory,
    ) {
        if (!in_array($provider, ['gmail', 'outlook'], true)) {
            throw new RuntimeException('OAuth mail provider is not supported.');
        }
    }

    public function id(): string
    {
        return 'official.mail.oauth.' . $this->provider;
    }

    public function label(): string
    {
        return $this->provider === 'gmail' ? 'Gmail OAuth（邮件中心）' : 'Outlook OAuth（邮件中心）';
    }

    public function apiVersion(): string
    {
        return '1.0';
    }

    public function capabilities(): array
    {
        return ['send', 'html', 'plain_text', 'oauth', 'test_connection', 'single_recipient'];
    }

    public function send(MailMessage $message, array $config): MailResult
    {
        try {
            $this->assertSupportedMessage($message);
            $account = $this->resolveAccount($config);
            $client = $this->factory->forAccount($account);
            $body = $message->text !== '' ? $message->text : $this->htmlToText($message->html);
            $messageId = $client->sendMessage($message->to[0]->headerValue(), $message->subject, $body);

            return MailResult::success($this->id(), $messageId);
        } catch (\Throwable $exception) {
            return MailResult::failure($this->id(), $exception->getMessage());
        }
    }

    public function testConnection(array $config): MailResult
    {
        try {
            $account = $this->resolveAccount($config);
            $token = $this->oauth->accessToken($account);
            if ($token === '') {
                throw new RuntimeException('OAuth account token is unavailable.');
            }

            return MailResult::success($this->id());
        } catch (\Throwable $exception) {
            return MailResult::failure($this->id(), $exception->getMessage());
        }
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    private function resolveAccount(array $config): array
    {
        $fromEmail = strtolower(trim((string) ($config['from_email'] ?? '')));
        if ($fromEmail !== '') {
            $account = $this->repository->connectedAccountForEmail($this->provider, $fromEmail);
            if ($account !== null) {
                return $account;
            }
            throw new RuntimeException('From Email must match a connected ' . ucfirst($this->provider) . ' account.');
        }
        $accounts = $this->repository->connectedAccounts($this->provider);
        if (count($accounts) === 1) {
            return $accounts[0];
        }
        if ($accounts === []) {
            throw new RuntimeException(ucfirst($this->provider) . ' OAuth account is not connected. Please connect it in Mail Center first.');
        }

        throw new RuntimeException('Multiple ' . ucfirst($this->provider) . ' accounts are connected. Set From Email to the account you want to use.');
    }

    private function assertSupportedMessage(MailMessage $message): void
    {
        if (count($message->to) !== 1 || $message->cc !== [] || $message->bcc !== []) {
            throw new RuntimeException('OAuth mail provider currently supports one direct recipient per system email.');
        }
        if ($message->attachments !== []) {
            throw new RuntimeException('OAuth mail provider does not send Core mail attachments yet.');
        }
        if (!$message->to[0] instanceof MailAddress) {
            throw new RuntimeException('Mail recipient is invalid.');
        }
    }

    private function htmlToText(string $html): string
    {
        $text = preg_replace('/<(br|p|div|li|tr|h[1-6])\b[^>]*>/i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
