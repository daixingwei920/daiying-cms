<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_08_12_000002_content_media_schema';
    }

    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $idColumn = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER PRIMARY KEY AUTO_INCREMENT';
        $longText = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_contents (
                id ' . $idColumn . ',
                content_type VARCHAR(64) NOT NULL,
                title VARCHAR(255) NOT NULL,
                slug VARCHAR(191) NOT NULL,
                status VARCHAR(32) NOT NULL,
                blocks_json ' . $longText . ' NOT NULL,
                meta_json ' . $longText . ' NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL,
                published_at VARCHAR(64) NULL
            )'
        );

        $pdo->exec('CREATE UNIQUE INDEX cms_contents_type_slug_unique ON cms_contents (content_type, slug)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_terms (
                id ' . $idColumn . ',
                taxonomy VARCHAR(64) NOT NULL,
                name VARCHAR(191) NOT NULL,
                slug VARCHAR(191) NOT NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );

        $pdo->exec('CREATE UNIQUE INDEX cms_terms_taxonomy_slug_unique ON cms_terms (taxonomy, slug)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_content_terms (
                content_id INTEGER NOT NULL,
                term_id INTEGER NOT NULL,
                created_at VARCHAR(64) NOT NULL,
                PRIMARY KEY (content_id, term_id)
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cms_media (
                id ' . $idColumn . ',
                storage_provider VARCHAR(64) NOT NULL,
                media_type VARCHAR(32) NOT NULL,
                mime_type VARCHAR(128) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                relative_path VARCHAR(512) NOT NULL,
                byte_size INTEGER NOT NULL,
                sha256_hash VARCHAR(64) NOT NULL,
                metadata_json ' . $longText . ' NULL,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL
            )'
        );

        $pdo->exec('CREATE UNIQUE INDEX cms_media_hash_unique ON cms_media (sha256_hash)');
    }
};
