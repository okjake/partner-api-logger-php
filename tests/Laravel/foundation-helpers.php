<?php

declare(strict_types=1);

// `config_path()` comes from laravel/framework's Illuminate/Foundation/helpers.php,
// which no `illuminate/*` component ships. The provider's boot() calls it to
// declare its publishable config, so the provider tests need a stand-in.
if (!function_exists('config_path')) {
    function config_path(string $path = ''): string
    {
        return '/app/config' . ($path !== '' ? '/' . $path : '');
    }
}
