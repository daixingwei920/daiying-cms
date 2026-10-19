<?php

declare(strict_types=1);

namespace Cms\Core\Theme;

use Cms\Core\Routing\BasePath;

use Cms\Core\Foundation\FoundationVersions;

final class TemplateContext
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly ThemeRuntime $theme,
        private readonly array $data,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->theme->settings[$key] ?? $default;
    }

    public function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public function siteIcpNumber(): string
    {
        return trim((string) $this->get('site_icp_number', ''));
    }

    public function siteIcpFooter(): string
    {
        $number = $this->siteIcpNumber();
        if ($number === '') {
            return '';
        }

        return '<footer data-cms-site-icp="1" style="padding:16px;text-align:center;overflow-wrap:anywhere">'
            . '<a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer">' . $this->e($number) . '</a></footer>';
    }

    public function apiVersion(): string
    {
        return FoundationVersions::THEME_API;
    }

    public function themeId(): string
    {
        return $this->theme->manifest->id;
    }

    public function asset(string $path): string
    {
        return ThemeViewModel::assetUrl($this->theme->manifest->id, $path);
    }

    public function assetPath(string $path): string
    {
        $relative = ThemeViewModel::cleanRelativePath($path);
        $fullPath = $this->theme->path . '/assets/' . $relative;
        $assetsRoot = realpath($this->theme->path . '/assets');
        $resolved = realpath($fullPath);
        if ($assetsRoot !== false && $resolved !== false && str_starts_with($resolved, $assetsRoot . DIRECTORY_SEPARATOR)) {
            return $resolved;
        }

        return $fullPath;
    }

    /** @param array<string,mixed> $query */
    public function url(string $path, array $query = []): string
    {
        return ThemeViewModel::url($path, $query);
    }

    /** @param array<string,mixed> $media @return array{id:int|null,url:string,title:string,alt:string,mime:string,size:int|null,provider:string,metadata:array<string,mixed>} */
    public function media(array $media): array
    {
        return ThemeViewModel::media($media);
    }

    /** @param array<string,mixed> $query @return array{current:int,total:int,per_page:int,total_items:int|null,has_prev:bool,has_next:bool,prev_url:string|null,next_url:string|null,pages:list<array{page:int,url:string,current:bool}>} */
    public function pagination(int $current, int $total, string $basePath, array $query = [], int $perPage = 20, ?int $totalItems = null): array
    {
        return ThemeViewModel::pagination($current, $total, $basePath, $query, $perPage, $totalItems);
    }

    /** @param list<array<string,mixed>|string> $items @return list<array{label:string,url:string|null,current:bool}> */
    public function breadcrumb(array $items): array
    {
        return ThemeViewModel::breadcrumb($items);
    }

    /** @return list<array{label:string,url:string,current:bool}> */
    public function menu(string $name = 'primary'): array
    {
        $menus = $this->get('menus', []);
        $items = [];
        if (is_array($menus) && is_array($menus[$name] ?? null)) {
            $items = $menus[$name];
        } elseif ($name === 'primary' && is_array($this->get('menu', null))) {
            $items = $this->get('menu');
        }

        if ($items === [] && $name === 'primary' && !is_array($menus[$name] ?? null) && !is_array($this->get('menu', null))) {
            $items = $this->get('navigation', []);
        }
        return ThemeViewModel::menu(is_array($items) ? $items : [], $this->menuCurrentPath());
    }

    /** Structured menu for themes that opt into two-level navigation. */
    public function menuTree(string $name = 'primary'): array
    {
        $menus = $this->get('menus', []);
        $menus = is_array($menus) ? $menus : [];
        $items = $menus[$name] ?? ($name === 'primary' ? $this->get('navigation_tree', $this->get('menu', $this->get('navigation', []))) : []);
        return ThemeViewModel::menuTree(is_array($items) ? $items : [], $this->menuCurrentPath());
    }

    private function menuCurrentPath(): string
    {
        $explicit = $this->get('current_path', null);
        if (is_string($explicit)) { return BasePath::stripCurrent($explicit); }
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        return is_string($path) ? BasePath::stripCurrent($path) : '';
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    public function seo(array $overrides = []): array
    {
        $seo = $this->get('seo', []);
        if (!is_array($seo)) {
            $seo = [];
        }

        return $overrides + $seo;
    }
}
