<?php

declare(strict_types=1);

namespace Official\Seo\BaiduSubmit;

use Cms\Core\Security\SecretRedactor;

final class BaiduUrlSubmissionHttpClient implements BaiduUrlSubmissionTransportInterface
{
    /** @param list<string> $urls */
    public function submit(string $siteUrl, string $token, array $urls, int $timeoutSeconds): BaiduUrlSubmissionResult
    {
        $endpoint = 'https://data.zz.baidu.com/urls?site=' . rawurlencode($siteUrl) . '&token=' . rawurlencode($token);
        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize Baidu URL submit request.');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => implode("\n", $urls),
            CURLOPT_HTTPHEADER => ['Content-Type: text/plain'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, max(3, $timeoutSeconds)),
            CURLOPT_TIMEOUT => max(5, min(60, $timeoutSeconds)),
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false) {
            return new BaiduUrlSubmissionResult(0, null, null, [], [], [], 'Network error: ' . (string) SecretRedactor::redact($curlError));
        }
        $decoded = json_decode((string) $body, true);
        $raw = is_array($decoded) ? $decoded : ['raw' => substr((string) SecretRedactor::redact((string) $body), 0, 500)];
        $success = isset($raw['success']) && is_numeric($raw['success']) ? (int) $raw['success'] : null;
        $remain = isset($raw['remain']) && is_numeric($raw['remain']) ? (int) $raw['remain'] : null;
        $notSameSite = is_array($raw['not_same_site'] ?? null) ? array_values(array_filter($raw['not_same_site'], 'is_string')) : [];
        $notValid = is_array($raw['not_valid'] ?? null) ? array_values(array_filter($raw['not_valid'], 'is_string')) : [];
        $error = null;
        if ($status < 200 || $status >= 300) {
            $error = is_string($raw['message'] ?? null) ? (string) $raw['message'] : 'Baidu URL Submit returned HTTP ' . $status . '.';
        }

        return new BaiduUrlSubmissionResult($status, $success, $remain, $notSameSite, $notValid, $raw, $error);
    }
}
