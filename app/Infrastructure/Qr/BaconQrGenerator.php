<?php

declare(strict_types=1);

namespace App\Infrastructure\Qr;

use App\Domain\Contracts\QrGenerator;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * QR codes via bacon/bacon-qr-code, rendered as SVG.
 *
 * SVG rather than PNG so no image extension (GD / Imagick) is required on the
 * server, and the code stays sharp when the letter is printed.
 */
class BaconQrGenerator implements QrGenerator
{
    public function svg(string $data, int $size = 200): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle($size, 1),
            new SvgImageBackEnd,
        ));

        return $writer->writeString($data);
    }

    public function dataUri(string $data, int $size = 200): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($this->svg($data, $size));
    }
}
