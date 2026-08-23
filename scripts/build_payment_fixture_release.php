<?php

declare(strict_types=1);

fwrite(
    STDERR,
    "Payment Fixture plugin packaging is retired. Payment is a CMS Core foundation; use core.fixture-payment only through payment.fixture_provider_enabled in deterministic tests.\n"
);
exit(2);
