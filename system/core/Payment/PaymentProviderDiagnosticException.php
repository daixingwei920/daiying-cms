<?php

declare(strict_types=1);

namespace Cms\Core\Payment;

use RuntimeException;
use Throwable;

final class PaymentProviderDiagnosticException extends RuntimeException
{
    /** @param array<string,mixed> $diagnostic */
    public function __construct(string $message, private readonly array $diagnostic = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** @return array<string,mixed> */
    public function diagnostic(): array
    {
        return $this->diagnostic;
    }
}
