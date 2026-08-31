<?php

declare(strict_types=1);

namespace Cms\Core\Theme;

use Cms\Core\Config\Settings;
use Cms\Core\Logging\FileLogger;

final class ThemeManager
{
    public function __construct(
        private readonly string $themesPath,
        private readonly Settings $settings,
        private readonly FileLogger $logger,
    ) {
    }

    public function activeThemeId(): string
    {
        $configured = (string) $this->settings->get('theme.active', 'default');

        return $configured !== '' ? $configured : 'default';
    }

    public function safeThemeId(): string
    {
        return 'safe';
    }

    public function active(): ThemeRuntime
    {
        return $this->loadWithFallback($this->activeThemeId());
    }

    /** @param list<string> $enabledPlugins */
    public function activeWithPlugins(array $enabledPlugins): ThemeRuntime
    {
        return $this->loadWithFallback($this->activeThemeId(), $enabledPlugins);
    }

    /** @param list<string> $enabledPlugins */
    public function loadWithFallback(string $themeId, array $enabledPlugins = []): ThemeRuntime
    {
        try {
            $this->assertUsable($themeId, $enabledPlugins);
            return $this->load($themeId);
        } catch (\Throwable $exception) {
            $this->logger->error('Theme fallback triggered', [
                'source' => 'Core',
                'theme_id' => $themeId,
                'error' => $exception->getMessage(),
            ]);

            return $this->load($this->safeThemeId());
        }
    }

    public function load(string $themeId): ThemeRuntime
    {
        $themePath = $this->themesPath . '/' . $themeId;
        $manifestFile = $themePath . '/theme.json';
        if (!is_file($manifestFile)) {
            throw new ThemeException('Theme manifest not found: ' . $themeId);
        }

        $decoded = json_decode((string) file_get_contents($manifestFile), true);
        if (!is_array($decoded)) {
            throw new ThemeException('Theme manifest is invalid JSON: ' . $themeId);
        }

        $manifest = ThemeManifest::fromArray($decoded);
        if ($manifest->id !== $themeId) {
            throw new ThemeException('Theme manifest id does not match directory.');
        }

        return new ThemeRuntime($manifest, $themePath, $this->settingsFor($manifest->id));
    }

    /** @param list<string> $enabledPlugins */
    public function assertUsable(string $themeId, array $enabledPlugins = []): void
    {
        $runtime = $this->load($themeId);
        $manifest = $runtime->manifest;
        if (!$this->coreCompatible($manifest)) {
            throw new ThemeException('Theme is not compatible with this Core version.');
        }
        $missing = array_values(array_diff($manifest->requiredPlugins, $enabledPlugins));
        if ($missing !== []) {
            throw new ThemeException('Theme required plugins are not enabled: ' . implode(', ', $missing));
        }
        if (!is_file($runtime->path . '/templates/home.php')) {
            throw new ThemeException('Theme home template is missing.');
        }
    }

    /** @return array<string, ThemeManifest> */
    public function discover(): array
    {
        $themes = [];
        foreach (glob($this->themesPath . '/*/theme.json') ?: [] as $manifestFile) {
            try {
                $decoded = json_decode((string) file_get_contents($manifestFile), true);
                if (is_array($decoded)) {
                    $manifest = ThemeManifest::fromArray($decoded);
                    $themes[$manifest->id] = $manifest;
                }
            } catch (\Throwable $exception) {
                $this->logger->error('Theme discovery skipped invalid theme', [
                    'source' => 'Core',
                    'file' => $manifestFile,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        ksort($themes);

        return $themes;
    }

    /** @param list<string> $enabledPlugins @return array{id:string,name:string,version:string,author:string,current:bool,compatible:bool,usable:bool,required_plugins:list<string>,recommended_plugins:list<string>,missing_plugins:list<string>,reason:string} */
    public function describe(string $themeId, ThemeManifest $manifest, array $enabledPlugins = []): array
    {
        $reason = '';
        $usable = true;
        $compatible = $this->coreCompatible($manifest);
        $missingPlugins = array_values(array_diff($manifest->requiredPlugins, $enabledPlugins));
        if (!$compatible) {
            $usable = false;
            $reason = 'Core version is outside ' . $manifest->coreMin . ' - ' . $manifest->coreMax . '.';
        } elseif ($missingPlugins !== []) {
            $usable = false;
            $reason = 'Missing required plugins: ' . implode(', ', $missingPlugins) . '.';
        } elseif (!is_file($this->themesPath . '/' . $themeId . '/templates/home.php')) {
            $usable = false;
            $reason = 'Missing templates/home.php.';
        }

        return [
            'id' => $themeId,
            'name' => $manifest->name,
            'version' => $manifest->version,
            'author' => $manifest->author,
            'current' => $themeId === $this->activeThemeId(),
            'compatible' => $compatible,
            'usable' => $usable,
            'required_plugins' => $manifest->requiredPlugins,
            'recommended_plugins' => $manifest->recommendedPlugins,
            'missing_plugins' => $missingPlugins,
            'reason' => $reason,
        ];
    }

    /** @return array<string, mixed> */
    private function settingsFor(string $themeId): array
    {
        $settings = $this->settings->get('theme.settings.' . $themeId, []);

        return is_array($settings) ? $settings : [];
    }

    private function coreCompatible(ThemeManifest $manifest): bool
    {
        $coreVersion = (string) $this->settings->get('app.version', '0.0.0');
        if (!version_compare($coreVersion, $manifest->coreMin, '>=')) {
            return false;
        }
        if ($manifest->coreMax !== '' && $manifest->coreMax !== '*' && $manifest->coreMax !== '1.x') {
            return version_compare($coreVersion, $manifest->coreMax, '<=');
        }

        return true;
    }
}
