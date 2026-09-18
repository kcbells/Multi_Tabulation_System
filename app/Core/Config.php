<?php
declare(strict_types=1);

namespace App\Core;

/** Read-only access to config/app.php using dot notation. */
final class Config
{
    private static array $items = [];

    public static function load(string $file): void
    {
        self::$items = require $file;
    }

    public static function get(string $key, $default = null)
    {
        $value = self::$items;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }
}
