<?php

declare(strict_types=1);

namespace Cms\Core\MarketServer;

final class ReviewStateMachine
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        ReviewState::SUBMITTED => [ReviewState::SCANNING, ReviewState::REJECTED, ReviewState::WITHDRAWN],
        ReviewState::SCANNING => [ReviewState::AI_REVIEW_PENDING, ReviewState::NEEDS_REVIEW, ReviewState::REJECTED, ReviewState::WITHDRAWN],
        ReviewState::AI_REVIEW_PENDING => [ReviewState::AI_REVIEW_RUNNING, ReviewState::AI_REVIEW_FAILED, ReviewState::CODEX_REVIEW_PENDING, ReviewState::NEEDS_REVIEW, ReviewState::MANUAL_REVIEW, ReviewState::NEEDS_FIX, ReviewState::NEEDS_SECURITY_REVIEW, ReviewState::WITHDRAWN],
        ReviewState::AI_REVIEW_RUNNING => [ReviewState::AI_REVIEW_FAILED, ReviewState::CODEX_REVIEW_PENDING, ReviewState::NEEDS_REVIEW, ReviewState::MANUAL_REVIEW, ReviewState::NEEDS_FIX, ReviewState::NEEDS_SECURITY_REVIEW, ReviewState::WITHDRAWN],
        ReviewState::AI_REVIEW_FAILED => [ReviewState::AI_REVIEW_PENDING, ReviewState::MANUAL_REVIEW, ReviewState::NEEDS_REVIEW, ReviewState::WITHDRAWN],
        ReviewState::CODEX_REVIEW_PENDING => [ReviewState::CODEX_REVIEW_RUNNING, ReviewState::CODEX_REVIEW_FAILED, ReviewState::NEEDS_REVIEW, ReviewState::MANUAL_REVIEW, ReviewState::NEEDS_FIX, ReviewState::NEEDS_SECURITY_REVIEW, ReviewState::WITHDRAWN],
        ReviewState::CODEX_REVIEW_RUNNING => [ReviewState::CODEX_REVIEW_FAILED, ReviewState::NEEDS_REVIEW, ReviewState::MANUAL_REVIEW, ReviewState::NEEDS_FIX, ReviewState::NEEDS_SECURITY_REVIEW, ReviewState::WITHDRAWN],
        ReviewState::CODEX_REVIEW_FAILED => [ReviewState::CODEX_REVIEW_PENDING, ReviewState::MANUAL_REVIEW, ReviewState::NEEDS_REVIEW, ReviewState::WITHDRAWN],
        ReviewState::NEEDS_REVIEW => [ReviewState::AI_REVIEW_PENDING, ReviewState::MANUAL_REVIEW, ReviewState::APPROVED, ReviewState::REJECTED, ReviewState::NEEDS_FIX, ReviewState::WITHDRAWN],
        ReviewState::MANUAL_REVIEW => [ReviewState::APPROVED, ReviewState::REJECTED, ReviewState::NEEDS_FIX, ReviewState::NEEDS_SECURITY_REVIEW, ReviewState::WITHDRAWN],
        ReviewState::NEEDS_FIX => [ReviewState::REJECTED, ReviewState::WITHDRAWN],
        ReviewState::NEEDS_SECURITY_REVIEW => [ReviewState::APPROVED, ReviewState::REJECTED, ReviewState::NEEDS_FIX, ReviewState::WITHDRAWN],
        ReviewState::APPROVED => [ReviewState::PUBLISHED, ReviewState::REJECTED, ReviewState::WITHDRAWN],
        ReviewState::REJECTED => [ReviewState::NEEDS_SECURITY_REVIEW],
        ReviewState::PUBLISHED => [ReviewState::DEPRECATED, ReviewState::UNPUBLISHED],
        ReviewState::DEPRECATED => [ReviewState::UNPUBLISHED],
        ReviewState::WITHDRAWN => [],
        ReviewState::UNPUBLISHED => [],
    ];

    public function assertCanTransition(string $from, string $to): void
    {
        if (!in_array($from, ReviewState::ALL, true) || !in_array($to, ReviewState::ALL, true)) {
            throw new MarketServerException('Unknown review state.');
        }

        if (!in_array($to, self::TRANSITIONS[$from], true)) {
            throw new MarketServerException('Invalid review transition: ' . $from . ' to ' . $to);
        }
    }
}
