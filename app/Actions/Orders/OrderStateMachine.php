<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Actions\Discounts\ReleaseOrderDiscounts;
use App\Actions\PrivateRequests\SyncPrivateRequest;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only place an order changes status (PLAN §3.3). Validates the
 * transition against OrderStatus, locks the order row, and writes the
 * audit log with actor, time and reason. It does not fulfil: seats,
 * credits and tickets are FulfilOrder's job (milestones 6 and 7).
 */
final class OrderStateMachine
{
    public const MAX_REASON_LENGTH = 500;

    /**
     * @param  array<string, scalar|null>  $context  extra audit details (e.g. Stripe event id)
     *
     * @throws InvalidStatusTransition
     */
    public function transition(Order $order, OrderStatus $to, ?User $actor = null, ?string $reason = null, array $context = []): Order
    {
        $reason = self::cleanReason($reason);

        if ($to->requiresReason() && $reason === null) {
            throw InvalidStatusTransition::reasonRequired('Order '.$order->reference, $to->value);
        }

        return DB::transaction(function () use ($order, $to, $actor, $reason, $context): Order {
            // Re-read under lock: the in-memory status may be stale (webhook
            // and admin acting at the same time).
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw InvalidStatusTransition::between('Order '.$locked->reference, $from->value, $to->value);
            }

            $locked->status = $to;

            if ($to === OrderStatus::Paid) {
                $locked->paid_at = now()->toImmutable();
            }

            $locked->save();

            // An unpaid order that ends gives back its gift voucher, account
            // credit and promo use (milestone 10e Part B).
            if ($from->isUnpaid() && in_array($to, [OrderStatus::Expired, OrderStatus::Cancelled, OrderStatus::Rejected], true)) {
                app(ReleaseOrderDiscounts::class)->handle($locked, $actor, 'Order '.mb_strtolower($to->label()));
            }

            // An approved private session request follows its order (prompt 15B).
            if (isset($locked->meta['private_request_id'])) {
                app(SyncPrivateRequest::class)->handle($locked, $to, $actor);
            }

            activity('orders')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('status_changed')
                ->withProperties([...$context, 'from' => $from->value, 'to' => $to->value, 'reason' => $reason])
                ->log(sprintf('Order %s: %s → %s', $locked->reference, $from->label(), $to->label()));

            $order->setRawAttributes($locked->getAttributes(), true);

            return $order;
        });
    }

    /** Trimmed and capped; blank means no reason. */
    public static function cleanReason(?string $reason): ?string
    {
        $reason = trim((string) $reason);

        return $reason === '' ? null : mb_substr($reason, 0, self::MAX_REASON_LENGTH);
    }
}
