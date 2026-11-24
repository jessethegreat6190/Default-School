<?php
declare(strict_types=1);

function config(string $key, mixed $default = null): mixed {
    static $config;
    if (!$config) {
        $config = require __DIR__ . '/../../config/app.php';
    }
    $value = $config;
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }
    return $value;
}

function e(string|null $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function asset_path(string $path): string {
    $base = rtrim((string) config('app.base_url', ''), '/');
    return $base . '/' . ltrim($path, '/');
}
