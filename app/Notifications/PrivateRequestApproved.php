<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PrivateSessionRequest;
use App\Support\ManageLink;
use App\Support\PrivateRequestLink;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the customer: their private session request is approved (prompt 15B).
 * The slot is held for them until the payment window ends; the email
 * has the amount, the deadline and the payment link.
 */
final class PrivateRequestApproved extends PrivateRequestNotification
{
    public static function type(): string
    {
        return 'private_request_approved';
    }

    public static function label(): string
    {
        return 'Private request approved (pay by)';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->request->loadMissing(['user', 'product']);

        return (new MailMessage)
            ->subject('Approved: pay by '.PrivateSessionRequest::localTime($request->pay_by).' to book '.$request->label())
            ->markdown('mail.private-request-approved', ['request' => $request, 'order' => $request->order, 'payUrl' => $request->order === null ? PrivateRequestLink::for($request) : ManageLink::forOrder($request->order)]);
    }
}
