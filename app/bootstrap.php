<?php
/**
 * Application bootstrap: autoloader, .env, config, timezone and session.
 * Every page, API request and CLI script requires this file first.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

// PSR-4 style autoloader: App\Core\Database -> app/Core/Database.php
spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) {
        return;
    }
    $file = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

App\Core\Env::load(APP_ROOT . '/.env');
require APP_ROOT . '/app/helpers.php';
App\Core\Config::load(APP_ROOT . '/config/app.php');

date_default_timezone_set((string) config('app.timezone', 'Asia/Manila'));

if (PHP_SAPI !== 'cli') {
    App\Core\Session::start();
}
