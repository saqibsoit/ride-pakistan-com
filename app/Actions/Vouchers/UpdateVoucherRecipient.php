<?php

declare(strict_types=1);

namespace App\Actions\Vouchers;

use App\Exceptions\VoucherRejected;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherOrder;
use Illuminate\Support\Facades\DB;

/**
 * Corrects a gift voucher's recipient before it is emailed (milestone
 * 10e): the buyer from their account, or an admin. Once it has been sent
 * the recipient is fixed (an admin can still resend to the stored
 * address). Logged without the old or new address.
 */
final class UpdateVoucherRecipient
{
    /**
     * From the admin voucher list: a purchased voucher goes through its
     * purchase; an admin-issued one is changed directly.
     *
     * @throws VoucherRejected
     */
    public function forVoucher(Voucher $voucher, User $actor, string $name, string $email): Voucher
    {
        if ($voucher->voucher_order_id !== null) {
            $this->handle(VoucherOrder::query()->findOrFail($voucher->voucher_order_id), $actor, $name, $email);

            return $voucher->refresh();
        }

        [$name, $email] = self::clean($name, $email);

        return DB::transaction(function () use ($voucher, $actor, $name, $email): Voucher {
            $locked = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            if ($locked->delivered_at !== null) {
                throw VoucherRejected::alreadyDelivered();
            }

            $locked->recipient_name = $name;
            $locked->recipient_email = $email;
            $locked->save();

            activity('vouchers')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('recipient_changed')
                ->log('Recipient changed for voucher '.$locked->formattedCode());

            return $voucher->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * @return array{0: string, 1: string}
     *
     * @throws VoucherRejected
     */
    private static function clean(string $name, string $email): array
    {
        $name = mb_substr(trim($name), 0, 120);
        $email = mb_strtolower(trim($email));

        if ($name === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw VoucherRejected::notUsable('Enter the recipient’s name and a valid email address.');
        }

        return [$name, $email];
    }

    /** @throws VoucherRejected */
    public function handle(VoucherOrder $order, User $actor, string $name, string $email): VoucherOrder
    {
        [$name, $email] = self::clean($name, $email);

        return DB::transaction(function () use ($order, $actor, $name, $email): VoucherOrder {
            $locked = VoucherOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $voucher = Voucher::query()->where('voucher_order_id', $locked->id)->lockForUpdate()->first();

            if ($voucher?->delivered_at !== null) {
                throw VoucherRejected::alreadyDelivered();
            }

            $locked->recipient_name = $name;
            $locked->recipient_email = $email;
            $locked->save();

            if ($voucher !== null) {
                $voucher->recipient_name = $name;
                $voucher->recipient_email = $email;
                $voucher->save();
            }

            activity('vouchers')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('recipient_changed')
                ->log('Recipient changed for voucher purchase '.$locked->reference);

            $order->setRawAttributes($locked->getAttributes(), true);

            return $order;
        });
    }
}
