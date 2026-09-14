<?php

declare(strict_types=1);

namespace Official\Seo\BaiduSubmit;

use Cms\Core\Queue\QueueJob;
use Cms\Core\Queue\QueueService;
use Cms\Core\Security\SecretRedactor;

final class BaiduUrlSubmissionService
{
    public const QUEUE_JOB_TYPE = 'baidu_url_submission.submit';

    public function __construct(
        private readonly BaiduUrlSubmissionRepository $repo,
        private readonly BaiduUrlSubmissionTransportInterface $transport,
        private readonly QueueService $queue,
    ) {
    }

    public function enqueueUrl(string $url, ?int $contentId = null, ?string $contentType = null): ?int
    {
        $settings = $this->repo->settings();
        if (!$settings['enabled']) {
            return null;
        }
        $normalized = $this->normalizeUrl($url, $settings['site_url']);
        if ($normalized === null || $this->repo->recentlySubmitted($normalized, $settings['dedupe_window_seconds'])) {
            return null;
        }

        return $this->queue->enqueue(new QueueJob(self::QUEUE_JOB_TYPE, [
            'url' => $normalized,
            'content_id' => $contentId,
            'content_type' => $contentType,
        ], BaiduUrlSubmissionRepository::PLUGIN_ID, 3));
    }

    /** @return array{processed:int,succeeded:int,failed:int,dead:int} */
    public function processQueue(int $limit = 10): array
    {
        return $this->queue->process($limit);
    }

    /** @param list<string> $urls @return array{status:string,message:string,http_status:?int,success:?int,remain:?int,not_same_site:list<string>,not_valid:list<string>,submitted:list<string>,deduped:list<string>} */
    public function submitUrls(array $urls, string $trigger = 'manual', ?int $contentId = null, ?string $contentType = null): array
    {
        $settings = $this->repo->settings();
        if (!$settings['enabled']) {
            return $this->summary('disabled', 'Baidu URL submission is disabled.');
        }
        $siteUrl = $this->normalizeSiteUrl($settings['site_url']);
        if ($siteUrl === null) {
            return $this->summary('invalid_site', 'Site URL is not configured or invalid.');
        }
        $token = $this->repo->token();
        if ($token === null || trim($token) === '') {
            return $this->summary('missing_token', 'Baidu API token is not configured.');
        }

        $normalized = [];
        foreach ($urls as $url) {
            if (!is_string($url)) {
                continue;
            }
            $clean = $this->normalizeUrl($url, $siteUrl);
            if ($clean !== null && !in_array($clean, $normalized, true)) {
                $normalized[] = $clean;
            }
        }
        if ($normalized === []) {
            return $this->summary('invalid_url', 'No valid same-site URL was provided.');
        }

        $deduped = [];
        $pending = [];
        foreach ($normalized as $url) {
            if ($this->repo->recentlySubmitted($url, $settings['dedupe_window_seconds'])) {
                $deduped[] = $url;
                $this->repo->record($url, $trigger, 'deduped', null, null, null, [], [], null, 'Skipped by dedupe window.', $contentId, $contentType);
                continue;
            }
            $pending[] = $url;
        }
        if ($pending === []) {
            return [
                'status' => 'deduped',
                'message' => 'All URLs were skipped by the dedupe window.',
                'http_status' => null,
                'success' => null,
                'remain' => null,
                'not_same_site' => [],
                'not_valid' => [],
                'submitted' => [],
                'deduped' => $deduped,
            ];
        }

        try {
            $result = $this->transport->submit($siteUrl, $token, $pending, 20);
        } catch (\Throwable $exception) {
            $message = 'Network error: ' . (string) SecretRedactor::redact($exception->getMessage());
            foreach ($pending as $url) {
                $this->repo->record($url, $trigger, 'failed', null, null, null, [], [], null, $message, $contentId, $contentType);
            }

            return [
                'status' => 'failed',
                'message' => $message,
                'http_status' => null,
                'success' => null,
                'remain' => null,
                'not_same_site' => [],
                'not_valid' => [],
                'submitted' => $pending,
                'deduped' => $deduped,
            ];
        }
        $status = $result->isOk() ? 'submitted' : 'failed';
        if ($result->remain === 0 && $result->success === 0) {
            $status = 'quota_exhausted';
        }
        foreach ($pending as $url) {
            $rowStatus = $status;
            if (in_array($url, $result->notSameSite, true)) {
                $rowStatus = 'not_same_site';
            } elseif (in_array($url, $result->notValid, true)) {
                $rowStatus = 'not_valid';
            } elseif ($result->isOk() && ($result->success ?? 0) > 0) {
                $rowStatus = 'success';
            }
            $this->repo->record($url, $trigger, $rowStatus, $result->httpStatus, $result->success, $result->remain, $result->notSameSite, $result->notValid, $result->raw, $result->errorSummary, $contentId, $contentType);
        }

        return [
            'status' => $status,
            'message' => $result->errorSummary ?? 'Baidu URL Submit responded.',
            'http_status' => $result->httpStatus,
            'success' => $result->success,
            'remain' => $result->remain,
            'not_same_site' => $result->notSameSite,
            'not_valid' => $result->notValid,
            'submitted' => $pending,
            'deduped' => $deduped,
        ];
    }

    private function normalizeSiteUrl(string $siteUrl): ?string
    {
        $siteUrl = rtrim(trim($siteUrl), '/');
        if ($siteUrl === '' || filter_var($siteUrl, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $scheme = strtolower((string) parse_url($siteUrl, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($siteUrl, PHP_URL_HOST));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        return $siteUrl;
    }

    private function normalizeUrl(string $url, string $siteUrl): ?string
    {
        $url = trim($url);
        $site = $this->normalizeSiteUrl($siteUrl);
        if ($url === '' || $site === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $urlScheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $urlHost = strtolower((string) parse_url($url, PHP_URL_HOST));
        $siteHost = strtolower((string) parse_url($site, PHP_URL_HOST));
        if (!in_array($urlScheme, ['http', 'https'], true) || $urlHost === '' || $urlHost !== $siteHost) {
            return null;
        }
        $fragmentless = strtok($url, '#');

        return is_string($fragmentless) ? $fragmentless : $url;
    }

    /** @return array{status:string,message:string,http_status:?int,success:?int,remain:?int,not_same_site:list<string>,not_valid:list<string>,submitted:list<string>,deduped:list<string>} */
    private function summary(string $status, string $message): array
    {
        return [
            'status' => $status,
            'message' => $message,
            'http_status' => null,
            'success' => null,
            'remain' => null,
            'not_same_site' => [],
            'not_valid' => [],
            'submitted' => [],
            'deduped' => [],
        ];
    }
}
