<?php
declare(strict_types=1);

namespace App\Storage;

/**
 * File server contract. Paths are relative keys such as "criteria/12/act34_ab12.pdf".
 */
interface StorageInterface
{
    /** Copies a local file to the file server under $path. */
    public function put(string $path, string $localFile): void;

    /** Downloads the stored file to a local temporary path and returns it (caller deletes). */
    public function download(string $path): string;

    /** Streams the stored file to the output buffer. */
    public function output(string $path): void;

    public function exists(string $path): bool;

    public function size(string $path): int;

    public function delete(string $path): void;

    /** Human-readable name of the backend, e.g. "Local folder D:/files". */
    public function describe(): string;
}
