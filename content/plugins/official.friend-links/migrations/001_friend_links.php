<?php

declare(strict_types=1);

return [
    'id' => 'friend_links_001_friend_links',
    'affected_objects' => [
        'table:friend_links_links',
    ],
    'up' => static function (PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_friend_links_links (
                id ' . $idColumn . ',
                name VARCHAR(191) NOT NULL,
                url VARCHAR(500) NOT NULL,
                logo_media_id INTEGER NULL,
                description TEXT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                status VARCHAR(32) NOT NULL DEFAULT \'enabled\',
                rel VARCHAR(191) NOT NULL DEFAULT \'noopener noreferrer\',
                target VARCHAR(32) NOT NULL DEFAULT \'_blank\',
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_friend_links_status_sort ON cms_friend_links_links(status, sort_order, id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_friend_links_url ON cms_friend_links_links(url)');
    },
    'down' => static function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS cms_friend_links_links');
    },
];
