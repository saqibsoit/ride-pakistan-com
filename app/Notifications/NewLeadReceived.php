<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/** To each admin: a new enquiry arrived from a site form (milestone 10). */
final class NewLeadReceived extends CustomerNotification
{
    public function __construct(public readonly Lead $lead)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'new_lead';
    }

    public static function label(): string
    {
        return 'New lead (admins)';
    }

    public function subject(): Model
    {
        return $this->lead;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New lead: '.$this->lead->name)
            ->markdown('mail.new-lead', [
                'lead' => $this->lead,
                'leadUrl' => LeadResource::getUrl('view', ['record' => $this->lead], panel: 'admin'),
            ]);
    }
}
