<?php
declare(strict_types=1);

return [
    'app' => [
        'name' => 'SchoolGate',
        'tagline' => 'One system. Every child, accounted for.',
        'env' => 'local',
        'base_url' => '/schoolgate/public',
        'timezone' => 'Africa/Kampala',
    ],
    'school' => [
        'code' => 'default',
        'name' => 'Default School',
        'short_name' => 'School',
        'logo' => '/schoolgate/public/schools/default/logo.svg',
        'favicon' => '/schoolgate/public/schools/default/favicon.svg',
        'primary_color' => '#1f6feb',
        'secondary_color' => '#0b3d91',
        'accent_color' => '#f4b400',
    ],
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'schoolgate',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
];
