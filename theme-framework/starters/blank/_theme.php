<?php
declare(strict_types=1);

use Cms\Core\Theme\TemplateContext;

function blank_content_url(array $item): string
{
    if (!empty($item['url'])) {
        return (string) $item['url'];
    }
    $content = is_array($item['content'] ?? null) ? $item['content'] : [];
    if (!empty($content['url'])) {
        return (string) $content['url'];
    }
    $slug = trim((string) ($item['slug'] ?? $content['slug'] ?? ''), '/');
    if ($slug === '') {
        return '';
    }
    return (($item['content_type'] ?? $content['content_type'] ?? 'article') === 'article' ? '/articles/' : '/') . rawurlencode($slug);
}

function blank_items(TemplateContext $context): array
{
    foreach (['items', 'articles', 'contents', 'results'] as $key) {
        $value = $context->get($key, null);
        if (is_array($value)) {
            return $value;
        }
    }
    return [];
}

function blank_excerpt(array $item): string
{
    return trim((string) ($item['excerpt'] ?? $item['summary'] ?? ''));
}

function blank_cover(array $item): string
{
    foreach (['cover', 'cover_url', 'featured_image', 'featured_image_url'] as $key) {
        $value = $item[$key] ?? '';
        if (is_array($value)) {
            $value = $value['url'] ?? '';
        }
        $value = trim((string) $value);
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function blank_logo_url(TemplateContext $context): string
{
    foreach (['logo_image', 'site_logo_url'] as $key) {
        $value = $key === 'logo_image' ? $context->setting($key, '') : $context->get($key, '');
        if (is_array($value)) {
            $value = $value['url'] ?? '';
        }
        $value = trim((string) $value);
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}
