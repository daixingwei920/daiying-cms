<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

use RuntimeException;

final class AiException extends RuntimeException
{
    public function __construct(string $message, private readonly string $reason = 'ai_error')
    {
        parent::__construct($message);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
