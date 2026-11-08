<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Booking;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Ticket QR codes (PLAN §6). The code holds only the staff check-in URL
 * (/checkin/{ticket_code}): no names or other personal data. SVG for web
 * pages, PNG for emails (many mail apps block SVG images).
 */
final class TicketQr
{
    public static function url(Booking $booking): string
    {
        return route('checkin.show', ['code' => $booking->ticket_code]);
    }

    public static function svg(Booking $booking): string
    {
        return (new QRCode(new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
            'eccLevel' => EccLevel::M,
            'drawLightModules' => false,
            'connectPaths' => true,
            'cssClass' => 'block h-auto w-full',
        ])))->render(self::url($booking));
    }

    /** Raw PNG bytes. */
    public static function png(Booking $booking, int $scale = 8): string
    {
        return (new QRCode(new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'outputBase64' => false,
            'eccLevel' => EccLevel::M,
            'scale' => $scale,
        ])))->render(self::url($booking));
    }
}
