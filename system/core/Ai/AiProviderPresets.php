<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

final class AiProviderPresets
{
    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        return [
            'deepseek' => [
                'label' => 'DeepSeek',
                'adapter' => 'openai_compatible',
                'base_url' => 'https://api.deepseek.com/v1',
                'model' => 'deepseek-chat',
                'api_key_required' => true,
                'cloud' => true,
                'supports_model_discovery' => false,
            ],
            'openai' => [
                'label' => 'OpenAI',
                'adapter' => 'openai_compatible',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'gpt-4.1-mini',
                'api_key_required' => true,
                'cloud' => true,
                'supports_model_discovery' => true,
            ],
            'xai' => [
                'label' => 'Grok / xAI',
                'adapter' => 'openai_compatible',
                'base_url' => 'https://api.x.ai/v1',
                'model' => 'grok-4.6',
                'api_key_required' => true,
                'cloud' => true,
                'supports_model_discovery' => false,
            ],
            'tencent_hunyuan' => [
                'label' => '腾讯混元',
                'adapter' => 'openai_compatible',
                'base_url' => 'https://api.hunyuan.cloud.tencent.com/v1',
                'model' => 'hunyuan-turbos-latest',
                'api_key_required' => true,
                'cloud' => true,
                'supports_model_discovery' => false,
            ],
            'qwen' => [
                'label' => '通义千问 / Qwen',
                'adapter' => 'openai_compatible',
                'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
                'model' => 'qwen-plus',
                'api_key_required' => true,
                'cloud' => true,
                'supports_model_discovery' => false,
            ],
            'gemini' => [
                'label' => 'Google Gemini',
                'adapter' => 'gemini',
                'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
                'model' => 'gemini-3.6-flash',
                'api_key_required' => true,
                'cloud' => true,
                'supports_model_discovery' => false,
            ],
            'local_model' => [
                'label' => 'Local Model',
                'adapter' => 'openai_compatible',
                'base_url' => 'http://127.0.0.1:11434/v1',
                'model' => '',
                'api_key_required' => false,
                'cloud' => false,
                'supports_model_discovery' => true,
                'local_api_type' => 'openai_compatible',
            ],
            'openai_compatible' => [
                'label' => '自定义 OpenAI-compatible',
                'adapter' => 'openai_compatible',
                'base_url' => '',
                'model' => '',
                'api_key_required' => true,
                'cloud' => false,
                'supports_model_discovery' => true,
            ],
            'openclaw' => [
                'label' => 'OpenClaw',
                'adapter' => 'openclaw',
                'base_url' => '',
                'model' => '',
                'api_key_required' => false,
                'cloud' => false,
                'supports_model_discovery' => true,
                'openclaw_agent' => '',
            ],
        ];
    }

    public static function exists(string $provider): bool
    {
        return isset(self::all()[$provider]);
    }

    public static function normalize(string $provider): string
    {
        $provider = trim($provider);
        if ($provider === '' || $provider === 'custom' || $provider === 'openai-compatible') {
            return 'openai_compatible';
        }
        if ($provider === 'grok' || $provider === 'x.ai') {
            return 'xai';
        }
        if ($provider === 'hunyuan' || $provider === 'tencent') {
            return 'tencent_hunyuan';
        }
        if ($provider === 'tongyi' || $provider === 'dashscope' || $provider === 'aliyun_qwen') {
            return 'qwen';
        }
        if (in_array($provider, ['local', 'ollama', 'lmstudio', 'lm_studio', 'llama_cpp', 'vllm'], true)) {
            return 'local_model';
        }
        if (in_array($provider, ['open-claw', 'open_claw'], true)) {
            return 'openclaw';
        }
        if (!self::exists($provider)) {
            return 'openai_compatible';
        }

        return $provider;
    }

    /** @return array<string,mixed> */
    public static function get(string $provider): array
    {
        $presets = self::all();

        return $presets[self::normalize($provider)] ?? $presets['openai_compatible'];
    }

    public static function adapter(string $provider, string $adapter = ''): string
    {
        $adapter = trim($adapter);
        if (in_array($adapter, ['openai_compatible', 'gemini', 'openclaw'], true)) {
            return $adapter;
        }

        return self::get($provider)['adapter'];
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    public static function applyDefaults(array $config): array
    {
        $provider = self::normalize((string) ($config['provider'] ?? 'openai_compatible'));
        $preset = self::get($provider);
        $config['provider'] = $provider;
        $config['adapter'] = self::adapter($provider, (string) ($config['adapter'] ?? ''));
        if (trim((string) ($config['base_url'] ?? '')) === '' && $preset['base_url'] !== '') {
            $config['base_url'] = $preset['base_url'];
        }
        if (trim((string) ($config['model'] ?? '')) === '' && $preset['model'] !== '') {
            $config['model'] = $preset['model'];
        }
        $config['api_key_required'] = self::requiresApiKey($provider);
        $config['cloud_provider'] = self::isCloudProvider($provider);
        $config['supports_model_discovery'] = !empty($preset['supports_model_discovery']);
        if (!isset($config['local_api_type']) || (string) $config['local_api_type'] === '') {
            $config['local_api_type'] = (string) ($preset['local_api_type'] ?? 'openai_compatible');
        }
        if (!isset($config['openclaw_agent'])) {
            $config['openclaw_agent'] = (string) ($preset['openclaw_agent'] ?? '');
        }

        return $config;
    }

    public static function requiresApiKey(string $provider): bool
    {
        return !empty(self::get($provider)['api_key_required']);
    }

    public static function isCloudProvider(string $provider): bool
    {
        return !empty(self::get($provider)['cloud']);
    }
}
