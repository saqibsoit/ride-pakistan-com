<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Order;
use App\Models\User;
use App\Support\GuestAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checkout pages for one order (/checkout/{reference}/…, guest checkout
 * decided 2026-11-12). Allowed for the signed-in customer who owns the
 * order, or a visitor whose session holds a current grant for it
 * (GuestAccess: placed in this browser, or opened from a manage link that
 * hasn't been revoked or run out). The order goes on the request as
 * `order` for the controller.
 *
 * Anyone else gets the same answer for every reference: signed out, the
 * sign-in page; signed in, 404. A reference never confirms that an order
 * exists.
 */
final class EnsureOrderAccess
{
    public const ATTRIBUTE = 'order';

    public function __construct(private readonly GuestAccess $guests) {}

    /**
     * @param  string  $mode  "checkout" (sign-in page / 404) or "manage" (the "ask for a new link" page)
     */
    public function handle(Request $request, Closure $next, string $mode = 'checkout'): Response
    {
        $reference = $request->route('reference');
        $user = $request->user();
        $order = is_string($reference) ? $this->guests->findOrder($user instanceof User ? $user : null, $reference) : null;

        if ($order === null && $mode === 'manage') {
            return response()->view('manage.link', ['closed' => true], 403);
        }

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

    public static function order(Request $request): Order
    {
        $order = $request->attributes->get(self::ATTRIBUTE);

        abort_unless($order instanceof Order, 404);

        return $order;
    }
}
