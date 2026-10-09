<?php

declare(strict_types=1);

namespace Cms\Core\Content;

final class ContentPublishedEvent
{
    public function __construct(
        public readonly int $contentId,
        public readonly string $contentType,
        public readonly string $title,
        public readonly string $slug,
        public readonly string $publicPath,
        public readonly string $publicUrl,
        public readonly string $publishedAt,
        public readonly string $trigger,
        public readonly ?string $actorType = null,
        public readonly ?int $actorId = null,
        public readonly ?string $taskId = null,
    ) {
    }
    /** Same transition contract as the admin publisher; repeated edits do not republish. */
    public static function notify(ContentRepository $repo, int $id, ?\Cms\Core\Events\EventDispatcher $events,
        \Cms\Core\Config\Settings $settings, string $trigger, ?array $before = null,
        ?string $actorType = null, ?int $actorId = null, ?string $taskId = null): void
    {
        if ($events === null) { return; }
        $content = $repo->find($id);
        if ($content === null || $content['status'] !== 'published') { return; }
        if ($before !== null && $before['status'] === 'published'
            && $before['slug'] === $content['slug'] && $before['content_type'] === $content['content_type']) { return; }
        $slug = (string) $content['slug'];
        if (!preg_match('/^[\p{L}\p{N}-]+$/u', $slug)) { return; }
        $path = match ($content['content_type']) {
            'article' => '/articles/' . rawurlencode($slug), 'page' => '/' . rawurlencode($slug), default => '',
        };
        $origin = rtrim((string) $settings->get('site.url', ''), '/');
        $base = \Cms\Core\Routing\BasePath::fromSettings($settings);
        if ($base !== '' && rtrim((string) parse_url($origin, PHP_URL_PATH), '/') === '') { $origin .= $base; }
        if ($path === '' || !filter_var($origin, FILTER_VALIDATE_URL)) { return; }
        $taskId = is_string($taskId) && preg_match('/^[A-Za-z0-9_.:-]{1,96}$/D', $taskId) ? $taskId : null;
        try {
            $events->dispatch(new self($id, $content['content_type'], $content['title'], $slug, $path,
                $origin . $path, (string) $content['published_at'], $trigger, $actorType, $actorId, $taskId));
        } catch (\Throwable) {
            $repo->auditWrite($id, $actorType ?? 'system', $actorId, 'content.event_dispatch_failed');
        }
    }

}
