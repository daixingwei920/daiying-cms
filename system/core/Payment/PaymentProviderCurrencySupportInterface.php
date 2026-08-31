<?php

declare(strict_types=1);

namespace Cms\Core\Payment;

interface PaymentProviderCurrencySupportInterface
{
    /** @return list<string> */
    public function supportedCurrencies(): array;
}
