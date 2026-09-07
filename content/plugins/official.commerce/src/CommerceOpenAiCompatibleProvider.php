<?php

declare(strict_types=1);

namespace Daiying\Commerce;

use RuntimeException;

final class CommerceOpenAiCompatibleProvider
{
    /** @param array<string,mixed> $module */
    public function chatCompletion(array $module, string $credential, string $systemPrompt, string $userPrompt): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL 不可用。');
        }
        $credential = trim($credential);
        if ($credential === '') {
            throw new RuntimeException('AI 凭据未配置。');
        }
        $endpoint = rtrim((string) ($module['endpoint'] ?? ''), '/');
        if ($endpoint === '') {
            throw new RuntimeException('AI Endpoint 未配置。');
        }
        $payload = [
            'model' => (string) ($module['model'] ?? ''),
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => (float) ($module['public_config']['temperature'] ?? 0.2),
            'stream' => false,
        ];
        $url = str_ends_with($endpoint, '/chat/completions') ? $endpoint : $endpoint . '/chat/completions';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $credential,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) ($module['public_config']['timeout_seconds'] ?? 12),
            CURLOPT_CONNECTTIMEOUT => min(10, (int) ($module['public_config']['timeout_seconds'] ?? 12)),
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (!is_string($body) || $body === '') {
            throw new RuntimeException($error !== '' ? $error : 'AI Provider 无响应。');
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('AI Provider HTTP ' . $status);
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('AI Provider 返回格式无效。');
        }
        $text = (string) ($data['choices'][0]['message']['content'] ?? $data['choices'][0]['text'] ?? '');
        if (trim($text) === '') {
            throw new RuntimeException('AI 返回为空。');
        }

        return $text;
    }
}
