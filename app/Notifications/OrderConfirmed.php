<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Actions\Payments\CreateStripeCheckout;
use App\Enums\PaymentMethod;
use App\Enums\SettingKey;
use App\Models\Booking;
use App\Models\Order;
use App\Notifications\Channels\WhatsAppMessage;
use App\Notifications\Concerns\AttachesCalendar;
use App\Support\Money;
use App\Support\Settings;
use App\Support\TicketQr;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "You're booked" (PLAN §3.1 step 4): sent once an order is paid, by card
 * or after a bank transfer is approved. Tickets with QR codes (inline and
 * attached as PNG) and the exact location pin, which appears only in
 * confirmed-booking emails and never on public pages.
 */
final class OrderConfirmed extends CustomerNotification
{
    use AttachesCalendar;

    public const BRAND_LINE = CreateStripeCheckout::BRAND_LINE;

    public function __construct(public readonly Order $order)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'order_confirmed';
    }

    public static function label(): string
    {
        return 'Booking confirmed (tickets and location)';
    }

    public function subject(): Model
    {
        return $this->order;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order->loadMissing(['product', 'bookings.slot', 'bookings.rider', 'creditPack']);
        $settings = app(Settings::class);
        $bookings = $order->bookings->sortBy(fn (Booking $b): string => $b->slot->starts_at->toIso8601String().'|'.$b->id)->values();

        $mail = (new MailMessage)
            ->subject('Booking confirmed: '.$order->reference.' ('.self::BRAND_LINE.')')
            ->markdown('mail.order-confirmed', [
                'order' => $order,
                'bookings' => $bookings,
                'viaTransfer' => $order->payment_method === PaymentMethod::BankTransfer,
                'mapPin' => $settings->string(SettingKey::MapPinUrl),
                'brand' => self::BRAND_LINE,
                'timezone' => (string) config('app.display_timezone'),
                'qr' => $bookings->mapWithKeys(fn (Booking $b): array => [$b->id => TicketQr::png($b, 6)])->all(),
                'calendar' => $this->googleLinks($bookings),
            ]);

        foreach ($bookings as $booking) {
            $mail->attachData(TicketQr::png($booking), 'ticket-'.$booking->ticket_code.'.png', ['mime' => 'image/png']);
        }

        return $bookings->isEmpty() ? $mail : $this->attachCalendar($mail, $bookings);
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        return new WhatsAppMessage('Ride Pakistan: booking '.$this->order->reference.' is confirmed ('.Money::format($this->order->amount_minor, $this->order->currency).'). Your tickets are in your email and account.');
    }
}
