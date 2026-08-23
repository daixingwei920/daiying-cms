<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'PHP CMS',
        'version' => '1.2.0',
        'debug' => false,
        'mode' => 'NORMAL',
        'secure_cookies' => true,
    ],
    'site' => [
        'name' => 'My CMS Site',
        'url' => 'https://example.com',
    ],
    'seo' => [
        'robots_index' => true,
    ],
    'theme' => [
        'active' => 'default',
        'settings' => [
            'default' => [
                'accent_color' => '#1f6feb',
            ],
        ],
    ],
    'database' => [
        'dsn' => 'mysql:host=127.0.0.1;dbname=php_cms;charset=utf8mb4',
        'username' => 'cms_user',
        'password' => 'change-me',
        'options' => [],
    ],
    'updates' => [
        'public_key' => "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----",
        'server_url' => 'https://updates.example.com',
    ],
    'payment' => [
        'fixture_provider_enabled' => false,
        'paid_download_token_ttl_seconds' => 86400,
        'paid_download_token_max_uses' => 0,
        'paid_content_token_ttl_seconds' => 2592000,
    ],
    'market' => [
        'enabled' => false,
        'developer_mode' => false,
    ],
    'security' => [
        'encryption_key' => 'change-me-generate-a-random-32-byte-secret',
        'admin_mfa' => [
            'runtime_enforcement' => true,
            'implemented_methods' => ['totp', 'recovery_codes'],
            'reserved_methods' => ['totp', 'passkey', 'recovery_codes'],
        ],
        'hsts_enabled' => true,
        'hsts_max_age' => 31536000,
        'hsts_include_subdomains' => true,
        'hsts_preload' => false,
    ],
];
