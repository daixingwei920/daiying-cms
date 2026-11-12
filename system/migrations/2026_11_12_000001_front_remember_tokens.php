<?php

declare(strict_types=1);

use Cms\Core\Migration\MigrationInterface;

return new class implements MigrationInterface {
    public function id(): string
    {
        return '2026_11_12_000001_front_remember_tokens';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS cms_front_remember_tokens (
            token_hash VARCHAR(64) PRIMARY KEY,
            user_id INTEGER NOT NULL,
            expires_at BIGINT NOT NULL,
            created_at VARCHAR(64) NOT NULL
        )');
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('CREATE INDEX IF NOT EXISTS cms_front_remember_tokens_user_idx ON cms_front_remember_tokens (user_id)');
        } else {
            $index = $pdo->query("SHOW INDEX FROM cms_front_remember_tokens WHERE Key_name = 'cms_front_remember_tokens_user_idx'");
            if ($index === false || $index->fetch() === false) {
                $pdo->exec('CREATE INDEX cms_front_remember_tokens_user_idx ON cms_front_remember_tokens (user_id)');
            }
        }
    }
};
