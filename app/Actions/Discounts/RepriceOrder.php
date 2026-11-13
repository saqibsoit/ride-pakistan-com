<?php

declare(strict_types=1);

namespace App\Actions\Discounts;

use App\Actions\Credit\AccountCredit;
use App\Actions\Vouchers\VoucherBalance;
use App\Enums\DiscountKind;
use App\Enums\OrderStatus;
use App\Exceptions\DiscountRejected;
use App\Exceptions\VoucherRejected;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\Referral;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Support\Facades\DB;

/**
 * Works out an unpaid order's discounts on the server (milestone 10e
 * Part B) from what the customer chose (order meta "discounts": promo,
 * gift voucher, use credit) and writes the result:
 *
 * 1. A promo code, or else the referral friend's discount (never both).
 *    A percentage is rounded down to whole rupees; never more than the
 *    subtotal.
 * 2. The gift voucher pays what it can of the rest.
 * 3. Account credit pays what it can of the rest.
 *
 * amount_minor becomes what is left to pay. The order is locked first,
 * then the promo code, voucher and customer rows (always in that order).
 * What the order held before is given back and taken again, so balances
 * are always exact. Each change is logged ("discounts").
 *
 * Returns messages for choices that no longer apply (e.g. a promo that
 * ended), keyed by kind; those choices are dropped.
 */
final class RepriceOrder
{
    public function __construct(
        private readonly PromoEligibility $promos,
        private readonly ReferralDiscount $referrals,
        private readonly VoucherBalance $vouchers,
        private readonly AccountCredit $credit,
        private readonly ReleaseOrderDiscounts $release,
    ) {}

    /**
     * @param  array{promo_code_id?: int|null, voucher_id?: int|null, use_credit?: bool}|null  $choice  new choices (merged), or null to recalculate
     * @return array<string, string> messages keyed by DiscountKind value
     *
     * @throws DiscountRejected
     */
    public function handle(Order $order, ?array $choice = null, ?User $actor = null): array
    {
        return DB::transaction(function () use ($order, $choice, $actor): array {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderStatus::PendingPayment || $locked->expires_at === null || ! $locked->expires_at->isFuture()) {
                throw DiscountRejected::locked();
            }

            $selected = [...self::choices($locked), ...($choice ?? [])];
            $messages = [];

            // Every lock before any plain read (order, promo, vouchers, customer):
            // MariaDB refuses a locking read of a row that changed after the
            // transaction's first plain read (error 1020), as in SlotLock.
            $promo = $selected['promo_code_id'] === null ? null : PromoCode::query()->whereKey($selected['promo_code_id'])->lockForUpdate()->first();
            $vouchers = ReleaseOrderDiscounts::lockVouchers($locked, $selected['voucher_id']);
            $customer = User::withTrashed()->whereKey($locked->user_id)->lockForUpdate()->firstOrFail();

            // Give back what the order held, then take it again below.
            $this->release->giveBackVouchers($locked, null, $actor, 'Checkout recalculated');
            $this->credit->giveBack($customer, $locked, AccountCredit::heldBy($locked), $actor, 'Checkout recalculated');

            $subtotal = $locked->subtotal_minor;
            $lines = [];
            $usedVouchers = [];

            // 1. Promo code, or the referral discount.
            if ($promo !== null) {
                $reason = $this->promos->reason($promo, $customer, $locked);

                if ($reason === null) {
                    $lines[] = $this->line(DiscountKind::Promo, $promo, 'Promo code '.$promo->code.' ('.$promo->summary().')', PromoEligibility::amountFor($promo, $subtotal));
                } else {
                    $messages[DiscountKind::Promo->value] = $reason;
                    $selected['promo_code_id'] = null;
                    $promo = null;
                }
            } elseif ($selected['promo_code_id'] !== null) {
                $messages[DiscountKind::Promo->value] = 'That promo code no longer exists.';
                $selected['promo_code_id'] = null;
            }

            // Never on a guest order (decided 2026-11-12): it depends on the account behind the email.
            if ($promo === null && ! $locked->placed_as_guest && ($referral = $this->referrals->referralFor($customer, $locked)) instanceof Referral) {
                $lines[] = $this->line(DiscountKind::Referral, $referral, 'Welcome discount (invited by a friend)', $this->referrals->amountFor($subtotal));
            }

            $remaining = $subtotal - (int) array_sum(array_column($lines, 'amount_minor'));

            // 2. Gift voucher.
            if ($selected['voucher_id'] !== null && $remaining > 0) {
                $voucher = $vouchers->get($selected['voucher_id']);

                try {
                    $taken = $voucher === null ? throw VoucherRejected::notUsable('That gift voucher no longer exists.') : $this->vouchers->redeem($voucher, $locked, $remaining, $actor ?? $customer);
                    $lines[] = $this->line(DiscountKind::Voucher, $voucher, 'Gift voucher '.$voucher->formattedCode(), $taken);
                    $remaining -= $taken;
                    $usedVouchers[] = $voucher->id;
                } catch (VoucherRejected $e) {
                    $messages[DiscountKind::Voucher->value] = $e->getMessage();
                    $selected['voucher_id'] = null;
                }
            }

            // 3. Account credit.
            if ($selected['use_credit'] && ! $locked->placed_as_guest && $remaining > 0) {
                $spent = $this->credit->spend($customer, $locked, $remaining);

                if ($spent > 0) {
                    $lines[] = $this->line(DiscountKind::AccountCredit, null, 'Account credit', $spent);
                    $remaining -= $spent;
                }
            }

            $lines = array_values(array_filter($lines, fn (array $l): bool => $l['amount_minor'] > 0));

            $locked->discounts()->delete();

            foreach ($lines as $line) {
                $locked->discounts()->create($line);
            }

            $promoLine = collect($lines)->firstWhere('kind', DiscountKind::Promo);

            if ($promoLine === null) {
                PromoRedemption::query()->where('order_id', $locked->id)->delete();
            } else {
                PromoRedemption::query()->updateOrCreate(['order_id' => $locked->id], [
                    'promo_code_id' => $promoLine['source_id'],
                    'user_id' => $customer->id,
                    'amount_minor' => $promoLine['amount_minor'],
                ]);
            }

            $before = $locked->amount_minor;
            $locked->amount_minor = max(0, $remaining);
            // Every voucher this order ever used, oldest first (ReleaseOrderDiscounts::voucherIds).
            // Re-read meta: giving back may have recorded voucher money moved to credit.
            $meta = Order::query()->whereKey($locked->id)->first(['id', 'meta'])->meta ?? [];
            $stored = array_map('intval', (array) ($meta['voucher_ids'] ?? []));
            $locked->meta = [...$meta, 'discounts' => $selected, 'voucher_ids' => array_values(array_unique([...$stored, ...$usedVouchers]))];
            $locked->save();

            if ($before !== $locked->amount_minor || $choice !== null) {
                activity('discounts')
                    ->performedOn($locked)
                    ->causedBy($actor ?? $customer)
                    ->event('repriced')
                    ->withProperties([
                        'subtotal_minor' => $subtotal,
                        'lines' => array_map(fn (array $l): array => ['kind' => $l['kind']->value, 'amount_minor' => $l['amount_minor']], $lines),
                        'amount_minor' => $locked->amount_minor,
                        'dropped' => array_keys($messages),
                    ])
                    ->log('Order '.$locked->reference.': discounts recalculated');
            }

            $order->setRawAttributes($locked->getAttributes(), true);
            $order->unsetRelation('discounts');

            return $messages;
        });
    }

    /**
     * What the customer chose for this order.
     *
     * @return array{promo_code_id: int|null, voucher_id: int|null, use_credit: bool}
     */
    public static function choices(Order $order): array
    {
        $stored = (array) ($order->meta['discounts'] ?? []);

        return [
            'promo_code_id' => isset($stored['promo_code_id']) ? (int) $stored['promo_code_id'] : null,
            'voucher_id' => isset($stored['voucher_id']) ? (int) $stored['voucher_id'] : null,
            'use_credit' => (bool) ($stored['use_credit'] ?? false),
        ];
    }

    /**
     * @return array{kind: DiscountKind, source_type: string|null, source_id: int|null, label: string, amount_minor: int}
     */
    private function line(DiscountKind $kind, PromoCode|Referral|Voucher|null $source, string $label, int $amount): array
    {
        return [
            'kind' => $kind,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey() === null ? null : (int) $source->getKey(),
            'label' => mb_substr($label, 0, 120),
            'amount_minor' => $amount,
        ];
    }
}
