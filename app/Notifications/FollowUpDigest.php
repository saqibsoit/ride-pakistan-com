<?php

declare(strict_types=1);

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/** To each admin and staff member at 09:00: the leads to follow up today (milestone 10c). */
final class FollowUpDigest extends CustomerNotification
{
    /**
     * @param  list<array{name: string, contact: string, status: string, due: string, overdue: bool, assignee: string, mine: bool, url: string}>  $leads
     */
    public function __construct(
        public readonly string $date,
        public readonly array $leads,
    ) {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'follow_up_digest';
    }

    public static function label(): string
    {
        return 'Follow-up digest (team)';
    }

    public function subject(): ?Model
    {
        return null;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = count($this->leads);

        return (new MailMessage)
            ->subject(sprintf('%d %s to follow up today', $count, $count === 1 ? 'lead' : 'leads'))
            ->markdown('mail.follow-up-digest', [
                'date' => CarbonImmutable::parse($this->date)->format('l j M'),
                'mine' => array_values(array_filter($this->leads, fn (array $lead): bool => $lead['mine'])),
                'others' => array_values(array_filter($this->leads, fn (array $lead): bool => ! $lead['mine'])),
            ]);
    }
}
