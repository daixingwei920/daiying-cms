<?php

declare(strict_types=1);

use Cms\Core\Content\ContentPublishedEvent;
use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Plugin\PluginContext;
use Cms\Core\Security\CsrfToken;

return static function (PluginContext $context): void {
    $pluginId = $context->manifest->id;

    $context->adminRoute('GET', '/admin/sdk-skeleton', static function (Request $request) use ($context, $pluginId): Response {
        $masked = $context->secrets()->masked($pluginId, 'api_token') ?? '';
        $html = '<h1>SDK Skeleton</h1>'
            . '<form method="post" action="/admin/sdk-skeleton/save">'
            . CsrfToken::field()
            . '<label>API Token <input type="password" name="api_token" placeholder="' . htmlspecialchars($masked, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"></label>'
            . '<button type="submit">Save</button>'
            . '</form>';

        return Response::html($html);
    }, 'settings.write', false);

    $context->adminRoute('POST', '/admin/sdk-skeleton/save', static function (Request $request) use ($context, $pluginId): Response {
        $token = trim((string) ($request->body['api_token'] ?? ''));
        if ($token !== '') {
            $context->secrets()->set($pluginId, 'api_token', $token);
        }
        $context->data()->put('settings', 'last_save', ['saved_at' => gmdate('c')]);

        return Response::redirect('/admin/sdk-skeleton');
    }, 'settings.write', true);

    $context->frontRoute('POST', '/sdk-skeleton/webhook', static function (Request $request): Response {
        $raw = $request->rawBody();

        return Response::json([
            'ok' => true,
            'bytes' => strlen($raw),
        ]);
    }, null, false);

    $context->frontRoute('GET', '/sdk-skeleton/ping', static fn (): Response => Response::json(['ok' => true]), null, false);

    $context->registerScheduledTask('official.sdk_skeleton.heartbeat', 3600, static function (array $payload) use ($context): void {
        $context->data()->put('scheduler', 'heartbeat', ['ran_at' => gmdate('c'), 'payload' => $payload]);
    }, ['source' => 'skeleton']);

    $context->listen(ContentPublishedEvent::class, static function (object $event) use ($context): void {
        if (!$event instanceof ContentPublishedEvent) {
            return;
        }
        $context->data()->put('events', 'content_published_' . $event->contentId, [
            'content_id' => $event->contentId,
            'title' => $event->title,
            'seen_at' => gmdate('c'),
        ]);
    });

    $context->registerBlock('sdk_skeleton_card', 'SDK Skeleton Card');
    $context->registerBlockRenderer('sdk_skeleton_card', static function (array $block, array $renderContext): string {
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];
        $title = htmlspecialchars((string) ($data['title'] ?? 'SDK Skeleton'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $body = htmlspecialchars((string) ($data['body'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<section class="sdk-skeleton-card"><h2>' . $title . '</h2>' . ($body !== '' ? '<p>' . $body . '</p>' : '') . '</section>';
    });

    $context->adminRoute('POST', '/admin/sdk-skeleton/draft', static function (Request $request) use ($context): Response {
        $draft = $context->content()->createDraft([
            'type' => 'article',
            'title' => trim((string) ($request->body['title'] ?? 'SDK Skeleton Draft')),
            'blocks' => [
                ['type' => 'paragraph', 'data' => ['text' => 'Created through the public ContentService.']],
            ],
        ]);

        return Response::json(['ok' => true, 'content_id' => (int) $draft['id']]);
    }, 'content.write', true);

    $context->frontRoute('GET', '/sdk-skeleton/me', static function () use ($context): Response {
        $user = $context->frontUsers()->current();

        return Response::json(['user' => $user]);
    }, 'auth.read', false);
};
