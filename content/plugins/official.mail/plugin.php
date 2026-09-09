<?php

declare(strict_types=1);

use Cms\Core\Plugin\PluginContext;
use Official\Mail\GmailSmtpMailProvider;
use Official\Mail\MailAccountRepository;
use Official\Mail\MailApiClientFactory;
use Official\Mail\MailController;
use Official\Mail\MailOAuthService;
use Official\Mail\OfficialSmtpMailProvider;
use Official\Mail\OutlookSmtpMailProvider;

require_once __DIR__ . '/src/GmailSmtpMailProvider.php';
require_once __DIR__ . '/src/MailAccountRepository.php';
require_once __DIR__ . '/src/MailHttpClient.php';
require_once __DIR__ . '/src/MailOAuthService.php';
require_once __DIR__ . '/src/MailboxClientInterface.php';
require_once __DIR__ . '/src/GmailMailboxClient.php';
require_once __DIR__ . '/src/MailApiClientFactory.php';
require_once __DIR__ . '/src/OfficialSmtpMailProvider.php';
require_once __DIR__ . '/src/OutlookMailboxClient.php';
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

    $repository = new MailAccountRepository($context->pdo(), $context->secrets());
    $http = new Official\Mail\MailHttpClient();
    $oauth = new MailOAuthService($repository, $http);
    $factory = new MailApiClientFactory($repository, $oauth, $http);
    $controller = new MailController($repository, $oauth, $factory, method_exists($context, 'mail') ? $context->mail() : null);
    $context->adminRoute('GET', '/admin/mail', [$controller, 'adminIndex'], 'mail.manage', false);
    $context->adminRoute('POST', '/admin/mail/oauth-config', [$controller, 'saveOAuthConfig'], 'mail.manage', true);
    $context->adminRoute('GET', '/admin/mail/oauth/start', [$controller, 'oauthStart'], 'mail.oauth', false);
    $context->adminRoute('GET', '/admin/mail/oauth/callback', [$controller, 'oauthCallback'], 'mail.oauth', false);
    $context->adminRoute('POST', '/admin/mail/accounts/disconnect', [$controller, 'disconnectAccount'], 'mail.manage', true);
    $context->adminRoute('GET', '/admin/mail/inbox', [$controller, 'inbox'], 'mail.read', false);
    $context->adminRoute('GET', '/admin/mail/message', [$controller, 'message'], 'mail.read', false);
    $context->adminRoute('POST', '/admin/mail/message/read', [$controller, 'markRead'], 'mail.read', true);
    $context->adminRoute('GET', '/admin/mail/attachment', [$controller, 'downloadAttachment'], 'mail.read', false);
    $context->adminRoute('GET', '/admin/mail/compose', [$controller, 'compose'], 'mail.send', false);
    $context->adminRoute('POST', '/admin/mail/send', [$controller, 'send'], 'mail.send', true);
    $context->adminMenu('邮件中心', '/admin/mail', 'mail.manage');
};
