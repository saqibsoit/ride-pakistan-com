<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Enums\BookingStatus;
use App\Enums\SlotStatus;
use App\Models\Booking;
use App\Models\Slot;
use App\Models\User;
use App\Notifications\SessionReminder;
use App\Support\Notifier;
use Carbon\CarbonImmutable;

/**
 * reminders:send (every 15 minutes). Customers with confirmed bookings in
 * a session starting in about 24 hours get one reminder per session,
 * covering all their riders. The window (24 h ± window) means a missed
 * scheduler run never skips a reminder; the dedupe key means it never
 * goes twice. Bookings made inside the window still get one.
 */
final class SendSessionReminders
{
    public function __construct(private readonly Notifier $notifier) {}

    /** @return int reminders queued */
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $hours = (int) config('notifications.reminder.hours_before', 24);
        $window = (int) config('notifications.reminder.window_hours', 1);
        $sent = 0;

        $slots = Slot::query()
            ->where('status', '!=', SlotStatus::Cancelled)
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->addHours($hours + $window))
            ->orderBy('starts_at')
            ->get();

        foreach ($slots as $slot) {
            $bookings = Booking::query()
                ->where('slot_id', $slot->id)
                ->where('status', BookingStatus::Confirmed)
                ->with('rider')
                ->get()
                ->groupBy('user_id');

            foreach ($bookings as $userId => $userBookings) {
                $user = User::query()->find($userId);

                if ($user !== null && $this->notifier->send($user, new SessionReminder($slot, $userBookings), Notifier::key(SessionReminder::type(), $slot, $user))) {
                    $sent++;
                }
            }
        }

        return $sent;
    }
}
