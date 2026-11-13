<?php

declare(strict_types=1);

namespace App\Actions\Discounts;

use App\Enums\DiscountKind;
use App\Enums\OrderStatus;
use App\Exceptions\DiscountRejected;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Stripe\StripeGateway;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Throwable;

/**
 * What the customer does on the checkout page (milestone 10e Part B): enter
 * a gift voucher or promo code, remove a discount, use account credit or
 * stop using it. Only the order's own customer, only while it waits for
 * payment (not once a bank transfer was chosen). An open Stripe page is
 * closed first, because its amount would no longer match. The amounts
 * themselves are worked out by RepriceOrder.
 */
final class ChangeOrderDiscounts
{
    public function __construct(
        private readonly RepriceOrder $reprice,
        private readonly StripeGateway $stripe,
        private readonly PromoEligibility $promos,
    ) {}

    /** @throws DiscountRejected */
    public function applyCode(Order $order, User $customer, string $code): DiscountKind
    {
        $this->assertEditable($order, $customer);

        $voucher = Voucher::query()->where('code', Voucher::normaliseCode($code))->first();

        if ($voucher !== null) {
            if (! $voucher->isUsable()) {
                throw DiscountRejected::because('This gift voucher can’t be used: it has '.($voucher->balance_minor === 0 ? 'no balance left' : 'expired or been cancelled').'.');
            }

            $this->change($order, $customer, ['voucher_id' => $voucher->id], DiscountKind::Voucher);

            return DiscountKind::Voucher;
        }

        $promo = PromoCode::query()->where('code', PromoCode::normaliseCode($code))->first();

        if ($promo === null) {
            throw DiscountRejected::unknownCode();
        }

        $reason = $this->promos->reason($promo, $customer, $order);

        if ($reason !== null) {
            throw DiscountRejected::because($reason);
        }

        $this->change($order, $customer, ['promo_code_id' => $promo->id], DiscountKind::Promo);

        return DiscountKind::Promo;
    }

    /** @throws DiscountRejected */
    public function remove(Order $order, User $customer, DiscountKind $kind): void
    {
        $this->assertEditable($order, $customer);

        $choice = match ($kind) {
            DiscountKind::Promo => ['promo_code_id' => null],
            DiscountKind::Voucher => ['voucher_id' => null],
            DiscountKind::AccountCredit => ['use_credit' => false],
            DiscountKind::Referral => throw DiscountRejected::because('The welcome discount is applied automatically.'),
        };

        $this->change($order, $customer, $choice, null);
    }

    /** @throws DiscountRejected */
    public function useCredit(Order $order, User $customer, bool $use): void
    {
        $this->assertEditable($order, $customer);

        // Account credit is never offered on a guest order (decided 2026-11-12).
        if ($use && $order->placed_as_guest) {
            throw DiscountRejected::because('Sign in to use your account credit.');
        }
        $this->change($order, $customer, ['use_credit' => $use], null);
    }

    /**
     * @param  array{promo_code_id?: int|null, voucher_id?: int|null, use_credit?: bool}  $choice
     *
     * @throws DiscountRejected
     */
    private function change(Order $order, User $customer, array $choice, ?DiscountKind $strict): void
    {
        $this->closeStripeSession($order);

        $messages = $this->reprice->handle($order, $choice, $customer);

        // The code just entered failed under lock (e.g. the last use went a moment ago).
        if ($strict !== null && isset($messages[$strict->value])) {
            throw DiscountRejected::because($messages[$strict->value]);
        }
    }

    /** @throws DiscountRejected */
    private function assertEditable(Order $order, User $customer): void
    {
        $order->refresh();

        if ($order->user_id !== $customer->id) {
            throw DiscountRejected::locked();
        }

        if ($order->status !== OrderStatus::PendingPayment || $order->expires_at === null || ! $order->expires_at->isFuture()) {
            throw DiscountRejected::locked();
        }
    }

    /**
     * The card page would charge the old amount: close it. A session
     * Stripe already completed means the card payment went through.
     *
     * @throws DiscountRejected
     */
    private function closeStripeSession(Order $order): void
    {
        if ($order->stripe_checkout_session_id === null) {
            return;
        }

        try {
            $session = $this->stripe->retrieveCheckoutSession($order->stripe_checkout_session_id);

            if ($session->status === Session::STATUS_COMPLETE) {
                throw DiscountRejected::because('This order has just been paid by card.');
            }

            if ($session->status === Session::STATUS_OPEN) {
                $this->stripe->expireCheckoutSession($session->id);
            }
        } catch (DiscountRejected $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('Could not close the Stripe session before changing discounts on order '.$order->reference, ['error' => $e->getMessage()]);

            throw DiscountRejected::because('Please close the card payment page first, then try again.');
        }

        $order->forceFill(['stripe_checkout_session_id' => null])->save();
    }
}
