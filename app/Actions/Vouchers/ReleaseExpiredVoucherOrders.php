<?php

declare(strict_types=1);

namespace App\Actions\Vouchers;

use App\Enums\VoucherOrderStatus;
use App\Models\VoucherOrder;
use App\Notifications\VoucherTransferExpired;
use App\Support\Notifier;
use App\Support\Stripe\StripeGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Throwable;

/**
 * vouchers:release (hourly, milestone 10e): closes unpaid gift voucher
 * purchases past their deadline. Card purchases (pending_payment) expire
 * 24 hours after they were started; any open Stripe session is expired
 * first, and a session Stripe reports complete is left to the webhook.
 * Transfer purchases without a proof (awaiting_transfer) expire at the
 * transfer deadline, with an email. Uploaded transfers never expire.
 */
final class ReleaseExpiredVoucherOrders
{
    public function __construct(
        private readonly VoucherOrderStateMachine $orders,
        private readonly StripeGateway $stripe,
        private readonly Notifier $notifier,
    ) {}

    /** @return int how many purchases were closed */
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $closed = 0;

        VoucherOrder::query()
            ->whereIn('status', [VoucherOrderStatus::PendingPayment, VoucherOrderStatus::AwaitingTransfer])
            ->where('expires_at', '<=', $now)
            ->orderBy('id')
            ->each(function (VoucherOrder $order) use (&$closed): void {
                if ($order->status === VoucherOrderStatus::PendingPayment && ! $this->closeStripeSession($order)) {
                    return;
                }

                $wasTransfer = $order->status === VoucherOrderStatus::AwaitingTransfer;

                try {
                    $this->orders->transition($order, VoucherOrderStatus::Expired, context: ['job' => 'vouchers:release']);
                } catch (Throwable) {
                    return; // moved on meanwhile (paid or uploaded)
                }

                $closed++;

                if ($wasTransfer) {
                    $order->loadMissing('user');
                    $this->notifier->send($order->user, new VoucherTransferExpired($order), Notifier::key(VoucherTransferExpired::type(), $order, $order->user));
                }
            });

        return $closed;
    }

    /** @return bool false when the card payment may have gone through */
    private function closeStripeSession(VoucherOrder $order): bool
    {
        if ($order->stripe_checkout_session_id === null) {
            return true;
        }

        try {
            $session = $this->stripe->retrieveCheckoutSession($order->stripe_checkout_session_id);

            if ($session->status === Session::STATUS_COMPLETE) {
                return false; // the webhook issues the voucher
            }

            if ($session->status === Session::STATUS_OPEN) {
                $this->stripe->expireCheckoutSession($session->id);
            }
        } catch (Throwable $e) {
            Log::warning('Could not check the Stripe session of voucher purchase '.$order->reference, ['error' => $e->getMessage()]);

            return false; // try again next hour
        }

        return true;
    }
}
