<?php

declare(strict_types=1);

namespace App\Actions\Vouchers;

use App\Actions\Orders\OrderStateMachine;
use App\Enums\VoucherOrderStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\User;
use App\Models\VoucherOrder;
use Illuminate\Support\Facades\DB;

/**
 * The only place a gift voucher purchase changes status (milestone 10e).
 * Validates the transition against VoucherOrderStatus, locks the row and
 * writes the audit log (log "vouchers") with actor, time and reason. It
 * does not issue the voucher: that is IssueVoucher's job.
 */
final class VoucherOrderStateMachine
{
    /**
     * @param  array<string, scalar|null>  $context
     *
     * @throws InvalidStatusTransition
     */
    public function transition(VoucherOrder $order, VoucherOrderStatus $to, ?User $actor = null, ?string $reason = null, array $context = []): VoucherOrder
    {
        $reason = OrderStateMachine::cleanReason($reason);

        if ($to->requiresReason() && $reason === null) {
            throw InvalidStatusTransition::reasonRequired('Voucher purchase '.$order->reference, $to->value);
        }

        return DB::transaction(function () use ($order, $to, $actor, $reason, $context): VoucherOrder {
            $locked = VoucherOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw InvalidStatusTransition::between('Voucher purchase '.$locked->reference, $from->value, $to->value);
            }

            $locked->status = $to;

            if ($to === VoucherOrderStatus::Paid) {
                $locked->paid_at = now()->toImmutable();
            }

            $locked->save();

            activity('vouchers')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('status_changed')
                ->withProperties([...$context, 'from' => $from->value, 'to' => $to->value, 'reason' => $reason])
                ->log(sprintf('Voucher purchase %s: %s → %s', $locked->reference, $from->label(), $to->label()));

            $order->setRawAttributes($locked->getAttributes(), true);

            return $order;
        });
    }
}
