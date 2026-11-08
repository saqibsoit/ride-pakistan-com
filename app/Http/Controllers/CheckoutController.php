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
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
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
    public function show(Request $request, string $reference, TransferWindow $window, RecordCheckoutTracking $tracking, AccountCredit $credit): View
    {
        $order = $this->order($request, $reference);

        Gate::authorize('view', $order);

        $order->load(['product', 'items.slot', 'items.rider', 'holds']);
        $tracking->handle($order, $request);

        /** @var User $user */
        $user = $request->user();
        $order->load('discounts');

        return view('checkout.show', [
            'order' => $order,
            'cardFailed' => $order->status->isUnpaid() && (int) ($order->meta['stripe_attempts'] ?? 0) > 0 && $order->stripe_checkout_session_id === null,
            'transferOffered' => $window->isOffered($order),
            // Discounts at checkout (milestone 10e Part B).
            'choices' => RepriceOrder::choices($order),
            'creditAvailable' => $credit->balance($user) + AccountCredit::heldBy($order),
            'cardOffered' => $order->amount_minor >= (int) config('services.stripe.min_charge_pkr', 200) * Money::MINOR_PER_MAJOR,
        ]);
    }

    /** A gift voucher or promo code (milestone 10e Part B). */
    public function discount(Request $request, string $reference, ChangeOrderDiscounts $change): RedirectResponse
    {
        $order = $this->order($request, $reference);
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
        $order = $this->order($request, $reference);
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
        $order = $this->order($request, $reference);
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
        $order = $this->order($request, $reference);

        try {
            $complete->handle($order, $this->customer($request));
        } catch (DiscountRejected|BookingRejected $e) {
            return redirect()->route('checkout.show', $order->reference)->with('checkout_error', $e->getMessage());
        }

        return redirect()->route('checkout.status', $order->reference);
    }

    private function customer(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
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
