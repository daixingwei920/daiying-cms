<?php

declare(strict_types=1);

use Cms\Core\Content\ContentPublishedEvent;
use Cms\Core\Plugin\PluginContext;
use Official\Seo\BaiduSubmit\BaiduUrlSubmissionController;
use Official\Seo\BaiduSubmit\BaiduUrlSubmissionHttpClient;
use Official\Seo\BaiduSubmit\BaiduUrlSubmissionRepository;
use Official\Seo\BaiduSubmit\BaiduUrlSubmissionService;

require_once __DIR__ . '/src/BaiduUrlSubmissionResult.php';
require_once __DIR__ . '/src/BaiduUrlSubmissionTransportInterface.php';
require_once __DIR__ . '/src/BaiduUrlSubmissionHttpClient.php';
require_once __DIR__ . '/src/BaiduUrlSubmissionRepository.php';
require_once __DIR__ . '/src/BaiduUrlSubmissionService.php';
require_once __DIR__ . '/src/BaiduUrlSubmissionController.php';

return static function (PluginContext $context): void {
    $repo = new BaiduUrlSubmissionRepository($context->pdo(), $context->secrets());
    $service = new BaiduUrlSubmissionService($repo, new BaiduUrlSubmissionHttpClient(), $context->queue());
    $controller = new BaiduUrlSubmissionController($repo, $service);

    $context->adminRoute('GET', '/admin/seo/baidu-submit', [$controller, 'index'], 'seo.manage', false);
    $context->adminRoute('POST', '/admin/seo/baidu-submit/save', [$controller, 'save'], 'seo.manage', true);
    $context->adminRoute('POST', '/admin/seo/baidu-submit/submit', [$controller, 'submit'], 'seo.submit', true);
    $context->adminRoute('POST', '/admin/seo/baidu-submit/process-queue', [$controller, 'processQueue'], 'seo.submit', true);
    $context->adminMenu('百度推送', '/admin/seo/baidu-submit', 'seo.manage', ['section' => '平台']);

    $context->registerQueueHandler(BaiduUrlSubmissionService::QUEUE_JOB_TYPE, function (array $payload) use ($service): void {
        $url = is_string($payload['url'] ?? null) ? $payload['url'] : '';
        if ($url === '') {
            return;
        }
        $service->submitUrls([$url], 'queue', isset($payload['content_id']) ? (int) $payload['content_id'] : null, is_string($payload['content_type'] ?? null) ? $payload['content_type'] : null);
    });

    $context->listen(ContentPublishedEvent::class, function (object $event) use ($service): void {
        if (!$event instanceof ContentPublishedEvent || $event->publicUrl === '') {
            return;
        }
        $service->enqueueUrl($event->publicUrl, $event->contentId, $event->contentType);
    });
};
