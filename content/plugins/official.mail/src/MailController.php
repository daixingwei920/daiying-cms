<?php

declare(strict_types=1);

namespace Official\Mail;

use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Mail\MailAddress;
use Cms\Core\Mail\MailMessage;
use Cms\Core\Mail\MailProviderRegistry;
use Cms\Core\Mail\MailService;
use Cms\Core\Notification\NotificationService;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Support\View;
use Throwable;

final class MailController
{
    public function __construct(
        private readonly MailAccountRepository $repository,
        private readonly MailOAuthService $oauth,
        private readonly MailApiClientFactory $factory,
        private readonly ?MailService $coreMail = null,
        private readonly ?NotificationService $notifications = null,
    ) {
    }

    public function adminIndex(Request $request): Response
    {
        $notice = $this->notice($request);
        $html = '<h1>邮件中心</h1><p>连接 Gmail 或 Outlook 后，可以在 Daiying CMS 后台收信、读信、回复和发送邮件。站点通知发信继续复用 Core 邮件设置。</p>' .
            $notice .
            '<h2>邮箱账号</h2>' . $this->accountsTable() .
            '<h2>OAuth Provider</h2>' . $this->oauthForms($request) .
            '<h2>发信 Provider</h2>' . $this->providersTable() .
            '<p><a class="button" href="/admin/settings/mail">打开 Core 邮件设置</a> <a class="button" href="/admin/mail/compose">写邮件</a></p>';

        return Response::html(View::page('邮件中心', $html));
    }

    public function saveOAuthConfig(Request $request): Response
    {
        try {
            $provider = (string) $request->input('provider', '');
            $this->repository->saveOAuthConfig($provider, $request->body);
        } catch (Throwable $exception) {
            return $this->error('OAuth 配置保存失败', $exception, '/admin/mail');
        }

        return Response::redirect('/admin/mail?saved=1');
    }

    public function oauthStart(Request $request): Response
    {
        try {
            $provider = (string) $request->input('provider', '');
            $auth = $this->oauth->authorizationUrl($provider, $this->baseUrl($request));
        } catch (Throwable $exception) {
            return $this->error('OAuth 授权启动失败', $exception, '/admin/mail');
        }

        return Response::redirect($auth['url']);
    }

    public function oauthCallback(Request $request): Response
    {
        try {
            $provider = (string) $request->input('provider', '');
            $code = (string) $request->input('code', '');
            $state = (string) $request->input('state', '');
            if ($code === '') {
                throw new \RuntimeException('OAuth callback did not include an authorization code.');
            }
            $this->oauth->completeAuthorization($provider, $code, $state, $this->baseUrl($request));
        } catch (Throwable $exception) {
            return $this->error('OAuth 授权失败', $exception, '/admin/mail');
        }

        return Response::redirect('/admin/mail?connected=1');
    }

    public function disconnectAccount(Request $request): Response
    {
        $this->repository->disconnectAccount((int) $request->input('account_id', 0));

        return Response::redirect('/admin/mail?disconnected=1');
    }

    public function inbox(Request $request): Response
    {
        try {
            $account = $this->requireAccount((int) $request->input('account_id', 0));
        } catch (Throwable $exception) {
            return $this->error('收件箱打开失败', $exception, '/admin/mail');
        }
        $query = trim((string) $request->input('q', ''));
        $messages = [];
        $warning = '';
        try {
            $messages = $this->factory->forAccount($account)->listMessages($query, 25);
            $createdRemoteIds = $this->repository->cacheMessages((int) $account['id'], $messages);
            $this->notifyNewUnreadMessages($account, $messages, $createdRemoteIds);
        } catch (Throwable $exception) {
            $this->repository->recordAccountError((int) $account['id'], $exception->getMessage());
            $messages = $this->repository->cachedMessages((int) $account['id'], $query, 25);
            $warning = '<p class="notice">邮件 Provider 暂时不可用，正在显示最近缓存的邮件摘要。</p>';
        }

        $rows = '';
        foreach ($messages as $message) {
            $subject = (string) ($message['subject'] ?? '(无主题)');
            $sender = trim((string) ($message['sender_name'] ?? '') . ' ' . (string) ($message['sender_email'] ?? ''));
            $rows .= '<tr><td>' . (!empty($message['is_read']) ? '已读' : '<strong>未读</strong>') . '</td><td>' . $this->e($sender) . '</td><td><a href="/admin/mail/message?account_id=' . (int) $account['id'] . '&message_id=' . rawurlencode((string) ($message['id'] ?? '')) . '">' . $this->e($subject) . '</a><br><span class="muted">' . $this->e((string) ($message['snippet'] ?? '')) . '</span></td><td>' . $this->e((string) ($message['received_at'] ?? '')) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">暂无邮件。</td></tr>';
        }

        $form = '<form method="get" action="/admin/mail/inbox"><input type="hidden" name="account_id" value="' . (int) $account['id'] . '"><label>搜索<input name="q" value="' . $this->e($query) . '" placeholder="发件人、主题或关键词"></label><button type="submit">搜索</button> <a class="button" href="/admin/mail/compose?account_id=' . (int) $account['id'] . '">写邮件</a></form>';
        $html = '<h1>收件箱</h1><p><a href="/admin/mail">返回邮件中心</a></p>' . $warning . '<p><strong>' . $this->e((string) $account['email']) . '</strong> · ' . $this->e((string) $account['provider']) . '</p>' . $form . '<table><tr><th>状态</th><th>发件人</th><th>主题</th><th>时间</th></tr>' . $rows . '</table>';

        return Response::html(View::page('收件箱', $html));
    }

    /** @param array<string,mixed> $account @param list<array<string,mixed>> $messages @param list<string> $createdRemoteIds */
    private function notifyNewUnreadMessages(array $account, array $messages, array $createdRemoteIds): void
    {
        if ($this->notifications === null || $createdRemoteIds === []) {
            return;
        }
        $created = array_fill_keys($createdRemoteIds, true);
        foreach ($messages as $message) {
            $remoteId = (string) ($message['id'] ?? '');
            if ($remoteId === '' || empty($created[$remoteId]) || !empty($message['is_read'])) {
                continue;
            }
            try {
                $subject = trim((string) ($message['subject'] ?? '')) ?: '(无主题)';
                $snippet = trim((string) ($message['snippet'] ?? ''));
                $accountId = (int) ($account['id'] ?? 0);
                $this->notifications->create('新邮件：' . $subject, $snippet, [
                    'source_type' => 'mail',
                    'source_id' => $remoteId,
                    'severity' => 'info',
                    'action_url' => '/admin/mail/message?account_id=' . $accountId . '&message_id=' . rawurlencode($remoteId),
                    'dedupe_key' => 'official.mail:' . $accountId . ':' . sha1($remoteId),
                    'payload' => [
                        'account_id' => $accountId,
                        'remote_message_id' => $remoteId,
                        'sender_email' => (string) ($message['sender_email'] ?? ''),
                        'received_at' => (string) ($message['received_at'] ?? ''),
                        'subject' => $subject,
                        'snippet' => $snippet,
                    ],
                ]);
            } catch (Throwable) {
                continue;
            }
        }
    }

    public function message(Request $request): Response
    {
        try {
            $account = $this->requireAccount((int) $request->input('account_id', 0));
        } catch (Throwable $exception) {
            return $this->error('邮件读取失败', $exception, '/admin/mail');
        }
        $messageId = (string) $request->input('message_id', '');
        try {
            $message = $this->factory->forAccount($account)->getMessage($messageId);
        } catch (Throwable $exception) {
            return $this->error('邮件读取失败', $exception, '/admin/mail/inbox?account_id=' . (int) $account['id']);
        }
        $attachments = '';
        foreach ((array) ($message['attachments'] ?? []) as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            $attachments .= '<li><a href="/admin/mail/attachment?account_id=' . (int) $account['id'] . '&message_id=' . rawurlencode($messageId) . '&attachment_id=' . rawurlencode((string) ($attachment['id'] ?? '')) . '">' . $this->e((string) ($attachment['filename'] ?? 'attachment')) . '</a> <span class="muted">' . $this->e((string) ($attachment['mime_type'] ?? '')) . ' · ' . (int) ($attachment['size'] ?? 0) . ' bytes</span></li>';
        }
        $body = (string) (($message['body_html'] ?? '') ?: nl2br($this->e((string) ($message['body_text'] ?? ''))));
        $readButton = '<form method="post" action="/admin/mail/message/read">' . CsrfToken::field() . '<input type="hidden" name="account_id" value="' . (int) $account['id'] . '"><input type="hidden" name="message_id" value="' . $this->e($messageId) . '"><input type="hidden" name="read" value="' . (!empty($message['is_read']) ? '0' : '1') . '"><button type="submit">' . (!empty($message['is_read']) ? '标记未读' : '标记已读') . '</button></form>';
        $html = '<h1>' . $this->e((string) ($message['subject'] ?? '(无主题)')) . '</h1><p><a href="/admin/mail/inbox?account_id=' . (int) $account['id'] . '">返回收件箱</a></p>' .
            '<p>发件人：' . $this->e(trim((string) ($message['sender_name'] ?? '') . ' ' . (string) ($message['sender_email'] ?? ''))) . '<br>收件人：' . $this->e((string) ($message['to'] ?? '')) . '<br>时间：' . $this->e((string) ($message['received_at'] ?? '')) . '</p>' .
            '<p><a class="button" href="/admin/mail/compose?account_id=' . (int) $account['id'] . '&reply_to=' . rawurlencode($messageId) . '&to=' . rawurlencode((string) ($message['sender_email'] ?? '')) . '&subject=' . rawurlencode('Re: ' . (string) ($message['subject'] ?? '')) . '">回复</a></p>' . $readButton .
            ($attachments !== '' ? '<h2>附件</h2><ul>' . $attachments . '</ul>' : '') .
            '<h2>正文</h2><div class="mail-body">' . $this->safeMailHtml($body) . '</div>';

        return Response::html(View::page('查看邮件', $html));
    }

    public function markRead(Request $request): Response
    {
        $messageId = (string) $request->input('message_id', '');
        try {
            $account = $this->requireAccount((int) $request->input('account_id', 0));
            $this->factory->forAccount($account)->markRead($messageId, (string) $request->input('read', '1') === '1');
        } catch (Throwable $exception) {
            return $this->error('邮件状态更新失败', $exception, '/admin/mail');
        }

        return Response::redirect('/admin/mail/message?account_id=' . (int) $account['id'] . '&message_id=' . rawurlencode($messageId));
    }

    public function downloadAttachment(Request $request): Response
    {
        try {
            $account = $this->requireAccount((int) $request->input('account_id', 0));
            $attachment = $this->factory->forAccount($account)->getAttachment((string) $request->input('message_id', ''), (string) $request->input('attachment_id', ''));
        } catch (Throwable $exception) {
            return $this->error('附件下载失败', $exception, '/admin/mail');
        }
        $filename = preg_replace('/[\r\n"\\\\\/]+/', '_', (string) ($attachment['filename'] ?? 'attachment')) ?: 'attachment';

        return new Response((string) ($attachment['content'] ?? ''), 200, [
            'Content-Type' => (string) ($attachment['mime_type'] ?? 'application/octet-stream'),
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function compose(Request $request): Response
    {
        $accountId = (int) $request->input('account_id', 0);
        $account = $accountId > 0 ? $this->repository->account($accountId) : null;
        $form = '<form method="post" action="/admin/mail/send">' . CsrfToken::field() .
            '<input type="hidden" name="account_id" value="' . (int) ($account['id'] ?? 0) . '">' .
            '<input type="hidden" name="reply_to" value="' . $this->e((string) $request->input('reply_to', '')) . '">' .
            '<label>发信账号<select name="from_account_id">' . $this->accountOptions((int) ($account['id'] ?? 0)) . '</select></label>' .
            '<label>收件人<input type="email" name="to" required value="' . $this->e((string) $request->input('to', '')) . '"></label>' .
            '<label>主题<input name="subject" required value="' . $this->e((string) $request->input('subject', '')) . '"></label>' .
            '<label>正文<textarea name="body" rows="12" required></textarea></label>' .
            '<button type="submit">发送</button></form>';

        return Response::html(View::page('写邮件', '<h1>写邮件</h1><p><a href="/admin/mail">返回邮件中心</a></p>' . $form));
    }

    public function send(Request $request): Response
    {
        $accountId = (int) $request->input('from_account_id', $request->input('account_id', 0));
        $account = $accountId > 0 ? $this->repository->account($accountId) : null;
        try {
            if (is_array($account) && (string) ($account['status'] ?? '') === 'connected' && (string) ($account['provider'] ?? '') !== '') {
                $this->factory->forAccount($account)->sendMessage((string) $request->input('to', ''), (string) $request->input('subject', ''), (string) $request->input('body', ''), (string) $request->input('reply_to', ''));
            } elseif ($accountId > 0) {
                throw new \RuntimeException('Selected mail account is not connected.');
            } elseif ($this->coreMail !== null) {
                $this->coreMail->send(new MailMessage([new MailAddress((string) $request->input('to', ''))], (string) $request->input('subject', ''), '', (string) $request->input('body', '')));
            } else {
                throw new \RuntimeException('No mail account or Core mail service is available.');
            }
        } catch (Throwable $exception) {
            return $this->error('邮件发送失败', $exception, '/admin/mail/compose');
        }

        return Response::redirect('/admin/mail?sent=1');
    }

    private function accountsTable(): string
    {
        $rows = '';
        foreach ($this->repository->accounts() as $account) {
            $rows .= '<tr><td>' . $this->e((string) $account['email']) . '</td><td>' . $this->e((string) $account['provider']) . '</td><td>' . $this->e((string) $account['status']) . '</td><td>' . $this->e((string) ($account['last_sync_at'] ?? '')) . '</td><td><a class="button" href="/admin/mail/inbox?account_id=' . (int) $account['id'] . '">收件箱</a> <a class="button" href="/admin/mail/compose?account_id=' . (int) $account['id'] . '">写信</a> <form method="post" action="/admin/mail/accounts/disconnect" style="display:inline">' . CsrfToken::field() . '<input type="hidden" name="account_id" value="' . (int) $account['id'] . '"><button type="submit">断开</button></form></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5">暂无已连接邮箱。</td></tr>';
        }

        return '<table><tr><th>邮箱</th><th>Provider</th><th>状态</th><th>最近同步</th><th>操作</th></tr>' . $rows . '</table>';
    }

    private function oauthForms(Request $request): string
    {
        $base = $this->baseUrl($request);
        $html = '';
        foreach ($this->repository->providers() as $provider) {
            $config = $this->repository->oauthConfig($provider);
            $callback = rtrim($base, '/') . '/admin/mail/oauth/callback?provider=' . rawurlencode($provider);
            $html .= '<fieldset><legend>' . strtoupper($provider) . '</legend><form method="post" action="/admin/mail/oauth-config">' . CsrfToken::field() .
                '<input type="hidden" name="provider" value="' . $this->e($provider) . '">' .
                '<label>状态<select name="status"><option value="enabled"' . (($config['status'] ?? '') === 'enabled' ? ' selected' : '') . '>启用</option><option value="disabled"' . (($config['status'] ?? '') !== 'enabled' ? ' selected' : '') . '>停用</option></select></label>' .
                '<label>Client ID<input name="client_id" value="' . $this->e((string) ($config['client_id'] ?? '')) . '"></label>' .
                ($provider === 'outlook' ? '<label>Tenant<input name="tenant" value="' . $this->e((string) ($config['tenant'] ?? 'common')) . '" placeholder="common"></label>' : '') .
                '<label>Client Secret<input name="client_secret" type="password" placeholder="' . (!empty($config['client_secret_configured']) ? $this->e((string) $config['client_secret_masked']) : '未配置') . '"></label>' .
                '<label>Redirect URI<input name="redirect_uri" value="' . $this->e((string) (($config['redirect_uri'] ?? '') ?: $callback)) . '"></label>' .
                '<p class="muted">授权范围：' . $this->e(implode(', ', MailOAuthService::defaultScopes($provider))) . '</p>' .
                '<button type="submit">保存</button> <a class="button" href="/admin/mail/oauth/start?provider=' . $this->e($provider) . '">连接 ' . strtoupper($provider) . '</a></form></fieldset>';
        }

        return $html;
    }

    private function providersTable(): string
    {
        $rows = '';
        foreach (MailProviderRegistry::all() as $provider) {
            $rows .= '<tr><td>' . $this->e($provider->label()) . '</td><td><code>' . $this->e($provider->id()) . '</code></td><td>' . $this->e(implode(', ', $provider->capabilities())) . '</td><td>' . $this->e($provider->apiVersion()) . '</td></tr>';
        }

        return '<table><tr><th>Provider</th><th>ID</th><th>能力</th><th>API</th></tr>' . $rows . '</table>';
    }

    private function accountOptions(int $selected): string
    {
        $html = '<option value="0">使用 Core 默认发信</option>';
        foreach ($this->repository->accounts() as $account) {
            if ((string) ($account['status'] ?? '') !== 'connected') {
                continue;
            }
            $id = (int) $account['id'];
            $html .= '<option value="' . $id . '"' . ($selected === $id ? ' selected' : '') . '>' . $this->e((string) $account['email'] . ' · ' . (string) $account['provider']) . '</option>';
        }

        return $html;
    }

    /** @return array<string,mixed> */
    private function requireAccount(int $id): array
    {
        $account = $this->repository->account($id);
        if ($account === null || (string) ($account['status'] ?? '') === 'disconnected') {
            throw new \RuntimeException('Mail account is not available.');
        }

        return $account;
    }

    private function notice(Request $request): string
    {
        foreach (['saved' => '设置已保存。', 'connected' => '邮箱已连接。', 'disconnected' => '邮箱已断开。', 'sent' => '邮件已发送。'] as $key => $message) {
            if ((string) $request->input($key, '') === '1') {
                return '<p class="notice">' . $this->e($message) . '</p>';
            }
        }

        return '';
    }

    private function error(string $title, Throwable $exception, string $back): Response
    {
        return Response::html(View::page($title, '<h1>' . $this->e($title) . '</h1><p>' . $this->e($this->redact($exception->getMessage())) . '</p><p><a class="button" href="' . $this->e($back) . '">返回</a></p>'), 400);
    }

    private function baseUrl(Request $request): string
    {
        $proto = (string) ($request->server['HTTP_X_FORWARDED_PROTO'] ?? '');
        $scheme = $proto !== '' ? $proto : ((string) ($request->server['HTTPS'] ?? '') !== '' && (string) $request->server['HTTPS'] !== 'off' ? 'https' : 'http');
        $host = (string) ($request->server['HTTP_HOST'] ?? 'www.daiyingcms.com');

        return $scheme . '://' . $host;
    }

    private function safeMailHtml(string $html): string
    {
        $html = preg_replace('/<\s*(script|style|iframe|object|embed|form|input|button)[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $html) ?? '';
        $html = preg_replace('/\s+on[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is', '', $html) ?? '';
        $html = preg_replace('/(href|src)\s*=\s*([\'"])\s*javascript:.*?\2/is', '$1="#"', $html) ?? '';

        return $html;
    }

    private function redact(string $message): string
    {
        return preg_replace('/(access_token|refresh_token|client_secret|Authorization|Bearer)\s*[=:]\s*[^&\s]+/i', '$1=[redacted]', $message) ?? '邮件操作失败。';
    }

    private function e(string $value): string
    {
        return View::escape($value);
    }
}
