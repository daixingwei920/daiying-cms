<?php

declare(strict_types=1);

$migration = static function (\PDO $pdo): void {
    $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    $auto = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $text = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';
    $json = $driver === 'sqlite' ? 'TEXT' : 'JSON';

    $pdo->exec("CREATE TABLE IF NOT EXISTS videos (
        id $auto,
        uuid VARCHAR(36) NOT NULL UNIQUE,
        type VARCHAR(32) NOT NULL,
        title VARCHAR(191) NOT NULL,
        original_title VARCHAR(191) NULL,
        slug VARCHAR(191) NOT NULL UNIQUE,
        description $text NULL,
        poster_media_id BIGINT NULL,
        backdrop_media_id BIGINT NULL,
        release_date DATE NULL,
        year INT NULL,
        region VARCHAR(64) NULL,
        language VARCHAR(32) NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'draft',
        season_count INT NOT NULL DEFAULT 0,
        episode_count INT NOT NULL DEFAULT 0,
        latest_episode_id BIGINT NULL,
        latest_episode_at DATETIME NULL,
        duration INT NULL,
        external_ids $json NULL,
        visibility VARCHAR(32) NOT NULL DEFAULT 'public',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_videos_type_year ON videos(type, year)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_videos_latest ON videos(latest_episode_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_videos_title_year ON videos(title, year)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS video_people (
        id $auto,
        uuid VARCHAR(36) NOT NULL UNIQUE,
        name VARCHAR(191) NOT NULL,
        slug VARCHAR(191) NOT NULL UNIQUE,
        role VARCHAR(32) NOT NULL DEFAULT 'actor',
        bio $text NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS video_seasons (
        id $auto,
        video_id BIGINT NOT NULL,
        season_number INT NOT NULL DEFAULT 1,
        title VARCHAR(191) NOT NULL,
        episode_count INT NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        UNIQUE(video_id, season_number)
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_video_seasons_video ON video_seasons(video_id, sort_order)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS video_episodes (
        id $auto,
        video_id BIGINT NOT NULL,
        season_id BIGINT NOT NULL,
        episode_number INT NOT NULL,
        title VARCHAR(191) NOT NULL,
        duration INT NULL,
        air_date DATE NULL,
        sort_order INT NOT NULL DEFAULT 0,
        UNIQUE(video_id, season_id, episode_number)
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_video_episodes_order ON video_episodes(video_id, season_id, sort_order)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS video_resource_providers (
        id $auto,
        name VARCHAR(191) NOT NULL,
        provider_type VARCHAR(32) NOT NULL,
        base_url VARCHAR(1024) NOT NULL,
        api_url VARCHAR(1024) NOT NULL,
        enabled INT NOT NULL DEFAULT 1,
        priority INT NOT NULL DEFAULT 100,
        request_interval INT NOT NULL DEFAULT 3,
        timeout INT NOT NULL DEFAULT 10,
        retry_count INT NOT NULL DEFAULT 2,
        last_sync_at DATETIME NULL,
        last_success_at DATETIME NULL,
        last_error $text NULL,
        config_encrypted $text NULL
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_video_providers_enabled ON video_resource_providers(enabled, priority)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS video_play_sources (
        id $auto,
        code VARCHAR(64) NOT NULL UNIQUE,
        display_name VARCHAR(191) NOT NULL,
        protocol VARCHAR(16) NOT NULL,
        priority INT NOT NULL DEFAULT 100,
        enabled INT NOT NULL DEFAULT 1,
        is_default INT NOT NULL DEFAULT 0,
        allow_frontend_switch INT NOT NULL DEFAULT 1,
        health_check_enabled INT NOT NULL DEFAULT 1,
        health_check_interval INT NOT NULL DEFAULT 3600
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS video_episode_play_urls (
        id $auto,
        video_id BIGINT NOT NULL,
        episode_id BIGINT NOT NULL,
        resource_provider_id BIGINT NOT NULL,
        play_source_id BIGINT NOT NULL,
        source_episode_id VARCHAR(191) NULL,
        url VARCHAR(2048) NOT NULL,
        url_type VARCHAR(16) NOT NULL,
        priority INT NOT NULL DEFAULT 100,
        enabled INT NOT NULL DEFAULT 1,
        content_hash VARCHAR(64) NOT NULL,
        last_checked_at DATETIME NULL,
        health_status VARCHAR(32) NOT NULL DEFAULT 'unknown',
        response_time INT NULL,
        UNIQUE(episode_id, play_source_id, content_hash)
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_video_play_urls_episode ON video_episode_play_urls(episode_id, enabled, priority)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_video_play_urls_health ON video_episode_play_urls(health_status, last_checked_at)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS video_category_mappings (
        id $auto,
        resource_provider_id BIGINT NOT NULL,
        source_category_id VARCHAR(191) NOT NULL,
        source_category_name VARCHAR(191) NOT NULL,
        cms_type VARCHAR(32) NOT NULL,
        cms_category VARCHAR(191) NULL,
        enabled INT NOT NULL DEFAULT 0,
        UNIQUE(resource_provider_id, source_category_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS video_watch_progress (
        id $auto,
        user_id BIGINT NOT NULL,
        video_id BIGINT NOT NULL,
        episode_id BIGINT NOT NULL,
        position_seconds INT NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL,
        UNIQUE(user_id, video_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS video_favorites (
        id $auto,
        user_id BIGINT NOT NULL,
        video_id BIGINT NOT NULL,
        notify_new_episode INT NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        UNIQUE(user_id, video_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS video_collector_jobs (
        id $auto,
        resource_provider_id BIGINT NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'pending',
        mode VARCHAR(32) NOT NULL DEFAULT 'incremental',
        checkpoint_json $json NULL,
        last_error $text NULL,
        attempts INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_video_collector_jobs_status ON video_collector_jobs(status, updated_at)");
};


$rollback = static function (\PDO $pdo): void {
    foreach ([
        'video_collector_jobs',
        'video_favorites',
        'video_watch_progress',
        'video_category_mappings',
        'video_episode_play_urls',
        'video_play_sources',
        'video_resource_providers',
        'video_episodes',
        'video_seasons',
        'video_people',
        'videos',
    ] as $table) {
        $pdo->exec('DROP TABLE IF EXISTS ' . $table);
    }
};

return ['up' => $migration, 'apply' => $migration, 'down' => $rollback, 'rollback' => $rollback];
