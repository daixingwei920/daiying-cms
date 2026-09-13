<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

final class OpenAiCompatibleProviderClient implements AiProviderClientInterface, AiModelDiscoveryClientInterface
{
    /** @var null|\Closure(string,string,list<string>,string,int):array{body:string,headers:list<string>} */
    private ?\Closure $transport;

    /** @param null|\Closure(string,string,list<string>,string,int):array{body:string,headers:list<string>} $transport */
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
        $provider = AiProviderPresets::normalize((string) ($config['provider'] ?? 'openai_compatible'));
        $apiKey = (string) ($config['api_key'] ?? '');
        $model = trim((string) ($config['model'] ?? ''));
        $baseUrl = $this->baseUrl($provider, (string) ($config['base_url'] ?? ''));
        if ($apiKey === '' && !empty($config['api_key_required'])) {
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
        if (array_key_exists('include_reasoning', $config)) {
            $payload['include_reasoning'] = (bool) $config['include_reasoning'];
        } elseif ($this->isGroqEndpoint($provider, $baseUrl) && $this->isReasoningModel($model)) {
            $payload['include_reasoning'] = false;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new AiException('AI request payload is invalid.', 'request_invalid');
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }
        $timeout = max(1, min(120, (int) ($config['timeout_seconds'] ?? 30)));
        $responseHeaders = [];
        $body = $this->request('POST', $this->joinUrl($baseUrl, 'chat/completions'), $headers, $json, $timeout, $responseHeaders);
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
        $content = $this->extractContent($decoded);
        if ($content === '') {
            throw new AiException($this->emptyResponseMessage($status, $decoded), 'response_empty');
        }

        return [
            'provider' => $provider,
            'model' => $model,
            'content' => $content,
            'raw' => [
                'id' => (string) ($decoded['id'] ?? ''),
                'http_status' => $status,
                'response_structure' => $this->responseStructure($decoded),
                'usage' => is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $config
     * @return list<array{id:string,label:string,capabilities:list<string>}>
     */
    public function models(array $config): array
    {
        $provider = AiProviderPresets::normalize((string) ($config['provider'] ?? 'openai_compatible'));
        $apiKey = (string) ($config['api_key'] ?? '');
        if ($apiKey === '' && !empty($config['api_key_required'])) {
            throw new AiException('AI API Key is not configured.', 'api_key_missing');
        }
        $baseUrl = $this->baseUrl($provider, (string) ($config['base_url'] ?? ''));
        $headers = ['Accept: application/json'];
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }
        $timeout = max(1, min(120, (int) ($config['timeout_seconds'] ?? 30)));
        $responseHeaders = [];
        $body = $this->request('GET', $this->joinUrl($baseUrl, 'models'), $headers, '', $timeout, $responseHeaders);
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
        $items = is_array($decoded['data'] ?? null) ? $decoded['data'] : (is_array($decoded['models'] ?? null) ? $decoded['models'] : []);
        $models = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = trim((string) ($item['id'] ?? $item['name'] ?? $item['model'] ?? ''));
            if ($id === '' || preg_match('/[\x00-\x1F\x7F]/', $id) === 1 || strlen($id) > 191) {
                continue;
            }
            $models[] = [
                'id' => $id,
                'label' => trim((string) ($item['display_name'] ?? $item['name'] ?? $id)),
                'capabilities' => ['text', 'chat', 'text_generation'],
            ];
        }

        return $models;
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
                throw new AiException('Unable to initialize AI HTTP client.', 'network_error');
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

        return $this->redact($message);
    }

    /** @param array<string,mixed> $decoded */
    private function extractContent(array $decoded): string
    {
        $message = $decoded['choices'][0]['message'] ?? null;
        if (is_array($message)) {
            $content = $message['content'] ?? '';
            if (is_string($content)) {
                return trim($content);
            }
            if (is_array($content)) {
                $parts = [];
                foreach ($content as $part) {
                    if (is_string($part)) {
                        $parts[] = $part;
                        continue;
                    }
                    if (!is_array($part)) {
                        continue;
                    }
                    $text = $part['text'] ?? $part['content'] ?? '';
                    if (is_string($text) && trim($text) !== '') {
                        $parts[] = $text;
                    }
                }

                return trim(implode("\n", $parts));
            }
        }

        $choiceText = $decoded['choices'][0]['text'] ?? '';
        if (is_string($choiceText)) {
            return trim($choiceText);
        }
        $outputText = $decoded['output_text'] ?? '';
        if (is_string($outputText)) {
            return trim($outputText);
        }

        return '';
    }

    /** @param array<string,mixed> $decoded */
    private function emptyResponseMessage(int $status, array $decoded): string
    {
        $finishReason = (string) ($decoded['choices'][0]['finish_reason'] ?? '');
        $message = 'AI provider returned an empty final content response. HTTP ' . $status . '.';
        if ($finishReason !== '') {
            $message .= ' finish_reason=' . $this->redact(substr($finishReason, 0, 80)) . '.';
        }
        $message .= ' response_structure=' . $this->responseStructure($decoded) . '.';

        return $this->redact($message);
    }

    /** @param array<string,mixed> $decoded */
    private function responseStructure(array $decoded): string
    {
        $keys = array_slice(array_keys($decoded), 0, 12);
        $message = $decoded['choices'][0]['message'] ?? null;
        if (is_array($message)) {
            $messageKeys = array_slice(array_keys($message), 0, 12);
            return 'root{' . implode(',', array_map('strval', $keys)) . '}; choices[0].message{' . implode(',', array_map('strval', $messageKeys)) . '}';
        }

        return 'root{' . implode(',', array_map('strval', $keys)) . '}';
    }

    private function isGroqEndpoint(string $provider, string $baseUrl): bool
    {
        $host = (string) (parse_url($baseUrl, PHP_URL_HOST) ?: '');

        return $provider === 'groq' || strcasecmp($host, 'api.groq.com') === 0;
    }

    private function isReasoningModel(string $model): bool
    {
        $model = strtolower($model);

        return str_contains($model, 'gpt-oss') || str_contains($model, 'reasoning') || str_contains($model, 'qwen3');
    }

    private function baseUrl(string $provider, string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            $baseUrl = AiProviderPresets::get($provider)['base_url'];
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
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new AiException('AI Base URL must be an http or https URL without credentials, query, or fragment.', 'base_url_invalid');
        }
        $path = is_array($parts) ? rtrim((string) ($parts['path'] ?? ''), '/') : '';
        if ($provider === 'local_model' && ($path === '' || $path === '/')) {
            $baseUrl .= '/v1';
        }

        return $baseUrl;
    }

    private function joinUrl(string $baseUrl, string $path): string
    {
        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
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

    private function redact(string $value): string
    {
        return preg_replace('/(?:sk|Bearer|api[_-]?key|secret)[A-Za-z0-9_=:.,\/+\-]+/i', '[redacted]', $value) ?: $value;
    }

    /** @param resource|\CurlHandle $ch */
    private function closeCurl($ch): void
    {
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }
    }
}
