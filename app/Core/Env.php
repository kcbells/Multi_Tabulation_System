<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Minimal .env loader.
 * KEY=value lines; "# comments"; values may be wrapped in single or double quotes.
 * Real environment variables (set on the server) take precedence over the file.
 */
final class Env
{
    private static array $values = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (!preg_match('/^[A-Z0-9_]+$/i', $key)) {
                continue;
            }
            $quoted = strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0];
            if ($quoted) {
                $value = substr($value, 1, -1);
            } elseif (($hash = strpos($value, ' #')) !== false) {
                $value = rtrim(substr($value, 0, $hash));
            }
            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, $default = null)
    {
        $server = getenv($key);
        $value = $server !== false ? $server : (self::$values[$key] ?? null);
        if ($value === null) {
            return $default;
        }
        switch (strtolower($value)) {
            case 'true':  return true;
            case 'false': return false;
            case 'null':  return null;
        }
        return $value;
    }
}
