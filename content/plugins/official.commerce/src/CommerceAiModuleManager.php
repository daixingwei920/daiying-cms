<?php

declare(strict_types=1);

namespace Daiying\Commerce;

use RuntimeException;
use Throwable;

final class CommerceAiModuleManager
{
    private const TASKS = [
        'product_copy' => '商品标题、摘要、描述辅助优化',
        'share_copy' => '分享文案生成',
        'content_match' => '商品与现有文章内容匹配',
        'sales_insight' => '销售/转化数据解释',
        'verification_explanation' => 'Verification 已取得事实的自然语言解释',
    ];

    /**
     * @param null|callable(array<string,mixed>,string):array<string,mixed> $transport
     */
    public function __construct(
        private readonly CommerceRepository $repo,
        private readonly string $encryptionKey,
        private $transport = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function testModule(int $moduleId): array
    {
        $module = $this->repo->aiModule($moduleId);
        if ($module === null) {
            throw new RuntimeException('AI 模块不存在。');
        }
        $prompt = 'Return a short plain text response: Daiying Commerce AI module connection OK.';
        try {
            $text = $this->callModule($module, $prompt);
            $message = $text !== '' ? '连接成功：' . $this->short($text, 120) : '连接成功。';
            $this->repo->updateAiModuleTest($moduleId, 'success', $message);
            $this->repo->recordAiInvocation($moduleId, (string) $module['name'], 'product_copy', 'success', (string) $module['billing_type'], $prompt, '', $text);

            return ['ok' => true, 'module_id' => $moduleId, 'message' => $message];
        } catch (Throwable $exception) {
            $message = $this->safeError($exception->getMessage());
            $this->repo->updateAiModuleTest($moduleId, 'failed', $message);
            $this->repo->recordAiInvocation($moduleId, (string) $module['name'], 'product_copy', 'failed', (string) $module['billing_type'], $prompt, $message);

            return ['ok' => false, 'module_id' => $moduleId, 'message' => $message];
        }
    }

    /**
     * @param array<string,mixed> $product
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function runProductTask(string $task, array $product, array $context = []): array
    {
        if (!array_key_exists($task, self::TASKS)) {
            throw new RuntimeException('AI 任务类型不支持。');
        }
        $allowPaid = !empty($context['allow_paid']);
        $prompt = $this->buildPrompt($task, $product, $context);
        $attempts = [];
        foreach ($this->eligibleModules($task, $allowPaid) as $module) {
            try {
                $text = $this->callModule($module, $prompt);
                $this->repo->recordAiInvocation((int) $module['id'], (string) $module['name'], $task, 'success', (string) $module['billing_type'], $prompt, '', $text);

                return [
                    'ok' => true,
                    'task' => $task,
                    'module_id' => (int) $module['id'],
                    'module_name' => (string) $module['name'],
                    'billing_type' => (string) $module['billing_type'],
                    'result' => $text,
                    'attempts' => $attempts,
                ];
            } catch (Throwable $exception) {
                $message = $this->safeError($exception->getMessage());
                $attempts[] = [
                    'module_id' => (int) $module['id'],
                    'module_name' => (string) $module['name'],
                    'billing_type' => (string) $module['billing_type'],
                    'error' => $message,
                ];
                $this->repo->recordAiInvocation((int) $module['id'], (string) $module['name'], $task, 'failed', (string) $module['billing_type'], $prompt, $message);
            }
        }

        return [
            'ok' => false,
            'task' => $task,
            'result' => '',
            'attempts' => $attempts,
            'message' => $allowPaid ? '没有可用 AI 模块。' : '免费 AI 模块不可用；未启用允许收费 AI，因此没有自动切换到收费模块。',
        ];
    }

    /** @return array<string,string> */
    public static function taskLabels(): array
    {
        return self::TASKS;
    }

    /** @param array<string,mixed> $module */
    private function callModule(array $module, string $prompt): string
    {
        if ((string) ($module['protocol'] ?? '') !== 'openai_compatible') {
            throw new RuntimeException('该协议需要专用 Provider Adapter，当前未配置。');
        }
        $credential = $this->repo->aiModuleCredential((int) $module['id'], $this->encryptionKey);
        if ($credential === '') {
            throw new RuntimeException('AI 凭据未配置。');
        }
        $payload = [
            'model' => (string) ($module['model'] ?? ''),
            'messages' => [
                ['role' => 'system', 'content' => 'You are a Daiying Commerce assistant. Only provide cautious copy and explanations. Never modify verification facts, never claim authenticity, never decide purchases for users.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => (float) ($module['public_config']['temperature'] ?? 0.2),
        ];
        $transport = $this->transport;
        $response = is_callable($transport)
            ? $transport($module, $this->redactPromptSecrets($prompt))
            : $this->httpChatCompletion($module, $credential, $payload);
        $text = trim((string) ($response['text'] ?? ''));
        if ($text === '') {
            throw new RuntimeException('AI 返回为空。');
        }

        return $this->short($text, 4000);
    }

    /** @param array<string,mixed> $module @param array<string,mixed> $payload @return array<string,mixed> */
    private function httpChatCompletion(array $module, string $credential, array $payload): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL 不可用。');
        }
        $endpoint = rtrim((string) ($module['endpoint'] ?? ''), '/');
        if ($endpoint === '') {
            throw new RuntimeException('AI Endpoint 未配置。');
        }
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
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (!is_string($body) || $body === '') {
            throw new RuntimeException($error !== '' ? $error : 'AI Provider 无响应。');
        }
        $data = json_decode($body, true);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('AI Provider HTTP ' . $status);
        }
        if (!is_array($data)) {
            throw new RuntimeException('AI Provider 返回格式无效。');
        }
        $text = (string) ($data['choices'][0]['message']['content'] ?? $data['choices'][0]['text'] ?? '');

        return ['text' => $text];
    }

    /** @return list<array<string,mixed>> */
    private function eligibleModules(string $task, bool $allowPaid): array
    {
        $free = [];
        $paid = [];
        foreach ($this->repo->aiModules(true) as $module) {
            $capabilities = is_array($module['capabilities'] ?? null) ? $module['capabilities'] : [];
            if (!in_array($task, $capabilities, true)) {
                continue;
            }
            if ((string) ($module['billing_type'] ?? 'free') === 'paid') {
                $paid[] = $module;
                continue;
            }
            $free[] = $module;
        }

        return $allowPaid ? array_merge($free, $paid) : $free;
    }

    /** @param array<string,mixed> $product @param array<string,mixed> $context */
    private function buildPrompt(string $task, array $product, array $context): string
    {
        $facts = [
            'task' => self::TASKS[$task],
            'product' => [
                'name' => (string) ($product['name'] ?? ''),
                'summary' => (string) ($product['summary'] ?? ''),
                'price_minor' => (int) ($product['price_minor'] ?? 0),
                'currency' => (string) ($product['currency'] ?? ''),
                'brand' => (string) ($product['brand'] ?? ''),
                'model' => (string) ($product['model'] ?? ''),
                'verification_status' => (string) ($product['verification_status'] ?? ''),
            ],
            'verification_facts' => is_array($context['verification_facts'] ?? null) ? $context['verification_facts'] : [],
            'sales_facts' => is_array($context['sales_facts'] ?? null) ? $context['sales_facts'] : [],
            'content_facts' => is_array($context['content_facts'] ?? null) ? $context['content_facts'] : [],
        ];

        return "请基于以下 Daiying Commerce 事实提供辅助文本。不要改变原始事实，不要声称正品认证，不要替消费者做购买决定。\n" .
            json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function safeError(string $message): string
    {
        $message = preg_replace('/(?:bearer\s+|sk-[A-Za-z0-9_-]+|api[_-]?key=|access[_-]?key=|secret=|authorization=)[^\s"\']*/i', '[redacted]', $message) ?: $message;

        return $this->short($message, 500);
    }

    private function redactPromptSecrets(string $prompt): string
    {
        return preg_replace('/(?:api[_-]?key|secret|token|authorization)["\']?\s*[:=]\s*["\']?[^,"\'}\s]+/i', '$1=[redacted]', $prompt) ?: $prompt;
    }

    private function short(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 3) . '...' : $text;
    }
}
