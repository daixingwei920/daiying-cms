<?php

declare(strict_types=1);

namespace Official\Mail;

use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Mail\MailProviderRegistry;
use Cms\Core\Support\View;

final class MailController
{
    public function adminIndex(Request $request): Response
    {
        $rows = '';
        foreach (MailProviderRegistry::all() as $provider) {
            $rows .= '<tr><td>' . $this->e($provider->label()) . '</td><td><code>' . $this->e($provider->id()) . '</code></td><td>' . $this->e(implode(', ', $provider->capabilities())) . '</td><td>' . $this->e($provider->apiVersion()) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">暂无邮件 Provider。</td></tr>';
        }

        return Response::html(View::page('邮件 Provider', '<h1>邮件 Provider</h1><p>official.mail 已接入 Core Mail API。站点发信配置、测试发送和默认 Provider 选择统一在 Core 邮件设置中完成。Gmail/Outlook 当前支持官方 SMTP 发信路径，完整 OAuth Webmail 管理器后续再接。</p><p><a class="button" href="/admin/settings/mail">打开 Core 邮件设置</a></p><table><tr><th>Provider</th><th>ID</th><th>能力</th><th>API</th></tr>' . $rows . '</table>'));
    }

    private function e(string $value): string
    {
        return View::escape($value);
    }
}
