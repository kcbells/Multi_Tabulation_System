<?php
declare(strict_types=1);

namespace App\Documents\Ocr;

use CURLFile;
use RuntimeException;

/** Cloud OCR (https://ocr.space) — used for scanned PDFs and when Tesseract is missing. */
final class OcrSpaceEngine implements OcrEngineInterface
{
    public function __construct(private string $apiKey) {}

    public function name(): string
    {
        return 'OCR.space';
    }

    public function available(): bool
    {
        return $this->apiKey !== '' && function_exists('curl_init');
    }

    public function supports(string $extension): bool
    {
        return in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'bmp', 'tif', 'tiff', 'gif', 'pdf'], true);
    }

    public function recognize(string $file, string $extension, array $options = []): string
    {
        if (!$this->available()) {
            throw new RuntimeException('online OCR is not configured');
        }
        if ($this->apiKey === 'helloworld' && filesize($file) > 1024 * 1024) {
            throw new RuntimeException('file is larger than the 1 MB limit of the demo key');
        }
        $mime = $extension === 'pdf' ? 'application/pdf' : (mime_content_type($file) ?: 'image/jpeg');
        $ch = curl_init('https://api.ocr.space/parse/image');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 0,          // no total limit on a slow connection
            CURLOPT_CONNECTTIMEOUT => 60,
            CURLOPT_LOW_SPEED_LIMIT => 1,         // …but stop if nothing at all moves for 5 minutes
            CURLOPT_LOW_SPEED_TIME  => 300,
            CURLOPT_HTTPHEADER     => ['apikey: ' . $this->apiKey],
            CURLOPT_POSTFIELDS     => [
                'file'      => new CURLFile($file, $mime, 'criteria.' . $extension),
                'language'  => 'eng',
                'isTable'   => 'true',
                'scale'     => 'true',
                'OCREngine' => '2',
            ],
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new RuntimeException($error ?: 'network error');
        }
        $json = json_decode((string) $response, true);
        if (!is_array($json)) {
            throw new RuntimeException('unexpected response');
        }
        if (!empty($json['IsErroredOnProcessing'])) {
            $msg = $json['ErrorMessage'] ?? 'processing error';
            throw new RuntimeException(is_array($msg) ? implode(' ', $msg) : (string) $msg);
        }
        $text = '';
        foreach ($json['ParsedResults'] ?? [] as $result) {
            $text .= ($result['ParsedText'] ?? '') . "\n";
        }
        return trim($text);
    }
}
