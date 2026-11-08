<?php

declare(strict_types=1);

namespace App\Actions\Vouchers;

use App\Enums\VoucherStatus;
use App\Models\User;
use App\Models\Voucher;
use App\Notifications\GiftVoucherReceived;
use App\Notifications\VoucherSent;
use App\Support\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Emails a gift voucher to its recipient (milestone 10e). Automatic sends
 * go out once per voucher: when it is issued (send now) or, for a chosen
 * date, at 09:00 Pakistan time that day (`vouchers:deliver`, every 15
 * minutes). The buyer of a scheduled voucher is told when it has gone.
 * An admin can resend (logged, not deduplicated).
 */
final class DeliverVoucher
{
    public function __construct(private readonly Notifier $notifier) {}

    /** @return bool whether it was sent now */
    public function handle(Voucher $voucher, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        $sent = DB::transaction(function () use ($voucher, $now): bool {
            $locked = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->with('voucherOrder')->firstOrFail();

            if ($locked->delivered_at !== null || $locked->status !== VoucherStatus::Active || (string) $locked->recipient_email === '') {
                return false;
            }

            $due = VoucherRules::deliveryTime($locked->voucherOrder?->send_on);

            if ($due !== null && $due->isAfter($now)) {
                return false;
            }

            $locked->delivered_at = $now;
            $locked->save();

            $this->notifier->sendToAddress((string) $locked->recipient_email, new GiftVoucherReceived($locked), GiftVoucherReceived::type().':Voucher:'.$locked->id);

            activity('vouchers')
                ->performedOn($locked)
                ->event('delivered')
                ->log('Voucher '.$locked->formattedCode().' emailed to its recipient');

            $voucher->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        $order = $voucher->voucherOrder()->with('user')->first();

        if ($sent && $order?->send_on !== null) {
            $this->notifier->send($order->user, new VoucherSent($voucher), Notifier::key(VoucherSent::type(), $voucher, $order->user));
        }

        return $sent;
    }

    /** Sends the email again (admin), whatever was sent before. */
    public function resend(Voucher $voucher, User $admin): bool
    {
        if ($voucher->status !== VoucherStatus::Active || (string) $voucher->recipient_email === '') {
            return false;
        }

        $sent = $this->notifier->sendToAddress((string) $voucher->recipient_email, new GiftVoucherReceived($voucher), resentBy: $admin);

        if ($voucher->delivered_at === null) {
            $voucher->delivered_at = CarbonImmutable::now();
            $voucher->save();
        }

        activity('vouchers')
            ->performedOn($voucher)
            ->causedBy($admin)
            ->event('resent')
            ->log('Voucher '.$voucher->formattedCode().' emailed again');

        return $sent;
    }

    /** vouchers:deliver: every active voucher not yet sent whose time has come. */
    public function due(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $count = 0;

        Voucher::query()
            ->where('status', VoucherStatus::Active)
            ->whereNull('delivered_at')
            ->whereNotNull('recipient_email')
            ->where(fn (Builder $q) => $q->whereNull('voucher_order_id')->orWhereHas('voucherOrder', fn (Builder $o) => $o
                ->whereNull('send_on')
                ->orWhere('send_on', '<=', $now->setTimezone((string) config('app.display_timezone'))->format('Y-m-d'))))
            ->orderBy('id')
            ->each(function (Voucher $voucher) use ($now, &$count): void {
                if ($this->handle($voucher, $now)) {
                    $count++;
                }
            });

        return $count;
    }
}
