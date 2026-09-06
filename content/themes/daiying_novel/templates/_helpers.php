<?php

declare(strict_types=1);

$novelThemeUrl = static function (array $item): string {
    foreach (['permalink', 'url', 'canonical'] as $key) {
        if (is_string($item[$key] ?? null) && $item[$key] !== '') {
            return (string) $item[$key];
        }
    }
    $jobId = (string) ($item['job_id'] ?? $item['novel_key'] ?? $item['id'] ?? '');
    return $jobId !== '' ? '/novels/book?job_id=' . rawurlencode($jobId) : '/novels';
};

$novelThemeChapterUrl = static function (array $chapter, array $novel = []) use ($novelThemeUrl): string {
    foreach (['permalink', 'url', 'canonical'] as $key) {
        if (is_string($chapter[$key] ?? null) && $chapter[$key] !== '') {
            return (string) $chapter[$key];
        }
    }
    $jobId = (string) ($chapter['job_id'] ?? $chapter['novel_key'] ?? $novel['job_id'] ?? $novel['novel_key'] ?? $novel['id'] ?? '');
    $sort = max(1, (int) ($chapter['sort_order'] ?? $chapter['chapter_number'] ?? 1));
    return $jobId !== '' ? '/novels/chapter?job_id=' . rawurlencode($jobId) . '&chapter=' . rawurlencode((string) $sort) : $novelThemeUrl($novel);
};

$novelThemeSearchUrl = static fn (): string => '/novels/search';
$novelThemeBookshelfUrl = static fn (): string => '/novels/bookshelf';
$novelThemeTxtUrl = static function (array $novel): string {
    foreach (['txt_url', 'export_url'] as $key) {
        if (is_string($novel[$key] ?? null) && $novel[$key] !== '') {
            return (string) $novel[$key];
        }
    }
    $jobId = (string) ($novel['job_id'] ?? $novel['novel_key'] ?? $novel['id'] ?? '');
    return $jobId !== '' ? '/novels/export.txt?job_id=' . rawurlencode($jobId) : '#';
};

return [
    'novel_url' => $novelThemeUrl,
    'novel_chapter_url' => $novelThemeChapterUrl,
    'novel_search_url' => $novelThemeSearchUrl,
    'novel_bookshelf_url' => $novelThemeBookshelfUrl,
    'novel_txt_url' => $novelThemeTxtUrl,
];
