<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Mail\OrderPaidMail;
use Illuminate\Support\Facades\Mail;

/** Queues the "Payment received" receipt for the customer. */
final class SendOrderPaidReceipt
{
    public function handle(OrderPaid $event): void
    {
        $order = $event->order;
        $email = (string) $order->user->email;

        if ($email === '' || str_ends_with($email, '.invalid')) {
            return;
        }

        Mail::to($email)->queue(new OrderPaidMail($order));
    }
}
