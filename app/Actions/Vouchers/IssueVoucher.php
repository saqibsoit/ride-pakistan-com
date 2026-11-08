<?php

declare(strict_types=1);

namespace App\Actions\Vouchers;

use App\Enums\VoucherOrderStatus;
use App\Events\VoucherIssued;
use App\Exceptions\VoucherRejected;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The only way a paid gift voucher purchase becomes a voucher (milestone
 * 10e; called by the Stripe webhook's voucher branch or the voucher
 * transfer approval, never by a page). Like FulfilOrder for orders, but
 * separate from it: a voucher holds no seats or credits.
 *
 * In one transaction: the purchase is locked and moved to paid, one
 * voucher is created with a unique code, the purchase amount as its
 * balance and an expiry of `vouchers.validity_months` from now, and the
 * opening ledger entry is written. Idempotent: a second call returns the
 * voucher already issued. Delivery is queued after commit (VoucherIssued).
 */
final class IssueVoucher
{
    public function __construct(
        private readonly VoucherOrderStateMachine $orders,
        private readonly GenerateVoucherCode $codes,
        private readonly VoucherRules $rules,
        private readonly VoucherBalance $balance,
    ) {}

    /**
     * @param  array<string, scalar|null>  $context  audit details (Stripe event id, verifier)
     *
     * @throws VoucherRejected the purchase is closed (expired, cancelled, rejected)
     */
    public function handle(VoucherOrder $order, ?User $actor = null, array $context = []): Voucher
    {
        return DB::transaction(function () use ($order, $actor, $context): Voucher {
            $locked = VoucherOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $existing = Voucher::query()->where('voucher_order_id', $locked->id)->first();

            if ($existing !== null) {
                $order->setRawAttributes($locked->getAttributes(), true);

                return $existing;
            }

            if (! $locked->status->isUnpaid() && ! in_array($locked->status, [VoucherOrderStatus::Paid, VoucherOrderStatus::Expired], true)) {
                throw VoucherRejected::notPayable();
            }

            if ($locked->status !== VoucherOrderStatus::Paid) {
                $this->orders->transition($locked, VoucherOrderStatus::Paid, $actor, context: $context);
            }

            $now = CarbonImmutable::now();

            $voucher = Voucher::query()->create([
                'code' => $this->codes->handle(),
                'voucher_order_id' => $locked->id,
                'label' => $locked->product_name,
                'recipient_name' => $locked->recipient_name,
                'recipient_email' => $locked->recipient_email,
                'initial_minor' => $locked->amount_minor,
                'balance_minor' => $locked->amount_minor,
                'currency' => $locked->currency,
                'expires_at' => $this->rules->expiresAt($now),
            ]);

            $this->balance->opened($voucher, $actor, 'Purchase '.$locked->reference);

            activity('vouchers')
                ->performedOn($voucher)
                ->causedBy($actor)
                ->event('issued')
                ->withProperties([...$context, 'voucher_order' => $locked->reference, 'amount_minor' => $voucher->initial_minor])
                ->log('Voucher issued for purchase '.$locked->reference);

            event(new VoucherIssued($voucher));

            $order->setRawAttributes($locked->getAttributes(), true);

            return $voucher;
        });
    }
}
