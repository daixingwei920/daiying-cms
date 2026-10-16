<?php
declare(strict_types=1);
namespace Cms\Core\Payment;
/** Hint only: consumers must re-read the public trusted snapshot. */
final class PaymentDisputeChangedEvent {
 public function __construct(public readonly int $paymentId,public readonly string $providerId){}
}
