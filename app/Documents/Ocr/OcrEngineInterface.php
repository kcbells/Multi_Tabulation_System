<?php
declare(strict_types=1);

namespace App\Documents\Ocr;

interface OcrEngineInterface
{
    public function name(): string;

    public function available(): bool;

    /** Whether the engine can read this file extension (e.g. "png", "pdf"). */
    public function supports(string $extension): bool;

    /**
     * @param array $options engine-specific hints (e.g. ['psm' => 6])
     * @return string recognized text
     * @throws \RuntimeException when recognition fails
     */
    public function recognize(string $file, string $extension, array $options = []): string;
}
