<?php
declare(strict_types=1);

namespace App\Documents;

use App\Documents\Ocr\OcrEngineInterface;
use App\Documents\Ocr\OcrSpaceEngine;
use App\Documents\Ocr\TesseractEngine;
use InvalidArgumentException;
use Throwable;

/**
 * Reads the text of an uploaded criteria document and picks the right strategy:
 *   .docx  -> DocxTextExtractor
 *   .pdf   -> PdfTextExtractor, then OCR engines when the PDF is a scan
 *   images -> OCR engines in order (Tesseract first, OCR.space fallback)
 *   .txt   -> as-is
 */
final class DocumentReader
{
    public const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp', 'bmp', 'tif', 'tiff', 'gif'];
    public const ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'webp', 'bmp', 'tif', 'tiff', 'gif', 'pdf', 'docx', 'txt'];

    /** @param OcrEngineInterface[] $ocrEngines in priority order */
    public function __construct(private array $ocrEngines) {}

    public static function fromConfig(): self
    {
        return new self([
            new TesseractEngine((array) config('ocr.tesseract_paths', [])),
            new OcrSpaceEngine((string) config('ocr.ocr_space_key', '')),
        ]);
    }

    public function hasLocalOcr(): bool
    {
        foreach ($this->ocrEngines as $engine) {
            if ($engine instanceof TesseractEngine && $engine->available()) {
                return true;
            }
        }
        return false;
    }

    /** @return array{text:string, engine:string, warnings:string[]} */
    public function read(string $file, string $extension, array $options = []): array
    {
        $ext = strtolower($extension);
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            throw new InvalidArgumentException('Unsupported file type.');
        }

        if ($ext === 'txt') {
            return $this->result((string) file_get_contents($file), 'Plain text');
        }
        if ($ext === 'docx') {
            return $this->result((new DocxTextExtractor())->extract($file), 'Word document reader');
        }

        $warnings = [];
        if ($ext === 'pdf') {
            try {
                $text = self::clean(PdfTextExtractor::extract($file));
                if (self::meaningful($text)) {
                    return ['text' => $text, 'engine' => 'PDF text reader', 'warnings' => []];
                }
            } catch (Throwable $e) {
                $warnings[] = 'The PDF text layer could not be read: ' . $e->getMessage();
            }
        }

        foreach ($this->ocrEngines as $engine) {
            if (!$engine->supports($ext) || !$engine->available()) {
                continue;
            }
            try {
                $text = self::clean($engine->recognize($file, $ext, $options));
                if ($text !== '') {
                    return ['text' => $text, 'engine' => $engine->name() . ($ext === 'pdf' ? ' (scanned PDF)' : ''), 'warnings' => $warnings];
                }
            } catch (Throwable $e) {
                $warnings[] = $engine->name() . ': ' . $e->getMessage();
            }
        }

        $warnings[] = $ext === 'pdf'
            ? 'This PDF looks scanned and could not be read. Upload a clear photo (JPG/PNG) of the criteria instead, or type the criteria manually.'
            : 'No text could be read from the image. Try a clearer, well-lit photo taken straight on.';
        return ['text' => '', 'engine' => 'none', 'warnings' => $warnings];
    }

    private function result(string $text, string $engine): array
    {
        return ['text' => self::clean($text), 'engine' => $engine, 'warnings' => []];
    }

    private static function meaningful(string $text): bool
    {
        return preg_match_all('/[A-Za-z]{2,}/', $text) >= 3;
    }

    public static function clean(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        $text = str_replace(["\r\n", "\r", "\xC2\xA0", "\f"], ["\n", "\n", ' ', "\n"], $text);
        $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? $text;
        $text = preg_replace("/[ ]+\n/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }
}
