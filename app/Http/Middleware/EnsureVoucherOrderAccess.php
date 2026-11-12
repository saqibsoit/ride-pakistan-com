<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\VoucherOrder;
use App\Support\GuestAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gift voucher purchase pages (/gift-vouchers/{GV-…}/…, guest checkout):
 * the signed-in buyer, or a visitor with a current grant for that one
 * purchase (bought in this browser, or opened from its signed link).
 * Anyone else gets the same answer for every reference, like
 * EnsureOrderAccess: signed out, the sign-in page; signed in, 404.
 */
final class EnsureVoucherOrderAccess
{
    public const ATTRIBUTE = 'voucher_order';

    public function __construct(private readonly GuestAccess $guests) {}

    public function handle(Request $request, Closure $next): Response
    {
        $reference = $request->route('reference');
        $user = $request->user();
        $order = is_string($reference) ? $this->guests->findVoucherOrder($user instanceof User ? $user : null, $reference) : null;

        if ($order === null) {
            if (! $user instanceof User) {
                redirect()->setIntendedUrl($request->fullUrl());

                return redirect()->route('login');
            }

            abort(404);
        }

        $request->attributes->set(self::ATTRIBUTE, $order);

        return $next($request);
    }

    public static function order(Request $request): VoucherOrder
    {
        $order = $request->attributes->get(self::ATTRIBUTE);

        abort_unless($order instanceof VoucherOrder, 404);

        return $order;
    }
}
