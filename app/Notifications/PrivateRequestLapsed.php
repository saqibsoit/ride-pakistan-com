<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\OrderStatus;
use App\Models\PrivateSessionRequest;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the customer: the approved private session wasn't paid in time
 * (prompt 15B), so the slot has been released.
 */
final class PrivateRequestLapsed extends PrivateRequestNotification
{
    public function __construct(PrivateSessionRequest $request, public readonly OrderStatus $orderStatus)
    {
        parent::__construct($request);
    }

    public static function type(): string
    {
        return 'private_request_lapsed';
    }

    public static function label(): string
    {
        return 'Private request not paid in time';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->request->loadMissing(['user', 'product']);

        return (new MailMessage)
            ->subject('Your private session wasn’t booked ('.$request->reference.')')
            ->markdown('mail.private-request-lapsed', ['request' => $request, 'rejected' => $this->orderStatus === OrderStatus::Rejected, 'requestUrl' => route('book', ['product' => $request->product->slug])]);
    }
}
