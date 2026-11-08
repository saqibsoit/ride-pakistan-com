<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Orders\OrderStateMachine;
use App\Enums\BookingStatus;
use App\Enums\CreditPackStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Exceptions\BookingRejected;
use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\User;
use App\Support\Stripe\StripeGateway;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin refund of a card order through the Stripe API (PLAN §3.3).
 *
 * Full refund (the whole remaining amount): every upcoming booking paid
 * by the order or its credit pack is cancelled and the pack's unused
 * credits are voided (pack revoked). Partial refund: only the bookings
 * and number of credits the admin chose. Reason required; everything is
 * audit-logged.
 *
 * Everything is checked before Stripe is called. The call runs while the
 * order row is locked, so the charge.refunded webhook for this refund waits
 * for the record and then finds nothing new to book.
 */
final class RefundOrder
{
    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly ApplyRefund $refund,
    ) {}

    /**
     * @param  list<int>  $cancelBookingIds  partial refunds: bookings to cancel
     * @param  int  $removeCredits  partial refunds: unused credits to take off the pack
     *
     * @throws BookingRejected
     */
    public function handle(Order $order, User $admin, int $amountMinor, string $reason, array $cancelBookingIds = [], int $removeCredits = 0): Order
    {
        $reason = (string) OrderStateMachine::cleanReason($reason);

        if (! $admin->isAdmin()) {
            throw BookingRejected::notAllowed('Only admins can refund orders.');
        }

        if ($reason === '') {
            throw BookingRejected::notAllowed('Give a reason for the refund.');
        }

        return DB::transaction(function () use ($order, $admin, $amountMinor, $reason, $cancelBookingIds, $removeCredits): Order {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded], true)
                || $locked->payment_method !== PaymentMethod::Card
                || $locked->stripe_payment_intent_id === null) {
                throw BookingRejected::notAllowed('Only paid card orders can be refunded here.');
            }

            $intent = $locked->stripe_payment_intent_id;
            $refundedSoFar = $locked->amount_refunded_minor;

            // Same rules as every refund (ApplyRefund); Stripe is called only once they pass.
            $this->refund->handle($locked, $admin, $amountMinor, $reason, $cancelBookingIds, $removeCredits, PaymentProvider::Stripe, function () use ($locked, $admin, $amountMinor, $intent, $refundedSoFar): array {
                try {
                    $refund = $this->stripe->createRefund([
                        'payment_intent' => $intent,
                        'amount' => $amountMinor,
                        'reason' => 'requested_by_customer',
                        'metadata' => ['order_id' => (string) $locked->id, 'reference' => $locked->reference, 'admin_id' => (string) $admin->id],
                    ], 'refund:'.$locked->id.':'.$refundedSoFar.':'.$amountMinor);
                } catch (Throwable $e) {
                    Log::error('Stripe refund failed for order '.$locked->reference, ['error' => $e->getMessage()]);

                    throw BookingRejected::notAllowed('Stripe did not accept the refund: '.$e->getMessage());
                }

                return [
                    'provider_id' => $refund->id,
                    'raw' => ['id' => $refund->id, 'status' => $refund->status, 'amount' => $refund->amount],
                    'context' => ['stripe_refund' => $refund->id],
                ];
            });

            $order->setRawAttributes($locked->refresh()->getAttributes(), true);

            return $order;
        });
    }

    /**
     * Confirmed bookings still to come that the order paid for, directly
     * or through its credit pack.
     *
     * @return Builder<Booking>
     */
    public static function upcomingBookings(Order $order, ?CreditPack $pack = null): Builder
    {
        $pack ??= $order->creditPack;

        return Booking::query()
            ->where('status', BookingStatus::Confirmed)
            ->where(fn (Builder $q) => $q->where('order_id', $order->id)
                ->when($pack !== null, fn (Builder $q) => $q->orWhere('credit_pack_id', $pack?->id)))
            ->whereHas('slot', fn (Builder $q) => $q->where('starts_at', '>', CarbonImmutable::now()))
            ->with(['slot', 'rider'])
            ->orderBy('id');
    }

    /** Unused credits on the order's pack (0 without one, or once it is revoked). */
    public static function creditsLeft(Order $order): int
    {
        $pack = $order->creditPack;

        return $pack === null || $pack->status === CreditPackStatus::Revoked ? 0 : $pack->creditsRemaining();
    }
}
