<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

/** A plugin-owned hint that a site operator may run another patrol. */
final class AiOperatorWakeEvent
{
    public function __construct(
        public readonly string $ownerPlugin,
        public readonly string $eventId,
    ) {
    }
}
