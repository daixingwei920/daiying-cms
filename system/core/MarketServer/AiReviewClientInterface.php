<?php

declare(strict_types=1);

namespace Cms\Core\MarketServer;

interface AiReviewClientInterface
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function review(array $payload): array;
}
