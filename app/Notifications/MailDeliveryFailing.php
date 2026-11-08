<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Actions\Mail\ReportMailFailure;
use App\Models\Slot;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To each admin (milestone 12): an email could not be delivered after its
 * retries. Sent with notifyNow() by ReportMailFailure, at most once an
 * hour. If the provider itself is down this can fail too; then only the
 * log (critical) has it.
 */
final class MailDeliveryFailing extends Notification
{
    public function __construct(
        public readonly string $kind,
        public readonly string $error,
        public readonly CarbonImmutable $at,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Emails are failing to send')
            ->markdown('mail.mail-delivery-failing', [
                'kind' => $this->kind,
                'error' => $this->error,
                'at' => $this->at->setTimezone(Slot::displayTimezone())->format('D j M Y, H:i'),
                'minutes' => ReportMailFailure::ALERT_EVERY_MINUTES,
            ]);
    }
}
