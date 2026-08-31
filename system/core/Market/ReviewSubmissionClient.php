<?php

declare(strict_types=1);

namespace Cms\Core\Market;

final class ReviewSubmissionClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiToken = '',
    ) {
    }

    /** @param array<string,string> $fields @param array{name:string,tmp_name:string,type?:string,size?:int,error?:int} $file */
    public function submit(array $fields, array $file): array
    {
        $this->requireCurl();
        $url = rtrim($this->baseUrl, '/') . '/api/v1/review/submissions';
        $payload = $fields;
        $payload['package'] = new \CURLFile($file['tmp_name'], $file['type'] ?? 'application/zip', $file['name']);

        return $this->request('POST', $url, $payload);
    }

    /** @return array<string,mixed> */
    public function status(string $submissionId): array
    {
        return $this->request('GET', rtrim($this->baseUrl, '/') . '/api/v1/review/submissions/' . rawurlencode($submissionId) . '/status');
    }

    /** @return array<string,mixed> */
    public function report(string $submissionId): array
    {
        return $this->request('GET', rtrim($this->baseUrl, '/') . '/api/v1/review/submissions/' . rawurlencode($submissionId) . '/report');
    }

    /** @param array<string,mixed>|null $payload @return array<string,mixed> */
    private function request(string $method, string $url, ?array $payload = null): array
    {
        $this->requireCurl();
        $curl = curl_init($url);
        if ($curl === false) {
            throw new MarketException('无法初始化审核提交请求。');
        }
        $headers = ['Accept: application/json'];
        if ($this->apiToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiToken;
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);
        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $payload ?? []);
        }
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        $error = curl_error($curl);

        if (!is_string($body)) {
            throw new MarketException('官方审核服务连接失败：' . ($error !== '' ? $error : 'unknown'));
        }
        $json = $this->normalizeJsonBody($body);
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            $prefix = $status === 201
                ? '提交可能已经成功创建，但官方服务器响应格式异常，请前往“我的提交”确认。'
                : '官方审核服务返回了无效 JSON。';
            throw new MarketException(
                $prefix . ' HTTP ' . $status .
                '，Content-Type: ' . ($contentType !== '' ? $contentType : 'unknown') .
                '，JSON 错误: ' . json_last_error_msg() .
                '，响应片段: ' . $this->bodySnippet($body)
            );
        }
        if ($status < 200 || $status >= 300) {
            $message = (string) ($decoded['error'] ?? $decoded['message'] ?? ('HTTP ' . $status));
            throw new MarketException('官方审核服务拒绝提交：' . $message);
        }

        return $decoded;
    }

    private function normalizeJsonBody(string $body): string
    {
        $body = ltrim($body);
        if (str_starts_with($body, "\xEF\xBB\xBF")) {
            $body = substr($body, 3);
        }

        return $body;
    }

    private function bodySnippet(string $body): string
    {
        $body = trim(preg_replace('/\s+/', ' ', $body) ?? $body);
        if ($body === '') {
            return '[empty]';
        }

        return substr($body, 0, 300);
    }

    private function requireCurl(): void
    {
        if (!extension_loaded('curl') || !class_exists(\CURLFile::class)) {
            throw new MarketException('服务器 PHP cURL 扩展不可用，无法提交官方审核。');
        }
    }
}
