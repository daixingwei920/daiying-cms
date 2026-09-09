<?php

declare(strict_types=1);

use Cms\Core\Plugin\PluginContext;
use Official\Mail\MailController;
use Official\Mail\MailRepository;
use Official\Mail\MailService;
use Official\Mail\SmtpTransport;

require_once __DIR__ . '/src/MailRepository.php';
require_once __DIR__ . '/src/SmtpTransport.php';
require_once __DIR__ . '/src/MailService.php';
require_once __DIR__ . '/src/MailController.php';

return static function (PluginContext $context): void {
    $repository = new MailRepository($context->pdo(), $context->secrets());
    $service = new MailService($repository, new SmtpTransport());
    $controller = new MailController($repository, $service);

    $context->adminRoute('GET', '/admin/mail', [$controller, 'adminSettings'], 'mail.manage', false);
    $context->adminRoute('POST', '/admin/mail/save', [$controller, 'adminSave'], 'mail.manage', true);
    $context->adminRoute('POST', '/admin/mail/test', [$controller, 'adminTest'], 'mail.send', true);
    $context->adminMenu('邮件设置', '/admin/mail', 'mail.manage');
};
