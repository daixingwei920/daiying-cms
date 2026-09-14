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
    ) {
    }
}
