<?php

declare(strict_types=1);

namespace Official\Seo\BaiduSubmit;

interface BaiduUrlSubmissionTransportInterface
{
    /** @param list<string> $urls */
    public function submit(string $siteUrl, string $token, array $urls, int $timeoutSeconds): BaiduUrlSubmissionResult;
}
