<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

final class OpenClawProviderClient implements AiProviderClientInterface, AiModelDiscoveryClientInterface
{
    /** @var null|\Closure(string,string,list<string>,string,int):array{body:string,headers:list<string>} */
    private ?\Closure $transport;

    /** @param null|\Closure(string,string,list<string>,string,int):array{body:string,headers:list<string>} $transport */
    public function __construct(?\Closure $transport = null)
    {
        $this->transport = $transport;
    }

    public function chat(array $messages, array $config): array
    {
        $provider = 'openclaw';
        $model = trim((string) ($config['model'] ?? ''));
        $agent = trim((string) ($config['openclaw_agent'] ?? ''));
        $baseUrl = $this->baseUrl((string) ($config['base_url'] ?? ''));
        $payload = [
            'messages' => $messages,
        ];
        if ($model !== '') {
            $payload['model'] = $model;
        }
        if ($agent !== '') {
            $payload['agent'] = $agent;
        }
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

        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        $token = trim((string) ($config['api_key'] ?? ''));
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $timeout = max(1, min(120, (int) ($config['timeout_seconds'] ?? 30)));
        $responseHeaders = [];
        $path = $agent !== '' ? 'api/v1/agents/' . rawurlencode($agent) . '/chat' : 'api/v1/chat';
        $body = $this->request('POST', $this->joinUrl($baseUrl, $path), $headers, $json, $timeout, $responseHeaders);
        $status = $this->httpStatus($responseHeaders);
        if ($status < 200 || $status >= 300) {
            throw new AiException($this->safeHttpError($status, $body), $this->httpReason($status));
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AiException('OpenClaw returned invalid JSON.', 'response_invalid');
        }
        if (!is_array($decoded)) {
            throw new AiException('OpenClaw returned an invalid response.', 'response_invalid');
        }
        $content = (string) (
            $decoded['content']
            ?? $decoded['message']['content']
            ?? $decoded['choices'][0]['message']['content']
            ?? $decoded['data']['content']
            ?? ''
        );
        if ($content === '') {
            throw new AiException('OpenClaw returned an empty response.', 'response_empty');
        }

        return [
            'provider' => $provider,
            'model' => $model !== '' ? $model : ($agent !== '' ? $agent : 'openclaw-agent'),
            'content' => $content,
            'raw' => [
                'usage' => is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [],
            ],
        ];
    }

    public function models(array $config): array
    {
        $baseUrl = $this->baseUrl((string) ($config['base_url'] ?? ''));
        $headers = ['Accept: application/json'];
        $token = trim((string) ($config['api_key'] ?? ''));
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $timeout = max(1, min(120, (int) ($config['timeout_seconds'] ?? 30)));
        $responseHeaders = [];
        $body = $this->request('GET', $this->joinUrl($baseUrl, 'api/v1/models'), $headers, '', $timeout, $responseHeaders);
        $status = $this->httpStatus($responseHeaders);
        if ($status < 200 || $status >= 300) {
            throw new AiException($this->safeHttpError($status, $body), $this->httpReason($status));
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AiException('OpenClaw returned invalid JSON.', 'response_invalid');
        }
        if (!is_array($decoded)) {
            throw new AiException('OpenClaw returned an invalid response.', 'response_invalid');
        }
        $items = is_array($decoded['data'] ?? null) ? $decoded['data'] : (is_array($decoded['models'] ?? null) ? $decoded['models'] : []);
        $models = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = trim((string) ($item['id'] ?? $item['name'] ?? ''));
            if ($id === '' || strlen($id) > 191 || preg_match('/[\x00-\x1F\x7F]/', $id) === 1) {
                continue;
            }
            $models[] = [
                'id' => $id,
                'label' => trim((string) ($item['label'] ?? $item['name'] ?? $id)),
                'capabilities' => ['text', 'chat', 'tools', 'agent', 'text_generation'],
            ];
        }

        return $models;
    }

    private function baseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            throw new AiException('OpenClaw URL is not configured.', 'base_url_missing');
        }
        if (strlen($baseUrl) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $baseUrl) === 1) {
            throw new AiException('OpenClaw URL is invalid.', 'base_url_invalid');
        }
        $parts = parse_url($baseUrl);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new AiException('OpenClaw URL must be an http or https URL without credentials, query, or fragment.', 'base_url_invalid');
        }

        return $baseUrl;
    }

    /** @param list<string> $headers @param list<string> $responseHeaders */
    private function request(string $method, string $url, array $headers, string $json, int $timeout, array &$responseHeaders): string
    {
        if ($this->transport !== null) {
            $result = ($this->transport)($method, $url, $headers, $json, $timeout);
            $responseHeaders = array_values(array_map('strval', $result['headers']));

            return (string) $result['body'];
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new AiException('Unable to initialize OpenClaw HTTP client.', 'network_error');
            }
            $options = [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$responseHeaders): int {
                    $responseHeaders[] = trim($header);
                    return strlen($header);
                },
            ];
            if ($method === 'POST') {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = $json;
            }
            curl_setopt_array($ch, $options);
            $body = curl_exec($ch);
            if (!is_string($body)) {
                $error = curl_error($ch);
                $this->closeCurl($ch);
                $reason = stripos($error, 'timed out') !== false ? 'timeout' : 'network_error';
                throw new AiException($error !== '' ? 'OpenClaw network error: ' . $this->redact($error) : 'OpenClaw network request failed.', $reason);
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
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $method === 'POST' ? $json : '',
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $responseHeaders = array_values(array_map('strval', $http_response_header ?? []));
        if (!is_string($body)) {
            throw new AiException('OpenClaw network request failed.', 'network_error');
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
            return 'OpenClaw authentication failed. HTTP ' . $status . '.';
        }
        if ($status === 404) {
            return 'OpenClaw model, agent, or endpoint was not found. HTTP 404.';
        }
        if ($status === 429) {
            return 'OpenClaw quota or rate limit was exceeded. HTTP 429.';
        }
        $message = 'OpenClaw request failed. HTTP ' . $status . '.';
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $providerMessage = is_array($decoded) ? (string) ($decoded['error']['message'] ?? $decoded['message'] ?? '') : '';
            if ($providerMessage !== '') {
                $message .= ' ' . substr($providerMessage, 0, 160);
            }
        } catch (\JsonException) {
        }

        return $this->redact($message);
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

    private function joinUrl(string $baseUrl, string $path): string
    {
        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }

    private function redact(string $value): string
    {
        return preg_replace('/(?:sk|Bearer|token|api[_-]?key|secret)[A-Za-z0-9_=:.,\/+\-]+/i', '[redacted]', $value) ?: $value;
    }

    /** @param resource|\CurlHandle $ch */
    private function closeCurl($ch): void
    {
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }
    }
}
