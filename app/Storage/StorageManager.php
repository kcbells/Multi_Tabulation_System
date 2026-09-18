<?php
declare(strict_types=1);

namespace App\Storage;

use InvalidArgumentException;

/** Builds the configured file server driver (STORAGE_DRIVER in .env). */
final class StorageManager
{
    private static ?StorageInterface $disk = null;

    public static function disk(): StorageInterface
    {
        if (self::$disk !== null) {
            return self::$disk;
        }
        $driver = strtolower((string) config('storage.driver', 'local'));
        return self::$disk = match ($driver) {
            'local' => new LocalStorage((string) config('storage.local.path', 'storage/uploads')),
            'ftp'   => new FtpStorage((array) config('storage.ftp')),
            default => throw new InvalidArgumentException("Unknown STORAGE_DRIVER \"{$driver}\" (use local or ftp)."),
        };
    }
}
