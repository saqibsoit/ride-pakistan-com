<?php

declare(strict_types=1);

namespace App\Actions\Vouchers;

use App\Enums\PaymentMethod;
use App\Enums\SettingKey;
use App\Enums\VoucherOrderStatus;
use App\Exceptions\VoucherRejected;
use App\Models\VoucherOrder;
use App\Support\Settings;
use App\Support\Stripe\StripeGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Throwable;

/**
 * The customer pays for a gift voucher by bank transfer (milestone 10e,
 * same flow as orders, PLAN §3.2). Any open Stripe session is expired
 * first so no card payment can land as well; a session Stripe already
 * completed means the card payment went through (the webhook issues the
 * voucher). The purchase then waits for the transfer until now +
 * `booking.transfer_hold_hours`. Choosing again is harmless.
 */
final class ChooseVoucherBankTransfer
{
    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly VoucherOrderStateMachine $orders,
        private readonly Settings $settings,
    ) {}

    /** @throws VoucherRejected */
    public function handle(VoucherOrder $order): VoucherOrder
    {
        $order->refresh();

        if (in_array($order->status, [VoucherOrderStatus::AwaitingTransfer, VoucherOrderStatus::AwaitingVerification], true)) {
            return $order;
        }

        $this->assertPayable($order, CarbonImmutable::now());
        $this->closeStripeSession($order);

        return DB::transaction(function () use ($order): VoucherOrder {
            $locked = VoucherOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::now();

            if (in_array($locked->status, [VoucherOrderStatus::AwaitingTransfer, VoucherOrderStatus::AwaitingVerification], true)) {
                $order->setRawAttributes($locked->getAttributes(), true);

                return $order;
            }

            $this->assertPayable($locked, $now);

            $deadline = $now->addHours(max(1, $this->settings->int(SettingKey::TransferHoldHours) ?? 24));

            $locked->expires_at = $deadline;
            $locked->payment_method = PaymentMethod::BankTransfer;
            $locked->stripe_checkout_session_id = null;
            $locked->save();

            $this->orders->transition($locked, VoucherOrderStatus::AwaitingTransfer, $locked->user, context: ['deadline' => $deadline->toIso8601String()]);

            $order->setRawAttributes($locked->getAttributes(), true);

            return $order;
        });
    }

    /** @throws VoucherRejected */
    private function assertPayable(VoucherOrder $order, CarbonImmutable $now): void
    {
        if ($order->status === VoucherOrderStatus::Paid) {
            throw VoucherRejected::alreadyPaid();
        }

        if ($order->status !== VoucherOrderStatus::PendingPayment || $order->expires_at === null || ! $order->expires_at->isAfter($now)) {
            throw VoucherRejected::notPayable();
        }
    }

    /** @throws VoucherRejected */
    private function closeStripeSession(VoucherOrder $order): void
    {
        if ($order->stripe_checkout_session_id === null) {
            return;
        }

        try {
            $session = $this->stripe->retrieveCheckoutSession($order->stripe_checkout_session_id);

            if ($session->status === Session::STATUS_COMPLETE) {
                throw VoucherRejected::alreadyPaid();
            }

            if ($session->status === Session::STATUS_OPEN) {
                $this->stripe->expireCheckoutSession($session->id);
            }
        } catch (VoucherRejected $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('Could not close the Stripe session before bank transfer for voucher purchase '.$order->reference, ['error' => $e->getMessage()]);

            throw new VoucherRejected('We couldn\'t switch this purchase to bank transfer just now. Please try again in a minute.');
        }
    }
}
