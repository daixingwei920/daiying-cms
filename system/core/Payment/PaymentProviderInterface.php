<?php

declare(strict_types=1);

namespace Cms\Core\Payment;

interface PaymentProviderInterface
{
    public function providerId(): string;

    public function displayName(): string;

    /** @return list<string> */
    public function capabilities(): array;

    public function createPayment(object $command): PaymentResult;

    public function capturePayment(object $command): PaymentResult;

    public function cancelPayment(object $command): PaymentResult;

    public function refundPayment(object $command): PaymentResult;

    public function getPaymentStatus(object $command): PaymentResult;
}
