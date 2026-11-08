<?php

declare(strict_types=1);

namespace App\Actions\Vouchers;

use App\Actions\Orders\OrderStateMachine;
use App\Enums\VoucherLedgerReason;
use App\Enums\VoucherStatus;
use App\Exceptions\VoucherRejected;
use App\Models\Order;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherLedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The one place a voucher's balance, status or expiry changes (milestone
 * 10e). Each change locks the voucher row, writes a signed ledger entry
 * and the audit log (log "vouchers"). Redeeming and restoring at checkout
 * and on refunds are added in Part B on top of these.
 */
final class VoucherBalance
{
    /** Writes the opening ledger entry of a newly created voucher. */
    public function opened(Voucher $voucher, ?User $actor, ?string $note = null): void
    {
        $this->ledger($voucher, $voucher->initial_minor, VoucherLedgerReason::Issued, $actor, $note);
    }

    /**
     * Cancels the remaining balance (reason required): fraud, a refunded
     * purchase, a mistake. A voided voucher can't be used again.
     *
     * @throws VoucherRejected
     */
    public function void(Voucher $voucher, ?User $actor, string $reason): Voucher
    {
        $reason = self::requireReason($reason);

        return DB::transaction(function () use ($voucher, $actor, $reason): Voucher {
            $locked = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === VoucherStatus::Void) {
                return $this->sync($voucher, $locked);
            }

            $left = $locked->balance_minor;
            $from = $locked->status;

            $locked->balance_minor = 0;
            $locked->status = VoucherStatus::Void;
            $locked->void_reason = $reason;
            $locked->save();

            if ($left > 0) {
                $this->ledger($locked, -$left, VoucherLedgerReason::Voided, $actor, $reason);
            }

            $this->log($locked, $actor, 'voided', 'Voucher '.$locked->formattedCode().' voided', ['from' => $from->value, 'balance_minor' => $left, 'reason' => $reason]);

            return $this->sync($voucher, $locked);
        });
    }

    /**
     * Moves the expiry date (reason required). An expired voucher with its
     * written-off balance comes back to life with that balance.
     *
     * @throws VoucherRejected
     */
    public function extend(Voucher $voucher, ?User $actor, CarbonImmutable $expiresAt, string $reason): Voucher
    {
        $reason = self::requireReason($reason);

        if (! $expiresAt->isFuture()) {
            throw VoucherRejected::notUsable('The new expiry date must be in the future.');
        }

        return DB::transaction(function () use ($voucher, $actor, $expiresAt, $reason): Voucher {
            $locked = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [VoucherStatus::Void, VoucherStatus::Redeemed], true)) {
                throw VoucherRejected::notUsable('Only active or expired vouchers can be extended.');
            }

            $from = $locked->expires_at;

            if ($locked->status === VoucherStatus::Expired) {
                $writtenOff = -1 * (int) $locked->ledger()->where('reason', VoucherLedgerReason::Expired)->sum('delta_minor');

                if ($writtenOff > 0) {
                    $locked->balance_minor += $writtenOff;
                    $this->ledger($locked, $writtenOff, VoucherLedgerReason::Adjusted, $actor, 'Reinstated on extension: '.$reason);
                }

                $locked->status = $locked->balance_minor > 0 ? VoucherStatus::Active : VoucherStatus::Redeemed;
            }

            $locked->expires_at = $expiresAt;
            $locked->save();

            $this->log($locked, $actor, 'extended', 'Voucher '.$locked->formattedCode().' extended', [
                'from' => $from->toIso8601String(),
                'to' => $expiresAt->toIso8601String(),
                'reason' => $reason,
            ]);

            return $this->sync($voucher, $locked);
        });
    }

    /** Marks a voucher past its expiry as expired and writes off what was left. */
    public function expire(Voucher $voucher, CarbonImmutable $now): bool
    {
        return DB::transaction(function () use ($voucher, $now): bool {
            $locked = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== VoucherStatus::Active || $locked->expires_at->isAfter($now)) {
                return false;
            }

            $left = $locked->balance_minor;
            $locked->balance_minor = 0;
            $locked->status = VoucherStatus::Expired;
            $locked->save();

            if ($left > 0) {
                $this->ledger($locked, -$left, VoucherLedgerReason::Expired, null, null);
            }

            $this->log($locked, null, 'expired', 'Voucher '.$locked->formattedCode().' expired', ['balance_minor' => $left]);
            $this->sync($voucher, $locked);

            return true;
        });
    }

    /**
     * Takes up to $maxMinor from the balance for an order at checkout
     * (milestone 10e Part B) and returns what was taken. Only an active,
     * unexpired voucher with a balance can be used. The voucher row is
     * locked, so two orders can never spend the same balance.
     *
     * @throws VoucherRejected
     */
    public function redeem(Voucher $voucher, Order $order, int $maxMinor, ?User $actor): int
    {
        return DB::transaction(function () use ($voucher, $order, $maxMinor, $actor): int {
            $locked = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isUsable()) {
                throw VoucherRejected::notUsable(match (true) {
                    $locked->status === VoucherStatus::Void => 'This gift voucher has been cancelled.',
                    $locked->status === VoucherStatus::Expired || ! $locked->expires_at->isFuture() => 'This gift voucher expired on '.$locked->expires_at->setTimezone((string) config('app.display_timezone'))->format('j M Y').'.',
                    default => 'This gift voucher has no balance left.',
                });
            }

            $taken = min(max(0, $maxMinor), $locked->balance_minor);

            if ($taken === 0) {
                return 0;
            }

            $locked->balance_minor -= $taken;

            if ($locked->balance_minor === 0) {
                $locked->status = VoucherStatus::Redeemed;
            }

            $locked->save();
            $this->ledger($locked, -$taken, VoucherLedgerReason::Redeemed, $actor, 'Order '.$order->reference, $order);
            $this->sync($voucher, $locked);

            return $taken;
        });
    }

    /**
     * Puts $amountMinor back for an order: an unpaid order ended, the
     * customer removed the voucher, or a refund (milestone 10e Part B).
     * Returns false when the voucher can't take it back (cancelled, or
     * expired by now); the caller then moves the amount to account credit.
     */
    public function restore(Voucher $voucher, Order $order, int $amountMinor, ?User $actor, string $note): bool
    {
        if ($amountMinor <= 0) {
            return true;
        }

        return DB::transaction(function () use ($voucher, $order, $amountMinor, $actor, $note): bool {
            $locked = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [VoucherStatus::Active, VoucherStatus::Redeemed], true) || ! $locked->expires_at->isFuture()) {
                return false;
            }

            $locked->balance_minor += $amountMinor;
            $locked->status = VoucherStatus::Active;
            $locked->save();

            $this->ledger($locked, $amountMinor, VoucherLedgerReason::Restored, $actor, mb_substr($note.' (order '.$order->reference.')', 0, 500), $order);
            $this->sync($voucher, $locked);

            return true;
        });
    }

    /** Net amount of this voucher an order holds now (redeemed minus restored). */
    public static function heldBy(Order $order, ?int $voucherId = null): int
    {
        return -1 * (int) VoucherLedgerEntry::query()
            ->where('order_id', $order->id)
            ->when($voucherId !== null, fn ($q) => $q->where('voucher_id', $voucherId))
            ->whereIn('reason', [VoucherLedgerReason::Redeemed, VoucherLedgerReason::Restored])
            ->sum('delta_minor');
    }

    /** @throws VoucherRejected */
    private static function requireReason(string $reason): string
    {
        $reason = OrderStateMachine::cleanReason($reason);

        if ($reason === null) {
            throw VoucherRejected::notUsable('Please give a reason.');
        }

        return $reason;
    }

    private function ledger(Voucher $voucher, int $delta, VoucherLedgerReason $reason, ?User $actor, ?string $note, ?Order $order = null): void
    {
        VoucherLedgerEntry::query()->create([
            'voucher_id' => $voucher->id,
            'order_id' => $order?->id,
            'delta_minor' => $delta,
            'reason' => $reason,
            'note' => $note === null ? null : mb_substr($note, 0, 500),
            'actor_id' => $actor?->id,
        ]);
    }

    /**
     * @param  array<string, scalar|null>  $properties
     */
    private function log(Voucher $voucher, ?User $actor, string $event, string $description, array $properties): void
    {
        activity('vouchers')
            ->performedOn($voucher)
            ->causedBy($actor)
            ->event($event)
            ->withProperties($properties)
            ->log($description);
    }

    private function sync(Voucher $voucher, Voucher $locked): Voucher
    {
        $voucher->setRawAttributes($locked->getAttributes(), true);

        return $voucher;
    }
}
