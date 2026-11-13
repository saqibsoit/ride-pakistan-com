<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use App\Support\ManageLink;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the customer: a new "Manage your booking" link, after the old ones
 * were stopped or when they asked for one again (guest checkout).
 */
final class ManageLinkReissued extends CustomerNotification
{
    public function __construct(public readonly Order $order, public readonly bool $revoked = true)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'manage_link_reissued';
    }

    public static function label(): string
    {
        return 'New manage link';
    }

    public function subject(): Model
    {
        return $this->order;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your link to manage booking '.$this->order->reference)
            ->markdown('mail.manage-link-reissued', [
                'order' => $this->order,
                'url' => ManageLink::forOrder($this->order),
                'revoked' => $this->revoked,
            ]);
    }
}
