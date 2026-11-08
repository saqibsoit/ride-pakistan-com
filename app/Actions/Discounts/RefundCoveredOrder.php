<?php

declare(strict_types=1);

namespace App\Actions\Discounts;

use App\Actions\Booking\CancelBooking;
use App\Actions\Booking\CreditPacks;
use App\Actions\Orders\OrderStateMachine;
use App\Actions\Payments\RefundOrder;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\BookingRejected;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Cancel and restore balances" for an order that gift voucher and account
 * credit paid entirely (milestone 10e Part B; no card or transfer money to
 * refund). Admins only, reason required: cancels the upcoming bookings it
 * paid for, voids the pack's unused credits, gives the voucher and credit
 * back (RestoreOrderDiscounts) and marks the order refunded. Logged.
 */
final class RefundCoveredOrder
{
    public function __construct(
        private readonly CancelBooking $cancel,
        private readonly CreditPacks $credits,
        private readonly RestoreOrderDiscounts $restore,
        private readonly OrderStateMachine $orders,
    ) {}

    /** @throws BookingRejected */
    public function handle(Order $order, User $admin, string $reason): Order
    {
        $reason = (string) OrderStateMachine::cleanReason($reason);

        if (! $admin->isAdmin()) {
            throw BookingRejected::notAllowed('Only admins can refund orders.');
        }

        if ($reason === '') {
            throw BookingRejected::notAllowed('Give a reason for the refund.');
        }

        return DB::transaction(function () use ($order, $admin, $reason): Order {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderStatus::Paid || $locked->payment_method !== PaymentMethod::Discounts) {
                throw BookingRejected::notAllowed('Only paid orders covered by gift voucher or credit can be cancelled here.');
            }

            $pack = CreditPack::query()->where('order_id', $locked->id)->lockForUpdate()->first();
            $upcoming = RefundOrder::upcomingBookings($locked, $pack)->get();

            foreach ($upcoming as $booking) {
                $this->cancel->handle($booking, $admin, 'Refunded: '.$reason, asAdmin: true, returnCredit: false);
            }

            if ($pack !== null) {
                $pack->refresh();
                $this->credits->remove($pack, $pack->creditsRemaining(), $admin, 'Order refunded: '.$reason, revoke: true);
            }

            $restored = $this->restore->all($locked, $admin, $reason);
            $this->orders->transition($locked, OrderStatus::Refunded, $admin, $reason, [
                'voucher_returned_minor' => $restored['voucher'],
                'account_credit_returned_minor' => $restored['credit'],
            ]);

            activity('payments')
                ->performedOn($locked)
                ->causedBy($admin)
                ->event('refunded')
                ->withProperties([
                    'amount_minor' => 0,
                    'full' => true,
                    'bookings_cancelled' => $upcoming->pluck('id')->all(),
                    'voucher_returned_minor' => $restored['voucher'],
                    'account_credit_returned_minor' => $restored['credit'],
                    'reason' => $reason,
                ])
                ->log('Order '.$locked->reference.' cancelled and balances restored');

            $order->setRawAttributes($locked->refresh()->getAttributes(), true);

            return $order;
        });
    }
}
