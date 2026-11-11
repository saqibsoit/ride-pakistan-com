<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To a trainer (prompt 15A): the sessions of theirs that one bulk schedule
 * change created, moved, resized, reassigned, closed or cancelled. One
 * email per trainer per bulk run.
 */
final class ScheduleChangedSummary extends CustomerNotification
{
    /**
     * @param  list<string>  $lines  one per session, Pakistan times
     */
    public function __construct(public readonly string $batch, public readonly array $lines)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'schedule_changed_summary';
    }

    public static function label(): string
    {
        return 'Schedule changed (trainer summary)';
    }

    public function subject(): ?Model
    {
        return null;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(count($this->lines) === 1 ? 'A session of yours changed' : count($this->lines).' of your sessions changed')
            ->markdown('mail.schedule-changed-summary', [
                'lines' => $this->lines,
                'runSheets' => route('team.sessions.index'),
            ]);
    }
}
