<?php
declare(strict_types=1);

namespace App\Services;

use App\Documents\DocumentReader;
use App\Storage\StorageInterface;
use App\Storage\StorageManager;
use App\Storage\TempFile;
use RuntimeException;
use Throwable;

/**
 * Shared upload pipeline for scanned documents (criteria sheets, program flows):
 *   1. copy the upload to a local scratch file,
 *   2. read its text (PDF / DOCX / OCR) and run the caller's parser,
 *      retrying OCR with automatic page segmentation when nothing was found,
 *   3. send the original file to the file server,
 *   4. clean up the scratch file.
 */
final class DocumentIntake
{
    private StorageInterface $storage;
    private DocumentReader $reader;

    public function __construct(?StorageInterface $storage = null, ?DocumentReader $reader = null)
    {
        $this->storage = $storage ?? StorageManager::disk();
        $this->reader = $reader ?? DocumentReader::fromConfig();
    }

    /**
     * @param callable(string $text): array $parse     returns parsed rows (empty array = nothing found)
     * @return array{key:string, text:string, engine:string, warnings:string[], parsed:array}
     */
    public function intake(UploadedFile $upload, string $storageFolder, callable $parse): array
    {
        $scratch = TempFile::create($upload->extension);
        try {
            if (!move_uploaded_file($upload->tmpPath, $scratch)) {
                throw new RuntimeException('The uploaded file could not be processed.');
            }

            $read = $this->reader->read($scratch, $upload->extension, ['psm' => 6]);
            $parsed = $parse($read['text']);
            if (!self::found($parsed) && str_starts_with($read['engine'], 'Tesseract')) {
                $retry = $this->reader->read($scratch, $upload->extension, ['psm' => 3]);
                $retryParsed = $parse($retry['text']);
                if (self::found($retryParsed)) {
                    [$read, $parsed] = [$retry, $retryParsed];
                }
            }

            $key = sprintf('%s/%s-%s.%s', trim($storageFolder, '/'), date('Ymd-His'), bin2hex(random_bytes(6)), $upload->extension);
            $this->storage->put($key, $scratch);
        } finally {
            @unlink($scratch);
        }

        return ['key' => $key, 'text' => $read['text'], 'engine' => $read['engine'], 'warnings' => $read['warnings'], 'parsed' => $parsed];
    }

    public function deleteQuietly(?string $key): void
    {
        if (!$key) {
            return;
        }
        try {
            $this->storage->delete($key);
        } catch (Throwable $e) {
            error_log('[tabulation] could not delete ' . $key . ': ' . $e->getMessage());
        }
    }

    /** Streams a stored document to the browser (inline for images/PDF/text). */
    public function stream(string $key, ?string $downloadName): never
    {
        try {
            $local = $this->storage->download($key);
        } catch (Throwable $e) {
            throw new \App\Core\HttpException('The file is not available on the file server.', 404);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($local) ?: 'application/octet-stream';
        $inline = str_starts_with($mime, 'image/') || $mime === 'application/pdf' || $mime === 'text/plain';
        $name = str_replace(['"', "\r", "\n"], '', (string) ($downloadName ?: basename($key)));

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($local));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
        header('Cache-Control: private, max-age=300');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"');
        readfile($local);
        @unlink($local);
        exit;
    }

    private static function found(array $parsed): bool
    {
        if (isset($parsed['found'])) {
            return (bool) $parsed['found'];
        }
        return isset($parsed['criteria']) ? (bool) $parsed['criteria'] : (bool) $parsed;
    }
}
