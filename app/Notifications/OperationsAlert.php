<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Actions\Monitoring\CheckOperationalHealth;
use App\Filament\Pages\VerifyTransfers;
use App\Filament\Pages\VerifyVoucherTransfers;
use App\Models\Slot;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To each admin (milestone 12): the queue has stopped or transfers are
 * waiting too long for review. Sent with notifyNow() by
 * CheckOperationalHealth, never queued, because the queue may be the
 * problem. Not a CustomerNotification for the same reason.
 */
final class OperationsAlert extends Notification
{
    public const STARTED = 'started';

    public const REPEATED = 'repeated';

    public const RESOLVED = 'resolved';

    public function __construct(
        public readonly string $problem,
        public readonly string $state,
        public readonly int $count,
        public readonly ?CarbonImmutable $oldest,
        public readonly CarbonImmutable $since,
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
        $tz = Slot::displayTimezone();
        $isQueue = $this->problem === CheckOperationalHealth::PROBLEM_QUEUE;

        $subject = match (true) {
            $this->state === self::RESOLVED && $isQueue => 'All clear: the queue is running again',
            $this->state === self::RESOLVED => 'All clear: no transfers waiting over '.CheckOperationalHealth::TRANSFER_MAX_HOURS.' hours',
            $isQueue => 'Background jobs are stuck',
            default => 'Bank transfers waiting over '.CheckOperationalHealth::TRANSFER_MAX_HOURS.' hours',
        };

        return (new MailMessage)
            ->subject(($this->state === self::REPEATED ? 'Still: ' : '').$subject)
            ->markdown('mail.operations-alert', [
                'heading' => $subject,
                'isQueue' => $isQueue,
                'resolved' => $this->state === self::RESOLVED,
                'count' => $this->count,
                'oldest' => $this->oldest?->setTimezone($tz)->format('D j M Y, H:i'),
                'since' => $this->since->setTimezone($tz)->format('D j M Y, H:i'),
                'queueMinutes' => CheckOperationalHealth::QUEUE_MAX_MINUTES,
                'transferHours' => CheckOperationalHealth::TRANSFER_MAX_HOURS,
                'repeatHours' => CheckOperationalHealth::REPEAT_HOURS,
                'ordersUrl' => VerifyTransfers::getUrl(panel: 'admin'),
                'vouchersUrl' => VerifyVoucherTransfers::getUrl(panel: 'admin'),
            ]);
    }
}
