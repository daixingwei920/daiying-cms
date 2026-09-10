<?php

declare(strict_types=1);

namespace Cms\Core\Payment;

interface PaymentProviderSettingsSchemaInterface
{
    /** @return list<array<string,mixed>> */
    public function settingsSchema(): array;

    /** @return array<string,mixed> */
    public function help(): array;

    /** @return array<string,mixed> */
    public function webhookMetadata(): array;
}
