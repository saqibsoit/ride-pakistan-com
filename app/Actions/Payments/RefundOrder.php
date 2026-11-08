<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Booking\CancelBooking;
use App\Actions\Booking\CreditPacks;
use App\Actions\Discounts\RestoreOrderDiscounts;
use App\Actions\Orders\OrderStateMachine;
use App\Enums\BookingStatus;
use App\Enums\CreditPackStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\BookingRejected;
use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\User;
use App\Support\Stripe\StripeGateway;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
        private readonly RecordRefund $record,
        private readonly CancelBooking $cancel,
        private readonly CreditPacks $credits,
        private readonly RestoreOrderDiscounts $restore,
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

            $refundable = $locked->amount_minor - $locked->amount_refunded_minor;

            if ($amountMinor <= 0 || $amountMinor > $refundable) {
                throw BookingRejected::notAllowed('The refund must be between PKR 0.01 and the amount not yet refunded.');
            }

            $full = $amountMinor === $refundable;
            $pack = CreditPack::query()->where('order_id', $locked->id)->lockForUpdate()->first();
            $upcoming = self::upcomingBookings($locked, $pack)->get();

            if ($full) {
                $toCancel = $upcoming;
            } else {
                $toCancel = $upcoming->whereIn('id', $cancelBookingIds)->values();

                if ($toCancel->count() !== count(array_unique($cancelBookingIds))) {
                    throw BookingRejected::notAllowed('Some of the chosen bookings are not upcoming bookings of this order.');
                }

                if ($removeCredits < 0 || $removeCredits > ($pack?->creditsRemaining() ?? 0)) {
                    throw BookingRejected::notAllowed('That is more credits than the pack has left.');
                }
            }

            try {
                $refund = $this->stripe->createRefund([
                    'payment_intent' => $locked->stripe_payment_intent_id,
                    'amount' => $amountMinor,
                    'reason' => 'requested_by_customer',
                    'metadata' => ['order_id' => (string) $locked->id, 'reference' => $locked->reference, 'admin_id' => (string) $admin->id],
                ], 'refund:'.$locked->id.':'.$locked->amount_refunded_minor.':'.$amountMinor);
            } catch (Throwable $e) {
                Log::error('Stripe refund failed for order '.$locked->reference, ['error' => $e->getMessage()]);

                throw BookingRejected::notAllowed('Stripe did not accept the refund: '.$e->getMessage());
            }

            $this->record->handle($locked, $amountMinor, $refund->id, $reason, $admin, [
                'id' => $refund->id,
                'status' => $refund->status,
                'amount' => $refund->amount,
            ], ['stripe_refund' => $refund->id]);

            // The same share of any gift voucher or account credit comes back (milestone 10e Part B).
            $restored = $this->restore->afterRefund($locked, $admin, $reason);

            /** @var Collection<int, Booking> $toCancel */
            foreach ($toCancel as $booking) {
                $this->cancel->handle($booking, $admin, 'Refunded: '.$reason, asAdmin: true, returnCredit: false);
            }

            if ($pack !== null) {
                $pack->refresh();

                if ($full) {
                    $this->credits->remove($pack, $pack->creditsRemaining(), $admin, 'Order refunded: '.$reason, revoke: true);
                } elseif ($removeCredits > 0) {
                    $this->credits->remove($pack, $removeCredits, $admin, 'Partial refund: '.$reason);
                }
            }

            activity('payments')
                ->performedOn($locked)
                ->causedBy($admin)
                ->event('refunded')
                ->withProperties([
                    'amount_minor' => $amountMinor,
                    'full' => $full,
                    'stripe_refund' => $refund->id,
                    'bookings_cancelled' => $toCancel->pluck('id')->all(),
                    'credits_removed' => $full ? 'all' : $removeCredits,
                    'voucher_returned_minor' => $restored['voucher'],
                    'account_credit_returned_minor' => $restored['credit'],
                    'reason' => $reason,
                ])
                ->log('Order '.$locked->reference.' refunded');

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
