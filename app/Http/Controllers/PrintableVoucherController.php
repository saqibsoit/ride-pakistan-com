<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Voucher;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\URL;

/**
 * /vouchers/{code} (milestone 10e): the printable gift voucher, linked
 * from the recipient's email. A signed URL (no expiry): the code alone
 * never opens the page, so codes can't be checked by guessing URLs.
 * Never cached or indexed.
 */
final class PrintableVoucherController extends Controller
{
    public function __invoke(Voucher $voucher): View
    {
        return view('gift-vouchers.printable', [
            'voucher' => $voucher->load('voucherOrder.user'),
        ]);
    }

    public static function url(Voucher $voucher): string
    {
        return URL::signedRoute('vouchers.show', ['voucher' => $voucher->code]);
    }
}
