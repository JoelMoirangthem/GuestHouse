<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

/**
 * Encodes a string as a QR code image. PLAN.md section 5: kept abstract so the
 * library can be swapped without touching the letter.
 */
interface QrGenerator
{
    /**
     * @return string An SVG document.
     */
    public function svg(string $data, int $size = 200): string;

    /**
     * The same image as a data URI, ready for an <img src> in HTML or a PDF.
     */
    public function dataUri(string $data, int $size = 200): string;
}
