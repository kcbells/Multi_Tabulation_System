<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use finfo;

/** Validates a PHP upload ($_FILES entry) by size, extension and real content type. */
final class UploadedFile
{
    private const MIME_RULES = [
        'image' => ['jpg', 'jpeg', 'png', 'webp', 'bmp', 'tif', 'tiff', 'gif'],
    ];

    public readonly string $tmpPath;
    public readonly string $originalName;
    public readonly string $extension;
    public readonly int $size;
    public readonly string $mime;

    public function __construct(?array $file, array $allowedExtensions, int $maxBytes)
    {
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            throw new HttpException(in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'The file is too large.'
                : 'Choose a file to upload.', 422);
        }
        if ((int) $file['size'] > $maxBytes) {
            throw new HttpException('The file is larger than ' . round($maxBytes / 1048576, 1) . ' MB.', 422);
        }

        $this->tmpPath = (string) $file['tmp_name'];
        $this->originalName = mb_substr(basename(str_replace('\\', '/', (string) $file['name'])), 0, 255);
        $this->extension = strtolower(pathinfo($this->originalName, PATHINFO_EXTENSION));
        $this->size = (int) $file['size'];
        $this->mime = (new finfo(FILEINFO_MIME_TYPE))->file($this->tmpPath) ?: 'application/octet-stream';

        if (!in_array($this->extension, $allowedExtensions, true)) {
            throw new HttpException('Unsupported file type. Allowed: ' . strtoupper(implode(', ', $allowedExtensions)) . '.', 422);
        }
        if (!$this->contentMatchesExtension()) {
            throw new HttpException('The file content does not match its extension.', 422);
        }
    }

    private function contentMatchesExtension(): bool
    {
        if (in_array($this->extension, self::MIME_RULES['image'], true)) {
            return str_starts_with($this->mime, 'image/');
        }
        return match ($this->extension) {
            'pdf'  => $this->mime === 'application/pdf',
            'docx' => in_array($this->mime, [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/zip', 'application/octet-stream',
            ], true),
            'txt'  => str_starts_with($this->mime, 'text/') || $this->mime === 'application/x-empty',
            default => false,
        };
    }
}
