<?php

declare(strict_types=1);

namespace Cms\Core\Comment;

final class CommentCreatedEvent
{
    public function __construct(
        public readonly int $commentId,
        public readonly int $contentId,
        public readonly ?int $authorUserId,
        public readonly string $authorName,
        public readonly ?string $authorEmail,
        public readonly string $status,
        public readonly string $createdAt,
    ) {
    }
}
