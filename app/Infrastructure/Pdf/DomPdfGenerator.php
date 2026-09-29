<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdf;

use App\Domain\Contracts\PdfGenerator;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * PDFs via barryvdh/laravel-dompdf.
 *
 * Remote resources stay disabled (the package default): everything a letter
 * needs — including the QR image — is inlined as a data URI, so rendering never
 * reaches out to the network. That matters on an air-gapped institute LAN.
 */
class DomPdfGenerator implements PdfGenerator
{
    public function fromView(string $view, array $data = [], string $paper = 'a4', string $orientation = 'portrait'): string
    {
        return Pdf::loadView($view, $data)
            ->setPaper($paper, $orientation)
            ->setOption('isRemoteEnabled', false)
            ->output();
    }
}
