<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'PHP CMS',
        'version' => '1.2.0',
        'debug' => false,
        'mode' => 'NORMAL',
        'secure_cookies' => false,
    ],
    'site' => [
        'name' => 'PHP CMS',
        'url' => '',
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
        'dsn' => '',
        'username' => '',
        'password' => '',
        'options' => [],
    ],
    'updates' => [
        'public_key' => '',
        'server_url' => '',
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
        'encryption_key' => '',
        'admin_mfa' => [
            'runtime_enforcement' => true,
            'implemented_methods' => ['totp', 'recovery_codes'],
            'reserved_methods' => ['totp', 'passkey', 'recovery_codes'],
        ],
        'hsts_enabled' => false,
        'hsts_max_age' => 31536000,
        'hsts_include_subdomains' => false,
        'hsts_preload' => false,
    ],
];
