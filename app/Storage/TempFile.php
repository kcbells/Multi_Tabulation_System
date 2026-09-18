<?php
declare(strict_types=1);

namespace App\Storage;

use RuntimeException;

/** Scratch files used while processing uploads (OCR) before they go to the file server. */
final class TempFile
{
    public static function create(string $extension = ''): string
    {
        $dir = (string) config('storage.tmp', sys_get_temp_dir());
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $dir = sys_get_temp_dir();
        }
        $ext = preg_replace('/[^a-z0-9]/i', '', $extension);
        $path = rtrim($dir, '/\\') . '/' . bin2hex(random_bytes(12)) . ($ext !== '' ? '.' . strtolower($ext) : '');
        if (@touch($path) === false) {
            throw new RuntimeException('Cannot create a temporary file.');
        }
        return $path;
    }
}
