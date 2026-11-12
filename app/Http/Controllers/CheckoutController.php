<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Credit\AccountCredit;
use App\Actions\Discounts\ChangeOrderDiscounts;
use App\Actions\Discounts\CompleteCoveredOrder;
use App\Actions\Discounts\RepriceOrder;
use App\Actions\Orders\RecordCheckoutTracking;
use App\Actions\Payments\CreateStripeCheckout;
use App\Actions\Payments\TransferWindow;
use App\Enums\DiscountKind;
use App\Exceptions\BookingRejected;
use App\Exceptions\CheckoutUnavailable;
use App\Exceptions\DiscountRejected;
use App\Http\Middleware\EnsureOrderAccess;
use App\Models\User;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * /checkout/{reference} (PLAN §6): order summary, seat hold and the two
 * ways to pay. Card payment hands over to Stripe Checkout; the status page
 * only shows what the webhook has recorded. EnsureOrderAccess lets in the
 * signed-in owner or a guest with access to this order (guest checkout,
 * decided 2026-11-12); anyone else gets the same answer for every
 * reference. A guest order offers no account credit.
 */
final class CheckoutController extends Controller
{
    public function show(Request $request, string $reference, TransferWindow $window, RecordCheckoutTracking $tracking, AccountCredit $credit): View
    {
        $order = EnsureOrderAccess::order($request);

        $order->load(['product', 'items.slot', 'items.rider', 'holds', 'discounts']);
        $tracking->handle($order, $request);

        return view('checkout.show', [
            'order' => $order,
            'cardFailed' => $order->status->isUnpaid() && (int) ($order->meta['stripe_attempts'] ?? 0) > 0 && $order->stripe_checkout_session_id === null,
            'transferOffered' => $window->isOffered($order),
            // Discounts at checkout (milestone 10e Part B).
            'choices' => RepriceOrder::choices($order),
            // Never on a guest order (decided 2026-11-12): it's the account's money.
            'creditAvailable' => $order->placed_as_guest ? 0 : $credit->balance($order->user) + AccountCredit::heldBy($order),
            'guest' => $request->user()?->id !== $order->user_id,
            'cardOffered' => $order->amount_minor >= (int) config('services.stripe.min_charge_pkr', 200) * Money::MINOR_PER_MAJOR,
        ]);
    }

    /** A gift voucher or promo code (milestone 10e Part B). */
    public function discount(Request $request, string $reference, ChangeOrderDiscounts $change): RedirectResponse
    {
        $order = EnsureOrderAccess::order($request);
        $code = $request->validate(['code' => ['required', 'string', 'max:60']], ['code.required' => 'Enter a gift voucher or promo code.'])['code'];

        try {
            $kind = $change->applyCode($order, $this->customer($request), (string) $code);
        } catch (DiscountRejected $e) {
            return redirect()->route('checkout.show', $order->reference)->withErrors(['code' => $e->getMessage()])->withInput();
        }

        return redirect()->route('checkout.show', $order->reference)->with('discount_status', $kind === DiscountKind::Voucher ? 'Gift voucher applied.' : 'Promo code applied.');
    }

    public function removeDiscount(Request $request, string $reference, string $kind, ChangeOrderDiscounts $change): RedirectResponse
    {
        $order = EnsureOrderAccess::order($request);
        $kind = DiscountKind::tryFrom($kind) ?? abort(404);

        try {
            $change->remove($order, $this->customer($request), $kind);
        } catch (DiscountRejected $e) {
            return redirect()->route('checkout.show', $order->reference)->withErrors(['code' => $e->getMessage()]);
        }

        return redirect()->route('checkout.show', $order->reference)->with('discount_status', $kind->label().' removed.');
    }

    public function credit(Request $request, string $reference, ChangeOrderDiscounts $change): RedirectResponse
    {
        $order = EnsureOrderAccess::order($request);
        $use = $request->boolean('use');

        try {
            $change->useCredit($order, $this->customer($request), $use);
        } catch (DiscountRejected $e) {
            return redirect()->route('checkout.show', $order->reference)->withErrors(['code' => $e->getMessage()]);
        }

        return redirect()->route('checkout.show', $order->reference)->with('discount_status', $use ? 'Account credit applied.' : 'Account credit removed.');
    }

    /** Nothing left to pay: confirm the booking (CompleteCoveredOrder). */
    public function confirm(Request $request, string $reference, CompleteCoveredOrder $complete): RedirectResponse
    {
        $order = EnsureOrderAccess::order($request);

        try {
            $complete->handle($order, $this->customer($request));
        } catch (DiscountRejected|BookingRejected $e) {
            return redirect()->route('checkout.show', $order->reference)->with('checkout_error', $e->getMessage());
        }

        return redirect()->route('checkout.status', $order->reference);
    }

    /** The order's own customer: the signed-in owner, or the account behind a guest order. */
    private function customer(Request $request): User
    {
        return EnsureOrderAccess::order($request)->user;
    }

    public function card(Request $request, string $reference, CreateStripeCheckout $checkout): RedirectResponse
    {
        $order = EnsureOrderAccess::order($request);

        try {
            $url = $checkout->handle($order);
        } catch (CheckoutUnavailable $e) {
            return redirect()->route('checkout.show', $order->reference)->with('checkout_error', $e->getMessage());
        }

        return redirect()->away($url, 303);
    }

    public function status(Request $request, string $reference): View
    {
        $order = EnsureOrderAccess::order($request);

        return view('checkout.status', ['order' => $order, 'cancelled' => $request->boolean('cancelled')]);
    }
}
