<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

final class OpenAiCompatibleProviderClient implements AiProviderClientInterface
{
    /**
     * @param list<array{role:string,content:string}> $messages
     * @param array<string,mixed> $config
     * @return array{provider:string,model:string,content:string,raw?:array<string,mixed>}
     */
    public function chat(array $messages, array $config): array
    {
        $provider = (string) ($config['provider'] ?? 'openai_compatible');
        $apiKey = (string) ($config['api_key'] ?? '');
        $model = trim((string) ($config['model'] ?? ''));
        $baseUrl = $this->baseUrl($provider, (string) ($config['base_url'] ?? ''));
        if ($apiKey === '') {
            throw new AiException('AI API Key is not configured.', 'api_key_missing');
        }
        if ($model === '') {
            throw new AiException('AI model is not configured.', 'model_missing');
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
        ];
        $maxTokens = (int) ($config['max_tokens'] ?? 0);
        if ($maxTokens > 0) {
            $payload['max_tokens'] = min($maxTokens, 200000);
        }
        if (array_key_exists('temperature', $config)) {
            $temperature = (float) $config['temperature'];
            if ($temperature >= 0.0 && $temperature <= 2.0) {
                $payload['temperature'] = $temperature;
            }
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new AiException('AI request payload is invalid.', 'request_invalid');
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];
        $timeout = max(1, min(120, (int) ($config['timeout_seconds'] ?? 30)));
        $responseHeaders = [];
        $body = $this->post($baseUrl . '/chat/completions', $headers, $json, $timeout, $responseHeaders);
        $status = $this->httpStatus($responseHeaders);
        if ($status < 200 || $status >= 300) {
            throw new AiException($this->safeHttpError($status, $body), $status === 401 || $status === 403 ? 'auth_failed' : 'http_error');
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AiException('AI provider returned invalid JSON.', 'response_invalid');
        }
        if (!is_array($decoded)) {
            throw new AiException('AI provider returned an invalid response.', 'response_invalid');
        }
        $content = (string) ($decoded['choices'][0]['message']['content'] ?? '');
        if ($content === '') {
            throw new AiException('AI provider returned an empty response.', 'response_empty');
        }

        return [
            'provider' => $provider,
            'model' => $model,
            'content' => $content,
            'raw' => [
                'id' => (string) ($decoded['id'] ?? ''),
                'usage' => is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [],
            ],
        ];
    }

    /** @param list<string> $headers @param list<string> $responseHeaders */
    private function post(string $url, array $headers, string $json, int $timeout, array &$responseHeaders): string
    {
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
                curl_close($ch);
                throw new AiException($error !== '' ? 'AI network error: ' . $error : 'AI network request failed.', 'network_error');
            }
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($status > 0) {
                $responseHeaders[] = 'HTTP/1.1 ' . $status;
            }
            curl_close($ch);

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

    private function safeHttpError(int $status, string $body): string
    {
        if ($status === 401 || $status === 403) {
            return 'AI API Key is invalid or unauthorized. HTTP ' . $status . '.';
        }
        if ($status === 404) {
            return 'AI model or endpoint was not found. HTTP 404.';
        }
        if ($status === 429) {
            return 'AI provider quota or rate limit was exceeded. HTTP 429.';
        }
        $message = 'AI provider request failed. HTTP ' . $status . '.';
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $providerMessage = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
            if ($providerMessage !== '') {
                $message .= ' ' . substr($providerMessage, 0, 160);
            }
        } catch (\JsonException) {
        }

        return preg_replace('/(?:sk|Bearer|api[_-]?key|secret)[A-Za-z0-9_=:.-]+/i', '[redacted]', $message) ?: $message;
    }

    private function baseUrl(string $provider, string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '' && $provider === 'deepseek') {
            $baseUrl = 'https://api.deepseek.com/v1';
        }
        if ($baseUrl === '') {
            throw new AiException('AI Base URL is not configured.', 'base_url_missing');
        }
        if (strlen($baseUrl) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $baseUrl) === 1) {
            throw new AiException('AI Base URL is invalid.', 'base_url_invalid');
        }
        $parts = parse_url($baseUrl);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new AiException('AI Base URL must be an http or https URL without credentials.', 'base_url_invalid');
        }

        return $baseUrl;
    }
}
