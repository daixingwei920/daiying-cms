<?php

declare(strict_types=1);

$videoThemeUrl = static function (array $item): string {
    foreach (['permalink', 'url', 'canonical'] as $key) {
        if (is_string($item[$key] ?? null) && $item[$key] !== '') {
            return (string) $item[$key];
        }
    }
    $id = (string) ($item['id'] ?? $item['video_id'] ?? '');
    return $id !== '' ? '/videos/detail?id=' . rawurlencode($id) : '/videos';
};

$videoThemeEpisodeUrl = static function (array $episode, array $video = []): string {
    foreach (['play_url', 'permalink', 'url', 'canonical'] as $key) {
        if (is_string($episode[$key] ?? null) && $episode[$key] !== '') {
            return (string) $episode[$key];
        }
    }
    $id = (string) ($episode['id'] ?? $episode['episode_id'] ?? '');
    if ($id !== '') {
        return '/videos/watch?episode_id=' . rawurlencode($id);
    }
    $videoId = (string) ($video['id'] ?? $video['video_id'] ?? '');
    return $videoId !== '' ? '/videos/detail?id=' . rawurlencode($videoId) : '/videos';
};

$videoThemeSearchUrl = static fn (): string => '/videos/search';
$videoThemeTypeUrl = static fn (string $type): string => '/videos?type=' . rawurlencode($type);

return [
    'video_url' => $videoThemeUrl,
    'video_episode_url' => $videoThemeEpisodeUrl,
    'video_search_url' => $videoThemeSearchUrl,
    'video_type_url' => $videoThemeTypeUrl,
];
