<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'PHP CMS',
        'version' => '1.2.21',
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
        'public_key' => 'h5vP/I/pAXWIz4GQ8h2LryHvyP+GW0Fc8AFEUHu0jms=',
        'server_url' => 'https://updates.daiyingcms.com',
    ],
    'payment' => [
        'fixture_provider_enabled' => false,
        'paid_download_token_ttl_seconds' => 86400,
        'paid_download_token_max_uses' => 0,
        'paid_content_token_ttl_seconds' => 2592000,
    ],
    'market' => [
        'enabled' => true,
        'developer_mode' => false,
        'server_url' => 'https://updates.daiyingcms.com',
        'channel' => 'stable',
        'site_token' => '',
    ],
    'review' => [
        'server_url' => 'https://updates.daiyingcms.com',
        'max_zip_bytes' => 20971520,
    ],
    'comments' => [
        'enabled' => true,
        'allow_guest' => true,
        'require_approval' => true,
    ],
    'mail' => [
        'from' => '',
        'smtp_host' => '',
    ],
    'security' => [
        'encryption_key' => '',
        'admin_mfa' => [
            'runtime_enforcement' => true,
            'implemented_methods' => ['totp', 'passkey', 'recovery_codes'],
            'reserved_methods' => ['totp', 'passkey', 'recovery_codes'],
        ],
        'hsts_enabled' => false,
        'hsts_max_age' => 31536000,
        'hsts_include_subdomains' => false,
        'hsts_preload' => false,
    ],
];
