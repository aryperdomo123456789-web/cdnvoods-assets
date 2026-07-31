<?php

final class Config
{
    private static ?array $base = null;

    public static function all(): array
    {
        if (self::$base !== null) {
            return self::$base;
        }

        $defaults = require dirname(__DIR__) . '/config/app.php';
        $override = [];
        $localPath = dirname(__DIR__) . '/storage/local.config.php';

        if (is_file($localPath)) {
            $loaded = require $localPath;
            if (is_array($loaded)) {
                $override = $loaded;
            }
        }

        self::$base = array_replace($defaults, $override);
        return self::$base;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }
}
