<?php
/** Small global helpers used by views and bootstrap code. */
declare(strict_types=1);

use App\Core\Config;
use App\Core\Env;

function env(string $key, $default = null)
{
    return Env::get($key, $default);
}

function config(string $key, $default = null)
{
    return Config::get($key, $default);
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** URL path inside the app, e.g. base_url('assets/css/app.css') -> /Scoring/assets/css/app.css */
function base_url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        // Derived from the running script, not DOCUMENT_ROOT, so it also works behind an
        // Apache Alias (e.g. /Scoring -> C:/xampp_nen/htdocs/Scoring on Laragon).
        // SCRIPT_NAME "/Scoring/api/index.php" minus the script's path inside the app "/api/index.php" = "/Scoring"
        $root = str_replace('\\', '/', APP_ROOT);
        $script = str_replace('\\', '/', (string) (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: ''));
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = '';
        if ($script !== '' && stripos($script, $root . '/') === 0) {
            $inside = substr($script, strlen($root));
            if (strcasecmp(substr($scriptName, -strlen($inside)), $inside) === 0) {
                $base = substr($scriptName, 0, -strlen($inside));
            }
        }
        $base = rtrim('/' . trim($base, '/'), '/');
    }
    return $base . '/' . ltrim($path, '/');
}

/** Cache-busted asset URL. */
function asset(string $path): string
{
    $file = APP_ROOT . '/' . ltrim($path, '/');
    return base_url($path) . (is_file($file) ? '?v=' . filemtime($file) : '');
}
