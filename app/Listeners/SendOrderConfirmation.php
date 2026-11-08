<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Notifications\OrderConfirmed;
use App\Support\Notifier;

/** Sends "You're booked" (tickets, map pin) once per paid order: card or approved transfer. */
final class SendOrderConfirmation
{
    public function __construct(private readonly Notifier $notifier) {}

    public function handle(OrderPaid $event): void
    {
        $order = $event->order->loadMissing('user');

        $this->notifier->send($order->user, new OrderConfirmed($order), Notifier::key(OrderConfirmed::type(), $order, $order->user));
    }
}
