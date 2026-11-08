<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Orders\OrderStateMachine;
use App\Exceptions\BookingRejected;
use App\Models\User;
use App\Support\SessionTimes;
use Carbon\CarbonImmutable;

/**
 * The checks every bank refund shares (milestone 10e), for orders
 * (RecordTransferRefund) and gift voucher purchases
 * (RecordVoucherTransferRefund): an admin, a reason, the outgoing
 * transfer's bank reference and the Pakistan date it was made, not in the
 * future and not before the money came in. Returns the cleaned values.
 */
final class BankRefund
{
    public const MAX_REFERENCE_LENGTH = 100;

    /**
     * @return array{reference: string, on: CarbonImmutable, reason: string}
     *
     * @throws BookingRejected
     */
    public static function check(User $admin, string $bankReference, CarbonImmutable $transferredOn, string $reason, ?CarbonImmutable $paidAt): array
    {
        $reason = (string) OrderStateMachine::cleanReason($reason);
        $reference = mb_substr(trim($bankReference), 0, self::MAX_REFERENCE_LENGTH);
        $on = $transferredOn->startOfDay();

        if (! $admin->isAdmin()) {
            throw BookingRejected::notAllowed('Only admins can record refunds.');
        }

        if ($reason === '') {
            throw BookingRejected::notAllowed('Give a reason for the refund.');
        }

        if ($reference === '') {
            throw BookingRejected::notAllowed('Enter the bank reference of the transfer you made.');
        }

        if ($on->isAfter(SessionTimes::now()->startOfDay())) {
            throw BookingRejected::notAllowed('The transfer date can’t be in the future.');
        }

        $paidOn = $paidAt?->setTimezone((string) config('app.display_timezone'))->startOfDay();

        if ($paidOn !== null && $on->isBefore($paidOn)) {
            throw BookingRejected::notAllowed('The refund can’t be dated before the payment came in ('.$paidOn->format('j M Y').').');
        }

        return ['reference' => $reference, 'on' => $on, 'reason' => $reason];
    }
}
