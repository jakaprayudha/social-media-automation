<?php
declare(strict_types=1);

// Copy to local.php outside the public document root. Never commit local.php.
return [
    'environment' => 'production', // development, testing, production
    'base_url' => 'https://social.example.com',
    'app_key' => '', // Generate with: php bin/console.php key:generate
    'storage_path' => dirname(__DIR__) . '/var',
    'session_idle_seconds' => 1800,
    'session_lifetime_seconds' => 28800,
    'reset_lifetime_seconds' => 1800,
    'mail' => [
        'transport' => 'smtp', // file is allowed only in development
        'from_address' => '',
        'from_name' => 'Ruang Social',
        'host' => '',
        'port' => 587,
        'encryption' => 'starttls', // starttls or tls; no plaintext SMTP
        'username' => '',
        'password' => '',
        'timeout_seconds' => 15,
    ],
];
