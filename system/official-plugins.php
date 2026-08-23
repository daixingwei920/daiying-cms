<?php

declare(strict_types=1);

return [
    'official.friend-links' => [
        'directory' => 'official.friend-links',
        'package_type' => 'plugin',
        'type' => 'system-plugin',
        'bundled' => true,
        'trust_level' => 'trusted_php',
        'capability_namespaces' => ['friend_links'],
        'table_prefixes' => ['friend_links_'],
    ],
];