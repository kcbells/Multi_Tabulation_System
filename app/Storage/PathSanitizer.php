<?php
declare(strict_types=1);

namespace App\Storage;

use InvalidArgumentException;

/** Rejects traversal and odd characters in storage keys. */
final class PathSanitizer
{
    public static function clean(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..' || !preg_match('/^[A-Za-z0-9._-]+$/', $part)) {
                throw new InvalidArgumentException('Invalid storage path.');
            }
            $parts[] = $part;
        }
        if (!$parts) {
            throw new InvalidArgumentException('Empty storage path.');
        }
        return implode('/', $parts);
    }
}
