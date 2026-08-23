<?php

declare(strict_types=1);

namespace Cms\Core\MarketServer;

final class AiReviewPolicy
{
    /** @param array<string, mixed> $result */
    public function apply(MarketServerRepository $repository, int $versionId, array $result, int $reviewerId, bool $autoApprove, int $maxFindings = 0): string
    {
        if ($repository->versionStatus($versionId) === ReviewState::NEEDS_REVIEW) {
            $repository->transitionVersion($versionId, ReviewState::MANUAL_REVIEW);
        }

        return 'NeedsManualReview';
    }
}
