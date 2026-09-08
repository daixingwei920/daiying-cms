<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

final class AiProviderPresets
{
    /** @return array<string,array{label:string,adapter:string,base_url:string,model:string}> */
    public static function all(): array
    {
        return [
            'deepseek' => [
                'label' => 'DeepSeek',
                'adapter' => 'openai_compatible',
                'base_url' => 'https://api.deepseek.com/v1',
                'model' => 'deepseek-chat',
            ],
            'openai' => [
                'label' => 'OpenAI',
                'adapter' => 'openai_compatible',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'gpt-4.1-mini',
            ],
            'xai' => [
                'label' => 'Grok / xAI',
                'adapter' => 'openai_compatible',
                'base_url' => 'https://api.x.ai/v1',
                'model' => 'grok-4.6',
            ],
            'tencent_hunyuan' => [
                'label' => '腾讯混元',
                'adapter' => 'openai_compatible',
                'base_url' => 'https://api.hunyuan.cloud.tencent.com/v1',
                'model' => 'hunyuan-turbos-latest',
            ],
            'gemini' => [
                'label' => 'Google Gemini',
                'adapter' => 'gemini',
                'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
                'model' => 'gemini-2.5-flash',
            ],
            'openai_compatible' => [
                'label' => '自定义 OpenAI-compatible',
                'adapter' => 'openai_compatible',
                'base_url' => '',
                'model' => '',
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
        if (!self::exists($provider)) {
            return 'openai_compatible';
        }

        return $provider;
    }

    /** @return array{label:string,adapter:string,base_url:string,model:string} */
    public static function get(string $provider): array
    {
        $presets = self::all();

        return $presets[self::normalize($provider)] ?? $presets['openai_compatible'];
    }

    public static function adapter(string $provider, string $adapter = ''): string
    {
        $adapter = trim($adapter);
        if (in_array($adapter, ['openai_compatible', 'gemini'], true)) {
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

        return $config;
    }
}
