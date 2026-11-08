<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Orders\RecordCheckoutTracking;
use App\Actions\Payments\CreateStripeCheckout;
use App\Actions\Payments\TransferWindow;
use App\Exceptions\CheckoutUnavailable;
use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * /checkout/{reference} (PLAN §6): order summary, seat hold and the two
 * ways to pay. Card payment hands over to Stripe Checkout; the status page
 * only shows what the webhook has recorded. Scoped to the signed-in
 * customer: another customer's reference is a 404.
 */
final class CheckoutController extends Controller
{
    public function show(Request $request, string $reference, TransferWindow $window, RecordCheckoutTracking $tracking): View
    {
        $order = $this->order($request, $reference);

        Gate::authorize('view', $order);

        $order->load(['product', 'items.slot', 'items.rider', 'holds']);
        $tracking->handle($order, $request);

        return view('checkout.show', [
            'order' => $order,
            'cardFailed' => $order->status->isUnpaid() && (int) ($order->meta['stripe_attempts'] ?? 0) > 0 && $order->stripe_checkout_session_id === null,
            'transferOffered' => $window->isOffered($order),
        ]);
    }

    public function card(Request $request, string $reference, CreateStripeCheckout $checkout): RedirectResponse
    {
        $order = $this->order($request, $reference);

        Gate::authorize('pay', $order);

        try {
            $url = $checkout->handle($order);
        } catch (CheckoutUnavailable $e) {
            return redirect()->route('checkout.show', $order->reference)->with('checkout_error', $e->getMessage());
        }

        return redirect()->away($url, 303);
    }

    public function status(Request $request, string $reference): View
    {
        $order = $this->order($request, $reference);

        Gate::authorize('view', $order);

        return view('checkout.status', ['order' => $order, 'cancelled' => $request->boolean('cancelled')]);
    }

    private function order(Request $request, string $reference): Order
    {
        /** @var User $user */
        $user = $request->user();

        return $user->orders()->where('reference', $reference)->firstOrFail();
    }
}
