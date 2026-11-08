<?php

declare(strict_types=1);

namespace App\Mail;

use App\Filament\Pages\VerifyTransfers;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To each admin: a transfer is waiting in the verification queue. */
final class TransferToVerifyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Order $order)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Transfer to verify: '.$this->order->reference);
    }

    public function content(): Content
    {
        $this->order->loadMissing(['user', 'product']);

        return new Content(markdown: 'mail.transfer-to-verify', with: ['queueUrl' => VerifyTransfers::getUrl()]);
    }
}
