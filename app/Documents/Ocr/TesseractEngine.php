<?php
declare(strict_types=1);

namespace App\Documents\Ocr;

use RuntimeException;

/** Local OCR using the Tesseract command-line program. */
final class TesseractEngine implements OcrEngineInterface
{
    private ?string $binary;

    public function __construct(array $candidatePaths)
    {
        $this->binary = null;
        foreach ($candidatePaths as $path) {
            if ($path && is_file($path)) {
                $this->binary = $path;
                break;
            }
        }
    }

    public function name(): string
    {
        return 'Tesseract OCR';
    }

    public function available(): bool
    {
        return $this->binary !== null && function_exists('proc_open');
    }

    public function supports(string $extension): bool
    {
        return in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'bmp', 'tif', 'tiff', 'gif'], true);
    }

    public function recognize(string $file, string $extension, array $options = []): string
    {
        if (!$this->available()) {
            throw new RuntimeException('Tesseract is not installed.');
        }
        $psm = (string) ($options['psm'] ?? 6);
        $cmd = [$this->binary, $file, 'stdout', '-l', 'eng', '--psm', $psm];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            throw new RuntimeException('Tesseract could not be started.');
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0 && trim($out) === '') {
            throw new RuntimeException('Tesseract failed: ' . trim(mb_substr($err, 0, 200)));
        }
        return trim($out);
    }
}
