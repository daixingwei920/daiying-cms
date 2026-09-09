<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

final class GeminiProviderClient implements AiProviderClientInterface
{
    /** @var null|\Closure(string,list<string>,string,int):array{body:string,headers:list<string>} */
    private ?\Closure $transport;

    /** @param null|\Closure(string,list<string>,string,int):array{body:string,headers:list<string>} $transport */
    public function __construct(?\Closure $transport = null)
    {
        $this->transport = $transport;
    }

    /**
     * @param list<array{role:string,content:string}> $messages
     * @param array<string,mixed> $config
     * @return array{provider:string,model:string,content:string,raw?:array<string,mixed>}
     */
    public function chat(array $messages, array $config): array
    {
        $provider = (string) ($config['provider'] ?? 'gemini');
        $apiKey = (string) ($config['api_key'] ?? '');
        $model = trim((string) ($config['model'] ?? ''));
        $modelPath = $this->modelPath($model);
        $baseUrl = $this->baseUrl((string) ($config['base_url'] ?? ''));
        if ($apiKey === '') {
            throw new AiException('AI API Key is not configured.', 'api_key_missing');
        }
        if ($modelPath === '') {
            throw new AiException('AI model is not configured.', 'model_missing');
        }

        $payload = $this->payload($messages, $config);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new AiException('AI request payload is invalid.', 'request_invalid');
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        $timeout = max(1, min(120, (int) ($config['timeout_seconds'] ?? 30)));
        $url = $baseUrl . '/' . $modelPath . ':generateContent?key=' . rawurlencode($apiKey);
        $responseHeaders = [];
        $body = $this->post($url, $headers, $json, $timeout, $responseHeaders);
        $status = $this->httpStatus($responseHeaders);
        if ($status < 200 || $status >= 300) {
            throw new AiException($this->safeHttpError($status, $body), $this->httpReason($status));
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AiException('AI provider returned invalid JSON.', 'response_invalid');
        }
        if (!is_array($decoded)) {
            throw new AiException('AI provider returned an invalid response.', 'response_invalid');
        }
        $parts = $decoded['candidates'][0]['content']['parts'] ?? [];
        $content = '';
        if (is_array($parts)) {
            foreach ($parts as $part) {
                if (is_array($part) && is_string($part['text'] ?? null)) {
                    $content .= (string) $part['text'];
                }
            }
        }
        if ($content === '') {
            throw new AiException('AI provider returned an empty response.', 'response_empty');
        }

        $model = str_starts_with($model, 'models/') ? substr($model, 7) : $model;

        return [
            'provider' => $provider,
            'model' => $model,
            'content' => $content,
            'raw' => [
                'usage' => is_array($decoded['usageMetadata'] ?? null) ? $decoded['usageMetadata'] : [],
            ],
        ];
    }

    /**
     * @param list<array{role:string,content:string}> $messages
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function payload(array $messages, array $config): array
    {
        $contents = [];
        $systemParts = [];
        foreach ($messages as $message) {
            $role = (string) $message['role'];
            $content = (string) $message['content'];
            if ($role === 'system') {
                $systemParts[] = ['text' => $content];
                continue;
            }
            $contents[] = [
                'role' => $role === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $content]],
            ];
        }
        if ($contents === [] && $systemParts !== []) {
            $contents[] = ['role' => 'user', 'parts' => [['text' => 'OK']]];
        }

        $payload = ['contents' => $contents];
        if ($systemParts !== []) {
            $payload['systemInstruction'] = ['parts' => $systemParts];
        }
        $generationConfig = [];
        $maxTokens = (int) ($config['max_tokens'] ?? 0);
        if ($maxTokens > 0) {
            $generationConfig['maxOutputTokens'] = min($maxTokens, 200000);
        }
        if (array_key_exists('temperature', $config)) {
            $temperature = (float) $config['temperature'];
            if ($temperature >= 0.0 && $temperature <= 2.0) {
                $generationConfig['temperature'] = $temperature;
            }
        }
        if ($generationConfig !== []) {
            $payload['generationConfig'] = $generationConfig;
        }

        return $payload;
    }

    /** @param list<string> $headers @param list<string> $responseHeaders */
    private function post(string $url, array $headers, string $json, int $timeout, array &$responseHeaders): string
    {
        if ($this->transport !== null) {
            $result = ($this->transport)($url, $headers, $json, $timeout);
            $responseHeaders = array_values(array_map('strval', $result['headers']));

            return (string) $result['body'];
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new AiException('Unable to initialize AI HTTP client.', 'network_error');
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$responseHeaders): int {
                    $responseHeaders[] = trim($header);
                    return strlen($header);
                },
            ]);
            $body = curl_exec($ch);
            if (!is_string($body)) {
                $error = curl_error($ch);
                $this->closeCurl($ch);
                $reason = stripos($error, 'timed out') !== false ? 'timeout' : 'network_error';
                throw new AiException($error !== '' ? 'AI network error: ' . $this->redact($error) : 'AI network request failed.', $reason);
            }
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($status > 0) {
                $responseHeaders[] = 'HTTP/1.1 ' . $status;
            }
            $this->closeCurl($ch);

            return $body;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $json,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $responseHeaders = array_values(array_map('strval', $http_response_header ?? []));
        if (!is_string($body)) {
            throw new AiException('AI network request failed.', 'network_error');
        }

        return $body;
    }

    /** @param list<string> $headers */
    private function httpStatus(array $headers): int
    {
        foreach (array_reverse($headers) as $header) {
            if (preg_match('#^HTTP/\S+\s+([0-9]{3})#i', $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 200;
    }

    private function httpReason(int $status): string
    {
        return match ($status) {
            401, 403 => 'auth_failed',
            404 => 'model_not_found',
            408, 504 => 'timeout',
            429 => 'quota_or_rate_limited',
            default => 'http_error',
        };
    }

    private function safeHttpError(int $status, string $body): string
    {
        if ($status === 401 || $status === 403) {
            return 'AI API Key is invalid or unauthorized. HTTP ' . $status . '.';
        }
        if ($status === 404) {
            return $this->redact('AI model or endpoint was not found. HTTP 404.' . $this->providerMessageSuffix($body));
        }
        if ($status === 429) {
            return 'AI provider quota or rate limit was exceeded. HTTP 429.';
        }
        $message = 'AI provider request failed. HTTP ' . $status . '.';
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $providerMessage = is_array($decoded) ? (string) ($decoded['error']['message'] ?? $decoded['error']['status'] ?? '') : '';
            if ($providerMessage !== '') {
                $message .= ' ' . substr($providerMessage, 0, 160);
            }
        } catch (\JsonException) {
        }

        return $this->redact($message);
    }

    private function providerMessageSuffix(string $body): string
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $providerMessage = is_array($decoded) ? (string) ($decoded['error']['message'] ?? $decoded['error']['status'] ?? '') : '';
            if ($providerMessage !== '') {
                return ' ' . substr($providerMessage, 0, 240);
            }
        } catch (\JsonException) {
        }

        return '';
    }

    private function modelPath(string $model): string
    {
        $model = trim($model);
        if ($model === '') {
            return '';
        }
        $model = ltrim($model, '/');
        if (str_starts_with($model, 'models/')) {
            $model = substr($model, 7);
        }
        if ($model === '' || str_contains($model, '/') || strlen($model) > 191 || preg_match('/[\x00-\x1F\x7F]/', $model) === 1) {
            throw new AiException('AI model is invalid.', 'model_invalid');
        }

        return 'models/' . rawurlencode($model);
    }

    private function baseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            $baseUrl = AiProviderPresets::get('gemini')['base_url'];
        }
        if (strlen($baseUrl) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $baseUrl) === 1) {
            throw new AiException('AI Base URL is invalid.', 'base_url_invalid');
        }
        $parts = parse_url($baseUrl);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new AiException('AI Base URL must be an http or https URL without credentials, query, or fragment.', 'base_url_invalid');
        }

        return $baseUrl;
    }

    private function redact(string $value): string
    {
        return preg_replace('/(?:sk|Bearer|api[_-]?key|key|secret)[A-Za-z0-9_=:.,\/+\-]+/i', '[redacted]', $value) ?: $value;
    }

    /** @param resource|\CurlHandle $ch */
    private function closeCurl($ch): void
    {
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }
    }
}
