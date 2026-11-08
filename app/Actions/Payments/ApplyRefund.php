<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Booking\CancelBooking;
use App\Actions\Booking\CreditPacks;
use App\Actions\Discounts\RestoreOrderDiscounts;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Exceptions\BookingRejected;
use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Collection;

/**
 * The rules every admin refund follows, whatever way the money goes back
 * (PLAN §3.3): card refunds through Stripe (RefundOrder) and bank
 * refunds the team pays by transfer (RecordTransferRefund).
 *
 * Never more than is left to refund. Full refund (everything left): every
 * upcoming booking the order paid for, directly or through its credit
 * pack, is cancelled and the pack's unused credits are voided. Partial:
 * only the bookings and number of credits the admin chose. The same share
 * of any gift voucher and account credit comes back (RestoreOrderDiscounts).
 * Recorded as a refunded payment (RecordRefund) and audit-logged.
 *
 * The caller holds the order row lock inside its transaction. Everything
 * is checked before $sendMoney runs, so a refused refund never moves money.
 */
final class ApplyRefund
{
    public function __construct(
        private readonly RecordRefund $record,
        private readonly CancelBooking $cancel,
        private readonly CreditPacks $credits,
        private readonly RestoreOrderDiscounts $restore,
    ) {}

    /**
     * @param  list<int>  $cancelBookingIds  partial refunds: bookings to cancel
     * @param  int  $removeCredits  partial refunds: unused credits to take off the pack
     * @param  Closure(): array{provider_id: string|null, raw: array<string, mixed>, context: array<string, scalar|null>}  $sendMoney
     *
     * @throws BookingRejected
     */
    public function handle(
        Order $locked,
        User $admin,
        int $amountMinor,
        string $reason,
        array $cancelBookingIds,
        int $removeCredits,
        PaymentProvider $provider,
        Closure $sendMoney,
        ?CarbonImmutable $transferredOn = null,
    ): Order {
        if (! in_array($locked->status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded], true)) {
            throw BookingRejected::notAllowed('Only paid orders can be refunded.');
        }

        $refundable = $locked->amount_minor - $locked->amount_refunded_minor;

        if ($amountMinor <= 0 || $amountMinor > $refundable) {
            throw BookingRejected::notAllowed('The refund must be between PKR 0.01 and the amount not yet refunded.');
        }

        $full = $amountMinor === $refundable;
        $pack = CreditPack::query()->where('order_id', $locked->id)->lockForUpdate()->first();
        $upcoming = RefundOrder::upcomingBookings($locked, $pack)->get();

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

        $sent = $sendMoney();

        $this->record->handle($locked, $amountMinor, $sent['provider_id'], $reason, $admin, $sent['raw'], $sent['context'], $provider, $transferredOn);

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
            ->withProperties(array_filter([
                ...$sent['context'],
                'method' => $provider->value,
                'amount_minor' => $amountMinor,
                'full' => $full,
                'bank_reference' => $provider === PaymentProvider::BankTransfer ? $sent['provider_id'] : null,
                'transferred_on' => $transferredOn?->toDateString(),
                'bookings_cancelled' => $toCancel->pluck('id')->all(),
                'credits_removed' => $full ? 'all' : $removeCredits,
                'voucher_returned_minor' => $restored['voucher'],
                'account_credit_returned_minor' => $restored['credit'],
                'reason' => $reason,
            ], fn (mixed $v): bool => $v !== null))
            ->log('Order '.$locked->reference.' refunded'.($provider === PaymentProvider::BankTransfer ? ' by bank transfer' : ''));

        return $locked->refresh();
    }
}
