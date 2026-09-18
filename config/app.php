<?php
/**
 * Application configuration, built from .env values.
 * Access with config('db.host'), config('storage.driver'), ...
 */
return [
    'app' => [
        'name'     => env('APP_NAME', 'PHINMA COC Tabulation'),
        'school'   => env('APP_SCHOOL', 'PHINMA Cagayan de Oro College'),
        'timezone' => env('APP_TIMEZONE', 'Asia/Manila'),
        'debug'    => (bool) env('APP_DEBUG', false),
        // how often open pages check for changes; raise it on free hosting with a daily hit limit
        'live_seconds' => max(3, (int) env('LIVE_UPDATE_SECONDS', 4)),
    ],

    'db' => [
        'host' => env('DB_HOST', 'localhost'),
        'port' => (int) env('DB_PORT', 3306),
        'name' => env('DB_NAME', 'coc_tabulation'),
        'user' => env('DB_USER', 'root'),
        'pass' => env('DB_PASS', ''),
    ],

    'storage' => [
        'driver' => env('STORAGE_DRIVER', 'local'),
        'local'  => [
            'path' => env('STORAGE_LOCAL_PATH', 'storage/uploads'),
        ],
        'ftp' => [
            'host'    => env('FTP_HOST', '127.0.0.1'),
            'port'    => (int) env('FTP_PORT', 21),
            'user'    => env('FTP_USER', ''),
            'pass'    => env('FTP_PASS', ''),
            'root'    => env('FTP_ROOT', '/'),
            'passive' => (bool) env('FTP_PASSIVE', true),
            'ssl'     => (bool) env('FTP_SSL', false),
            'timeout' => (int) env('FTP_TIMEOUT', 20),
        ],
        'max_bytes' => (int) round((float) env('UPLOAD_MAX_MB', 15) * 1024 * 1024),
        'tmp'       => APP_ROOT . '/storage/tmp',
    ],

    'ocr' => [
        'tesseract_paths' => array_values(array_filter([
            env('TESSERACT_PATH', ''),
            'C:/Program Files/Tesseract-OCR/tesseract.exe',
            'C:/Program Files (x86)/Tesseract-OCR/tesseract.exe',
            '/usr/bin/tesseract',
            '/usr/local/bin/tesseract',
        ])),
        'ocr_space_key' => env('OCR_SPACE_API_KEY', ''),
    ],

    'security' => [
        'max_login_attempts' => (int) env('MAX_LOGIN_ATTEMPTS', 10),
    ],
];
