<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Enums\BookingStatus;
use App\Enums\SettingKey;
use App\Exceptions\BookingRejected;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Booking;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;

/**
 * Gate check-in from the ticket QR (PLAN §6). Staff and admins record
 * attended from booking.checkin_opens_minutes [120] before the start until
 * the session ends, and no-show between the start and the end. Anything
 * else (another day, cancelled ticket) is refused with a clear reason.
 * Changes go through ChangeBookingStatus (validated, audit-logged).
 */
final class CheckInBooking
{
    public const OK = 'ok';

    public const CANCELLED = 'cancelled';

    public const WRONG_DAY = 'wrong_day';

    public const TOO_EARLY = 'too_early';

    public const ENDED = 'ended';

    public function __construct(
        private readonly ChangeBookingStatus $status,
        private readonly Settings $settings,
    ) {}

    /**
     * Whether staff can record attendance now, and if not, why.
     */
    public function state(Booking $booking, ?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now();
        $slot = $booking->slot;
        $tz = (string) config('app.display_timezone');

        if ($booking->status === BookingStatus::Cancelled) {
            return self::CANCELLED;
        }

        if (! $slot->starts_at->setTimezone($tz)->isSameDay($now->setTimezone($tz))) {
            return self::WRONG_DAY;
        }

        if ($now->isBefore($this->opensAt($booking))) {
            return self::TOO_EARLY;
        }

        if ($now->isAfter($slot->ends_at)) {
            return self::ENDED;
        }

        return self::OK;
    }

    public function opensAt(Booking $booking): CarbonImmutable
    {
        return $booking->slot->starts_at->subMinutes(max(0, $this->settings->int(SettingKey::CheckinOpensMinutes) ?? 120));
    }

    public function canMarkNoShow(Booking $booking, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return $this->state($booking, $now) === self::OK && ! $booking->slot->starts_at->isAfter($now);
    }

    /** @throws BookingRejected */
    public function handle(Booking $booking, User $actor, BookingStatus $to): Booking
    {
        if (! $actor->isTeamMember()) {
            throw BookingRejected::notAllowed('Only staff can check riders in.');
        }

        if (! in_array($to, [BookingStatus::Attended, BookingStatus::NoShow], true)) {
            throw BookingRejected::notAllowed('Check-in records attended or no-show only.');
        }

        $booking->refresh();

        $state = $this->state($booking);

        if ($state !== self::OK) {
            throw BookingRejected::notAllowed(self::message($state));
        }

        if ($booking->status === $to) {
            return $booking; // already recorded (double tap)
        }

        if ($to === BookingStatus::NoShow && ! $this->canMarkNoShow($booking)) {
            throw BookingRejected::notAllowed('No-show can be recorded once the session has started.');
        }

        try {
            return $this->status->handle($booking, $to, $actor, 'Gate check-in', earlyCheckIn: true);
        } catch (InvalidStatusTransition) {
            throw BookingRejected::notAllowed('This ticket can\'t be changed any more.');
        }
    }

    public static function message(string $state): string
    {
        return match ($state) {
            self::CANCELLED => 'This booking was cancelled.',
            self::WRONG_DAY => 'This ticket is for another day.',
            self::TOO_EARLY => 'Check-in hasn\'t opened yet for this session.',
            self::ENDED => 'This session has ended.',
            default => '',
        };
    }
}
