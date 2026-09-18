<?php
declare(strict_types=1);

namespace App\Storage;

use RuntimeException;

/** Stores files on a remote FTP / FTPS file server. */
final class FtpStorage implements StorageInterface
{
    /** @var resource|\FTP\Connection|null */
    private $conn = null;

    public function __construct(private array $cfg)
    {
        if (!function_exists('ftp_connect')) {
            throw new RuntimeException('The PHP FTP extension is not enabled on this server.');
        }
    }

    public function __destruct()
    {
        if ($this->conn) {
            @ftp_close($this->conn);
        }
    }

    private function connection()
    {
        if ($this->conn) {
            return $this->conn;
        }
        $host = (string) $this->cfg['host'];
        $port = (int) ($this->cfg['port'] ?: 21);
        $timeout = (int) ($this->cfg['timeout'] ?: 20);
        $conn = !empty($this->cfg['ssl']) && function_exists('ftp_ssl_connect')
            ? @ftp_ssl_connect($host, $port, $timeout)
            : @ftp_connect($host, $port, $timeout);
        if (!$conn) {
            throw new RuntimeException("Cannot connect to the file server ({$host}:{$port}).");
        }
        if (!@ftp_login($conn, (string) $this->cfg['user'], (string) $this->cfg['pass'])) {
            @ftp_close($conn);
            throw new RuntimeException('The file server rejected the FTP username or password.');
        }
        ftp_pasv($conn, (bool) $this->cfg['passive']);
        @ftp_set_option($conn, FTP_TIMEOUT_SEC, max($timeout, 3600)); // big files over a slow link
        return $this->conn = $conn;
    }

    private function remote(string $path): string
    {
        return rtrim((string) ($this->cfg['root'] ?: '/'), '/') . '/' . PathSanitizer::clean($path);
    }

    private function ensureDir(string $dir): void
    {
        $conn = $this->connection();
        $current = '';
        foreach (explode('/', trim($dir, '/')) as $part) {
            if ($part === '') {
                continue;
            }
            $current .= '/' . $part;
            if (!@ftp_chdir($conn, $current)) {
                if (!@ftp_mkdir($conn, $current)) {
                    throw new RuntimeException("Cannot create folder {$current} on the file server.");
                }
            }
        }
        @ftp_chdir($conn, '/');
    }

    public function put(string $path, string $localFile): void
    {
        $remote = $this->remote($path);
        $this->ensureDir(dirname($remote));
        if (!@ftp_put($this->connection(), $remote, $localFile, FTP_BINARY)) {
            throw new RuntimeException('Upload to the file server failed.');
        }
    }

    public function download(string $path): string
    {
        $tmp = TempFile::create(pathinfo($path, PATHINFO_EXTENSION));
        if (!@ftp_get($this->connection(), $tmp, $this->remote($path), FTP_BINARY)) {
            @unlink($tmp);
            throw new RuntimeException('File not found on the file server.');
        }
        return $tmp;
    }

    public function output(string $path): void
    {
        $tmp = $this->download($path);
        readfile($tmp);
        @unlink($tmp);
    }

    public function exists(string $path): bool
    {
        return $this->size($path) !== -1;
    }

    public function size(string $path): int
    {
        return (int) @ftp_size($this->connection(), $this->remote($path));
    }

    public function delete(string $path): void
    {
        @ftp_delete($this->connection(), $this->remote($path));
    }

    public function describe(): string
    {
        return 'FTP ' . $this->cfg['host'] . rtrim((string) $this->cfg['root'], '/');
    }
}
