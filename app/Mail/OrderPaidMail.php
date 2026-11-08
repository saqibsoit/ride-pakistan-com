<?php

declare(strict_types=1);

namespace App\Mail;

use App\Actions\Payments\CreateStripeCheckout;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Receipt sent once an order is paid (PLAN §3.1 step 4). Tickets with QR
 * codes and reminders arrive in milestone 8.
 */
final class OrderPaidMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Order $order)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Payment received: '.$this->order->reference.' ('.CreateStripeCheckout::BRAND_LINE.')');
    }

    public function content(): Content
    {
        $this->order->loadMissing(['product', 'bookings.slot', 'bookings.rider', 'creditPack']);

        return new Content(
            markdown: 'mail.order-paid',
            with: [
                'brand' => CreateStripeCheckout::BRAND_LINE,
                'timezone' => (string) config('app.display_timezone'),
            ],
        );
    }
}
