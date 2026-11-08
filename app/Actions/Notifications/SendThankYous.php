<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Slot;
use App\Models\User;
use App\Notifications\ThankYou;
use App\Support\Notifier;
use Carbon\CarbonImmutable;

/**
 * thankyou:send (hourly). Customers whose riders attended a session that
 * ended between notifications.thank_you.after_hours [1] and until_hours
 * [24] ago get one thank-you per session. No-shows get nothing.
 */
final class SendThankYous
{
    public function __construct(private readonly Notifier $notifier) {}

    /** @return int emails queued */
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $after = (int) config('notifications.thank_you.after_hours', 1);
        $until = (int) config('notifications.thank_you.until_hours', 24);
        $sent = 0;

        $slots = Slot::query()
            ->where('ends_at', '<=', $now->subHours($after))
            ->where('ends_at', '>', $now->subHours($until))
            ->get();

        foreach ($slots as $slot) {
            $byUser = Booking::query()
                ->where('slot_id', $slot->id)
                ->where('status', BookingStatus::Attended)
                ->get()
                ->groupBy('user_id');

            foreach ($byUser as $userId => $bookings) {
                $user = User::query()->find($userId);

                if ($user !== null && $this->notifier->send($user, new ThankYou($slot, $bookings), Notifier::key(ThankYou::type(), $slot, $user))) {
                    $sent++;
                }
            }
        }

        return $sent;
    }
}
