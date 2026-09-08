<?php

declare(strict_types=1);

namespace Cms\Core\Ai;

final class AiProviderRegistry
{
    /** @var array<string,AiProviderInterface> */
    private static array $providers = [];
    private static bool $bootstrapped = false;

    public static function register(AiProviderInterface $provider): void
    {
        $id = $provider->getId();
        if (!preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*$/', $id)) {
            throw new AiException('AI provider id is invalid.', 'provider_invalid');
        }
        self::$providers[$id] = $provider;
    }

    public static function get(string $id): ?AiProviderInterface
    {
        self::bootstrapDefaults();

        return self::$providers[AiProviderPresets::normalize($id)] ?? self::$providers[$id] ?? null;
    }

    /** @return array<string,AiProviderInterface> */
    public static function all(): array
    {
        self::bootstrapDefaults();
        ksort(self::$providers);

        return self::$providers;
    }

    /** @return array<string,array<string,mixed>> */
    public static function describe(): array
    {
        $descriptions = [];
        foreach (self::all() as $id => $provider) {
            $descriptions[$id] = [
                'id' => $provider->getId(),
                'label' => $provider->getLabel(),
                'capabilities' => $provider->getCapabilities(),
                'models' => array_map(static fn (AiModel $model): array => $model->toArray(), $provider->getModels()),
            ];
        }

        return $descriptions;
    }

    public static function clear(): void
    {
        self::$providers = [];
        self::$bootstrapped = false;
    }

    private static function bootstrapDefaults(): void
    {
        if (self::$bootstrapped) {
            return;
        }
        self::$bootstrapped = true;
        foreach (AiProviderPresets::all() as $id => $preset) {
            $model = new AiModel($preset['model'] !== '' ? $preset['model'] : 'custom-model', $preset['model'] !== '' ? $preset['model'] : 'Custom Model', self::defaultCapabilities($preset['adapter']));
            if ($preset['adapter'] === 'gemini') {
                self::register(new GeminiProvider([$model]));
                continue;
            }
            self::register(new OpenAiCompatibleProvider($id, $preset['label'], [$model]));
        }
    }

    /** @return list<string> */
    private static function defaultCapabilities(string $adapter): array
    {
        return $adapter === 'gemini'
            ? ['text_generation', 'vision', 'structured_output', 'streaming']
            : ['text_generation', 'structured_output', 'tool_calling', 'streaming'];
    }
}
