<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

/**
 * Renders a Blade view to PDF bytes. PLAN.md section 5: kept abstract so the
 * rendering library can be swapped without touching callers.
 */
interface PdfGenerator
{
    /**
     * @param  array<string, mixed>  $data
     * @return string Raw PDF bytes.
     */
    public function fromView(string $view, array $data = [], string $paper = 'a4', string $orientation = 'portrait'): string;
}
