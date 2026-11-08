<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Orders\OrderStateMachine;
use App\Enums\BankTransferStatus;
use App\Enums\OrderStatus;
use App\Exceptions\BookingRejected;
use App\Models\BankTransfer;
use App\Models\BookingHold;
use App\Models\Order;
use App\Models\User;
use App\Notifications\TransferRejected;
use App\Support\Notifier;
use App\Support\PageCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * An admin rejects a bank transfer (PLAN §3.2 step 4): reason required,
 * the order becomes rejected (final), its seats go back on sale and the
 * customer is emailed the reason.
 */
final class RejectBankTransfer
{
    public function __construct(
        private readonly OrderStateMachine $orders,
        private readonly PageCache $pageCache,
    ) {}

    /** @throws BookingRejected */
    public function handle(BankTransfer $transfer, User $admin, string $reason): void
    {
        $reason = (string) OrderStateMachine::cleanReason($reason);

        if (! $admin->isAdmin()) {
            throw BookingRejected::notAllowed('Only admins can reject transfers.');
        }

        if ($reason === '') {
            throw BookingRejected::notAllowed('Give a reason for rejecting the transfer.');
        }

        $order = DB::transaction(function () use ($transfer, $admin, $reason): Order {
            $order = Order::query()->whereKey($transfer->order_id)->lockForUpdate()->firstOrFail();
            $locked = BankTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== BankTransferStatus::Submitted || $order->status !== OrderStatus::AwaitingVerification) {
                throw BookingRejected::notAllowed('This transfer is not waiting for review.');
            }

            $locked->status = BankTransferStatus::Rejected;
            $locked->rejection_reason = $reason;
            $locked->verified_by = $admin->id;
            $locked->verified_at = CarbonImmutable::now();
            $locked->save();

            $this->orders->transition($order, OrderStatus::Rejected, $admin, $reason, ['bank_transfer_id' => $locked->id]);

            BookingHold::query()->where('order_id', $order->id)->delete();

            $transfer->setRawAttributes($locked->getAttributes(), true);

            return $order;
        });

        $this->pageCache->invalidate();

        app(Notifier::class)->send($order->user, new TransferRejected($order, $reason), Notifier::key(TransferRejected::type(), $order, $order->user));
    }
}
