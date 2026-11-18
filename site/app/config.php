<?php
// Copy this file to config.local.php for server-specific values before running /install.php.
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'DBNAME',
        'user' => 'DBUSER',
        'pass' => 'DBPASS',
    ],
    // Full site URL without trailing slash, e.g. https://example.com
    'base_url' => 'https://example.com',
    // Fresh installations only. Generate a random 32-byte hex token and clear it after setup.
    'install_token' => '',
    'timezone' => 'Europe/Istanbul',
    'posts_per_page' => 12,
    // Set to true while developing to see errors
    'debug' => false,
];
