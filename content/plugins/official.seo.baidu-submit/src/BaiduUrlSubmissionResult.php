<?php

declare(strict_types=1);

namespace Official\Seo\BaiduSubmit;

final class BaiduUrlSubmissionResult
{
    /** @param list<string> $notSameSite @param list<string> $notValid @param array<string,mixed> $raw */
    public function __construct(
        public readonly int $httpStatus,
        public readonly ?int $success,
        public readonly ?int $remain,
        public readonly array $notSameSite,
        public readonly array $notValid,
        public readonly array $raw,
        public readonly ?string $errorSummary = null,
    ) {
    }

    public function isOk(): bool
    {
        return $this->httpStatus >= 200 && $this->httpStatus < 300 && $this->errorSummary === null;
    }
}
