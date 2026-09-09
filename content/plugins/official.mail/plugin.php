<?php

declare(strict_types=1);

use Cms\Core\Plugin\PluginContext;
use Official\Mail\GmailSmtpMailProvider;
use Official\Mail\MailController;
use Official\Mail\OfficialSmtpMailProvider;
use Official\Mail\OutlookSmtpMailProvider;

require_once __DIR__ . '/src/GmailSmtpMailProvider.php';
require_once __DIR__ . '/src/OfficialSmtpMailProvider.php';
require_once __DIR__ . '/src/OutlookSmtpMailProvider.php';
require_once __DIR__ . '/src/MailController.php';

return static function (PluginContext $context): void {
    if (method_exists($context, 'registerMailProvider')) {
        $context->registerMailProvider(new OfficialSmtpMailProvider());
        $context->registerMailProvider(new GmailSmtpMailProvider());
        $context->registerMailProvider(new OutlookSmtpMailProvider());
    }
    if (method_exists($context, 'registerMailEvent')) {
        $context->registerMailEvent('official.mail.test', '邮件测试', ['site_name', 'admin_email']);
    }

    $controller = new MailController();
    $context->adminRoute('GET', '/admin/mail', [$controller, 'adminIndex'], 'mail.manage', false);
    $context->adminMenu('邮件设置', '/admin/mail', 'mail.manage');
};
