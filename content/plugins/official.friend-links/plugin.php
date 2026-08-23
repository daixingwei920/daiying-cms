<?php

declare(strict_types=1);

use Cms\Core\Plugin\PluginContext;
use Cms\Core\Audit\AuditLogger;
use Official\FriendLinks\FriendLinkRepository;
use Official\FriendLinks\FriendLinksController;

require_once __DIR__ . '/src/FriendLinkRepository.php';
require_once __DIR__ . '/src/FriendLinksController.php';

return static function (PluginContext $context): void {
    $controller = new FriendLinksController(new FriendLinkRepository($context->pdo()), new AuditLogger($context->pdo()));

    $context->adminRoute('GET', '/admin/friend-links', [$controller, 'adminIndex'], 'friend_links.view', false);
    $context->adminRoute('POST', '/admin/friend-links', [$controller, 'adminSave'], 'friend_links.manage', true);
    $context->frontRoute('GET', '/links', [$controller, 'publicIndex']);
    $context->adminMenu('友情链接', '/admin/friend-links', 'friend_links.view');
};
