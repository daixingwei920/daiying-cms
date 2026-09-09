<?php

declare(strict_types=1);

namespace Official\Mail;

use RuntimeException;
use Throwable;

final class MailService
{
    public function __construct(
        private readonly MailRepository $repository,
        private readonly MailTransport $transport,
    ) {
    }

    /** @param array<string,mixed> $message @return array{status:string,message_id:int,provider_message_id:string} */
    public function send(array $message): array
    {
        $settings = $this->repository->settings();
        if ((string) ($settings['status'] ?? 'disabled') !== 'enabled') {
            throw new RuntimeException('邮件发送未启用。');
        }
        $this->validateMessage($message);
        $id = $this->repository->createMessage($message + ['status' => 'sending']);
        try {
            $providerMessageId = $this->transport->send($settings, $this->repository->smtpPassword(), $message);
            $this->repository->markMessageSent($id, $providerMessageId);

            return ['status' => 'sent', 'message_id' => $id, 'provider_message_id' => $providerMessageId];
        } catch (Throwable $exception) {
            $this->repository->markMessageFailed($id, $exception->getMessage());
            throw new RuntimeException($this->repository->redact($exception->getMessage()), 0, $exception);
        }
    }

    /** @return array{status:string,message:string} */
    public function test(string $recipient): array
    {
        $recipient = strtolower(trim($recipient));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('测试收件邮箱格式无效。');
        }
        try {
            $this->send([
                'to_email' => $recipient,
                'to_name' => 'Daiying Mail Test',
                'subject' => 'Daiying CMS 邮件发送测试',
                'body_text' => "这是一封 Daiying CMS official.mail 测试邮件。\n如果你收到它，说明 SMTP 配置可以正常发送。",
            ]);
            $this->repository->recordTest('success', '测试邮件发送成功。');

            return ['status' => 'success', 'message' => '测试邮件发送成功。'];
        } catch (Throwable $exception) {
            $message = $this->repository->redact($exception->getMessage());
            $this->repository->recordTest('failed', $message);
            throw new RuntimeException($message, 0, $exception);
        }
    }

    /** @param array<string,mixed> $message */
    private function validateMessage(array $message): void
    {
        $to = strtolower(trim((string) ($message['to_email'] ?? '')));
        $subject = trim((string) ($message['subject'] ?? ''));
        $body = trim((string) ($message['body_text'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('收件邮箱格式无效。');
        }
        if ($subject === '' || preg_match('/[\r\n]/', $subject) === 1) {
            throw new RuntimeException('邮件标题不能为空且不能包含换行。');
        }
        if ($body === '') {
            throw new RuntimeException('邮件正文不能为空。');
        }
    }
}
