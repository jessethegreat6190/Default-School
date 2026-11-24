<?php
declare(strict_types=1);

final class Theme
{
    public static function schoolCode(): string
    {
        $code = (string) config('school.code', 'default');
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $code) ?: 'default';
    }

    public static function cssFiles(): array
    {
        $code = self::schoolCode();
        $files = ['assets/css/core/base.css', 'assets/css/core/components.css'];
        $theme = "assets/css/themes/{$code}/theme.css";
        $default = 'assets/css/themes/default/theme.css';
        $files[] = file_exists(dirname(__DIR__, 2) . '/public/' . $theme) ? $theme : $default;
        return $files;
    }

    public static function jsFiles(): array
    {
        $code = self::schoolCode();
        $files = ['assets/js/core/app.js'];
        $theme = "assets/js/themes/{$code}/theme.js";
        $default = 'assets/js/themes/default/theme.js';
        $files[] = file_exists(dirname(__DIR__, 2) . '/public/' . $theme) ? $theme : $default;
        return $files;
    }
}
