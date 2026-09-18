<?php
declare(strict_types=1);

namespace App\Storage;

use RuntimeException;

/**
 * Stores files in a directory: a folder on this server, a mapped drive, or a
 * UNC network share on a file server (e.g. \\FILESERVER\tabulation).
 */
final class LocalStorage implements StorageInterface
{
    private string $root;

    public function __construct(string $root)
    {
        $isAbsolute = preg_match('#^([A-Za-z]:[\\\\/]|[\\\\/]{2}|/)#', $root) === 1;
        $root = $isAbsolute ? $root : APP_ROOT . '/' . $root;
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        // UNC paths must keep their leading double slash
        if (str_starts_with($root, '\\\\')) {
            $this->root = '//' . ltrim($this->root, '/');
        }
    }

    private function full(string $path): string
    {
        return $this->root . '/' . PathSanitizer::clean($path);
    }

    public function put(string $path, string $localFile): void
    {
        $target = $this->full($path);
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('The file server folder is not writable: ' . $this->root);
        }
        if (!@copy($localFile, $target)) {
            throw new RuntimeException('Could not save the file to the file server.');
        }
    }

    public function download(string $path): string
    {
        $source = $this->full($path);
        if (!is_file($source)) {
            throw new RuntimeException('File not found on the file server.');
        }
        $tmp = TempFile::create(pathinfo($path, PATHINFO_EXTENSION));
        if (!@copy($source, $tmp)) {
            throw new RuntimeException('Could not read the file from the file server.');
        }
        return $tmp;
    }

    public function output(string $path): void
    {
        $source = $this->full($path);
        if (!is_file($source)) {
            throw new RuntimeException('File not found on the file server.');
        }
        readfile($source);
    }

    public function exists(string $path): bool
    {
        return is_file($this->full($path));
    }

    public function size(string $path): int
    {
        $f = $this->full($path);
        return is_file($f) ? (int) filesize($f) : 0;
    }

    public function delete(string $path): void
    {
        $f = $this->full($path);
        if (is_file($f)) {
            @unlink($f);
        }
    }

    public function describe(): string
    {
        return 'Folder ' . $this->root;
    }
}
