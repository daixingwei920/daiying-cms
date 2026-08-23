<?php

declare(strict_types=1);

namespace Cms\Core\MarketServer;

final class ReviewState
{
    public const SUBMITTED = 'Submitted';
    public const SCANNING = 'Scanning';
    public const NEEDS_REVIEW = 'NeedsReview';
    public const NEEDS_FIX = 'NeedsFix';
    public const MANUAL_REVIEW = 'ManualReview';
    public const AI_REVIEW_PENDING = 'AI_REVIEW_PENDING';
    public const AI_REVIEW_RUNNING = 'AI_REVIEW_RUNNING';
    public const AI_REVIEW_FAILED = 'AI_REVIEW_FAILED';
    public const CODEX_REVIEW_PENDING = 'CODEX_REVIEW_PENDING';
    public const CODEX_REVIEW_RUNNING = 'CODEX_REVIEW_RUNNING';
    public const CODEX_REVIEW_FAILED = 'CODEX_REVIEW_FAILED';
    public const NEEDS_SECURITY_REVIEW = 'NEEDS_SECURITY_REVIEW';
    public const APPROVED = 'Approved';
    public const REJECTED = 'Rejected';
    public const PUBLISHED = 'Published';
    public const DEPRECATED = 'Deprecated';
    public const WITHDRAWN = 'Withdrawn';
    public const UNPUBLISHED = 'Unpublished';

    public const ALL = [
        self::SUBMITTED,
        self::SCANNING,
        self::NEEDS_REVIEW,
        self::NEEDS_FIX,
        self::MANUAL_REVIEW,
        self::AI_REVIEW_PENDING,
        self::AI_REVIEW_RUNNING,
        self::AI_REVIEW_FAILED,
        self::CODEX_REVIEW_PENDING,
        self::CODEX_REVIEW_RUNNING,
        self::CODEX_REVIEW_FAILED,
        self::NEEDS_SECURITY_REVIEW,
        self::APPROVED,
        self::REJECTED,
        self::PUBLISHED,
        self::DEPRECATED,
        self::WITHDRAWN,
        self::UNPUBLISHED,
    ];
}
