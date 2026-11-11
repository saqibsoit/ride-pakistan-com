<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Resources\PrivateRequests\PrivateRequestResource;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To every admin, straight away (prompt 15B): a private session request
 * to approve or decline before it expires. The buttons open the admin
 * request page (panel sign-in and MFA still apply); nothing happens on
 * the link itself.
 */
final class PrivateRequestToReview extends PrivateRequestNotification
{
    public static function type(): string
    {
        return 'private_request_to_review';
    }

    public static function label(): string
    {
        return 'Private request to review (admins)';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->request->loadMissing(['user', 'product']);

        return (new MailMessage)
            ->subject('Private session request: '.$request->label())
            ->markdown('mail.private-request-to-review', ['request' => $request, 'viewUrl' => PrivateRequestResource::getUrl('view', ['record' => $request->id], panel: 'admin'), 'approveUrl' => PrivateRequestResource::getUrl('view', ['record' => $request->id, 'action' => 'approve'], panel: 'admin'), 'declineUrl' => PrivateRequestResource::getUrl('view', ['record' => $request->id, 'action' => 'decline'], panel: 'admin')]);
    }
}
