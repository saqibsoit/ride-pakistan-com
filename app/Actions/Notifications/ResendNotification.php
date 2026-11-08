<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Enums\BankTransferStatus;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\BookingRejected;
use App\Models\BankTransfer;
use App\Models\Booking;
use App\Models\Order;
use App\Models\User;
use App\Notifications\CustomerNotification;
use App\Notifications\OrderConfirmed;
use App\Notifications\SessionReminder;
use App\Notifications\TransferExpired;
use App\Notifications\TransferReceived;
use App\Notifications\TransferRejected;
use App\Support\Notifier;

/**
 * Admin resend of a customer email from the order or booking page
 * (milestone 8). Only emails that still match the record's state can be
 * resent. Logged in notification_log (resent_by) and the audit log.
 */
final class ResendNotification
{
    public function __construct(private readonly Notifier $notifier) {}

    /**
     * @return array<string, string> type => label, for the order's current state
     */
    public static function optionsForOrder(Order $order): array
    {
        $options = [];

        if (in_array($order->status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded], true)) {
            $options[OrderConfirmed::type()] = OrderConfirmed::label();
        }

        if ($order->status === OrderStatus::AwaitingVerification) {
            $options[TransferReceived::type()] = TransferReceived::label();
        }

        if ($order->status === OrderStatus::Rejected) {
            $options[TransferRejected::type()] = TransferRejected::label();
        }

        if ($order->status === OrderStatus::Expired && $order->payment_method === PaymentMethod::BankTransfer) {
            $options[TransferExpired::type()] = TransferExpired::label();
        }

        return $options;
    }

    /** @return array<string, string> */
    public static function optionsForBooking(Booking $booking): array
    {
        return $booking->status === BookingStatus::Confirmed && $booking->slot->starts_at->isFuture()
            ? [SessionReminder::type() => SessionReminder::label().' (with tickets)']
            : [];
    }

    /** @throws BookingRejected */
    public function forOrder(Order $order, string $type, User $admin): void
    {
        $this->assertAdmin($admin);

        if (! array_key_exists($type, self::optionsForOrder($order))) {
            throw BookingRejected::notAllowed('That email doesn\'t match this order any more.');
        }

        $notification = match ($type) {
            OrderConfirmed::type() => new OrderConfirmed($order),
            TransferReceived::type() => new TransferReceived($order),
            TransferExpired::type() => new TransferExpired($order),
            default => new TransferRejected($order, (string) BankTransfer::query()
                ->where('order_id', $order->id)
                ->where('status', BankTransferStatus::Rejected)
                ->latest('id')
                ->value('rejection_reason')),
        };

        $this->send($order->user, $notification, $admin, $order->reference);
    }

    /** @throws BookingRejected */
    public function forBooking(Booking $booking, string $type, User $admin): void
    {
        $this->assertAdmin($admin);

        if (! array_key_exists($type, self::optionsForBooking($booking))) {
            throw BookingRejected::notAllowed('That email doesn\'t match this booking any more.');
        }

        $bookings = Booking::query()
            ->where('slot_id', $booking->slot_id)
            ->where('user_id', $booking->user_id)
            ->where('status', BookingStatus::Confirmed)
            ->get();

        $this->send($booking->user, new SessionReminder($booking->slot, $bookings), $admin, $booking->ticket_code);
    }

    private function send(User $customer, CustomerNotification $notification, User $admin, string $about): void
    {
        if (! $this->notifier->send($customer, $notification, resentBy: $admin)) {
            throw BookingRejected::notAllowed('This customer has no email address we can use.');
        }

        activity('notifications')
            ->performedOn($notification->subject() ?? $customer)
            ->causedBy($admin)
            ->event('resent')
            ->withProperties(['type' => $notification::type(), 'customer_id' => $customer->id, 'about' => $about])
            ->log('Email resent: '.$notification::label());
    }

    /** @throws BookingRejected */
    private function assertAdmin(User $admin): void
    {
        if (! $admin->isAdmin()) {
            throw BookingRejected::notAllowed('Only admins can resend emails.');
        }
    }
}
