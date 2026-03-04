<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Service;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class QrCodeService {
    /**
     * Generate a QR code as an inline SVG string.
     */
    public function generateSvg(string $data, int $scale = 6): string {
        $options = new QROptions([
            'outputType' => QRCode::OUTPUT_MARKUP_SVG,
            'svgViewBoxSize' => null,
            'addQuietzone' => true,
            'quietzoneSize' => 2,
            'scale' => $scale,
            'drawLightModules' => false,
            'svgDefs' => '',
        ]);

        return (new QRCode($options))->render($data);
    }
}
