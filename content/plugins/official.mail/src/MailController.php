<?php

declare(strict_types=1);

namespace Official\Mail;

use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Support\View;
use Throwable;

final class MailController
{
    public function __construct(
        private readonly MailRepository $repository,
        private readonly MailService $service,
    ) {
    }

    public function adminSettings(Request $request): Response
    {
        $settings = $this->repository->settings();
        $notice = match ((string) ($request->query['saved'] ?? '')) {
            '1' => '<p class="notice">邮件设置已保存。</p>',
            default => '',
        };
        if ((string) ($request->query['tested'] ?? '') === '1') {
            $notice .= '<p class="notice">测试邮件已发送。</p>';
        }
        $lastTest = trim((string) ($settings['last_test_status'] ?? '')) !== ''
            ? '<p class="muted">上次测试：' . $this->e((string) $settings['last_test_status']) . ' · ' . $this->e((string) ($settings['last_tested_at'] ?? '')) . ' · ' . $this->e((string) ($settings['last_test_message'] ?? '')) . '</p>'
            : '<p class="muted">尚未测试连接。</p>';
        $passwordHint = !empty($settings['password_configured'])
            ? '已配置：' . $this->e((string) $settings['password_masked']) . '，留空则保留'
            : '服务端加密保存';

        $form = '<form method="post" action="/admin/mail/save">' . CsrfToken::field() .
            '<label>启用状态<select name="status">' . $this->options(['disabled' => '停用', 'enabled' => '启用'], (string) ($settings['status'] ?? 'disabled')) . '</select></label>' .
            '<label>SMTP Host<input name="host" value="' . $this->e((string) ($settings['host'] ?? '')) . '" placeholder="smtp.example.com"></label>' .
            '<label>端口<input name="port" type="number" min="1" max="65535" value="' . (int) ($settings['port'] ?? 587) . '"></label>' .
            '<label>加密方式<select name="encryption">' . $this->options(['starttls' => 'STARTTLS', 'ssl' => 'SSL/TLS', 'tls' => 'TLS 直连', 'none' => '无加密'], (string) ($settings['encryption'] ?? 'starttls')) . '</select></label>' .
            '<label>认证方式<select name="auth_mode">' . $this->options(['auto' => '自动/LOGIN', 'login' => 'LOGIN', 'plain' => 'PLAIN', 'none' => '不认证'], (string) ($settings['auth_mode'] ?? 'auto')) . '</select></label>' .
            '<label>SMTP 用户名<input name="username" value="' . $this->e((string) ($settings['username'] ?? '')) . '" autocomplete="username"></label>' .
            '<label>SMTP 密码<input name="password" type="password" autocomplete="new-password" placeholder="' . $passwordHint . '"></label>' .
            '<label>发件邮箱<input name="from_email" type="email" value="' . $this->e((string) ($settings['from_email'] ?? '')) . '"></label>' .
            '<label>发件名称<input name="from_name" value="' . $this->e((string) ($settings['from_name'] ?? '')) . '" placeholder="Daiying CMS"></label>' .
            '<label>回复邮箱<input name="reply_to" type="email" value="' . $this->e((string) ($settings['reply_to'] ?? '')) . '"></label>' .
            '<label>超时秒数<input name="timeout_seconds" type="number" min="3" max="60" value="' . (int) ($settings['timeout_seconds'] ?? 10) . '"></label>' .
            '<button type="submit">保存设置</button></form>';

        $test = '<form method="post" action="/admin/mail/test">' . CsrfToken::field() .
            '<label>测试收件邮箱<input name="to_email" type="email" required></label>' .
            '<button type="submit">发送测试邮件</button></form>';

        return Response::html(View::page('邮件设置', '<h1>邮件设置</h1>' . $notice . '<p>配置站点 SMTP 发信能力，密码只在服务端加密保存，不会显示在页面和发送日志中。</p>' . $lastTest . '<h2>SMTP 配置</h2>' . $form . '<h2>测试发送</h2>' . $test . '<h2>最近邮件</h2>' . $this->recentMessagesTable()));
    }

    public function adminSave(Request $request): Response
    {
        try {
            $this->repository->saveSettings($request->body);
        } catch (Throwable $exception) {
            return $this->error('邮件设置保存失败', $exception, '/admin/mail');
        }

        return Response::redirect('/admin/mail?saved=1');
    }

    public function adminTest(Request $request): Response
    {
        try {
            $this->service->test((string) ($request->body['to_email'] ?? ''));
        } catch (Throwable $exception) {
            return $this->error('测试邮件发送失败', $exception, '/admin/mail');
        }

        return Response::redirect('/admin/mail?tested=1');
    }

    private function recentMessagesTable(): string
    {
        $rows = '';
        foreach ($this->repository->recentMessages() as $message) {
            $error = trim((string) ($message['error_message'] ?? ''));
            $rows .= '<tr><td>' . $this->e((string) ($message['created_at'] ?? '')) . '</td><td>' . $this->e((string) ($message['recipient_email'] ?? '')) . '</td><td>' . $this->e((string) ($message['subject'] ?? '')) . '</td><td>' . $this->e((string) ($message['status'] ?? '')) . '</td><td>' . $this->e($error) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5">暂无邮件记录。</td></tr>';
        }

        return '<table><tr><th>时间</th><th>收件人</th><th>标题</th><th>状态</th><th>错误</th></tr>' . $rows . '</table>';
    }

    /** @param array<string,string> $items */
    private function options(array $items, string $selected): string
    {
        $html = '';
        foreach ($items as $value => $label) {
            $html .= '<option value="' . $this->e($value) . '"' . ($selected === $value ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }

        return $html;
    }

    private function error(string $title, Throwable $exception, string $back): Response
    {
        return Response::html(View::page($title, '<h1>' . $this->e($title) . '</h1><p>' . $this->e($this->repository->redact($exception->getMessage())) . '</p><p><a class="button" href="' . $this->e($back) . '">返回</a></p>'), 400);
    }

    private function e(string $value): string
    {
        return View::escape($value);
    }
}
