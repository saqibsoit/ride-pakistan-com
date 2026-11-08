<?php

declare(strict_types=1);

namespace App\Actions\Horses;

use App\Enums\BookingStatus;
use App\Enums\HorseLevel;
use App\Exceptions\HorseAssignmentRejected;
use App\Models\Booking;
use App\Models\Horse;
use App\Models\Slot;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Puts a rider on a horse for one session (milestone 10b). Staff, admins
 * and the session's trainer assign from the run sheet or the panel.
 *
 * Blocks: a cancelled booking; a horse that is inactive or retired, has a
 * rest or vet day on the session's Pakistan date, already carries another
 * rider in this or an overlapping session, or has reached its maximum
 * sessions that day (non-cancelled bookings). Warns only: the horse isn't
 * listed for the rider's level, or the rider's level isn't recorded.
 *
 * The horse row is locked first, so two assignments can't both take a
 * horse's last session of the day. Passing no horse unassigns. Logged.
 */
final class AssignHorse
{
    /**
     * @throws HorseAssignmentRejected
     * @throws AuthorizationException
     */
    public function handle(Booking $booking, ?Horse $horse, User $actor): AssignmentResult
    {
        if (! $actor->can('assignHorse', $booking)) {
            throw new AuthorizationException('You can\'t assign horses for this session.');
        }

        return DB::transaction(function () use ($booking, $horse, $actor): AssignmentResult {
            $lockedHorse = $horse === null ? null : Horse::query()->whereKey($horse->id)->lockForUpdate()->firstOrFail();
            $locked = Booking::query()->whereKey($booking->id)->with(['slot', 'rider'])->lockForUpdate()->firstOrFail();

            if ($locked->status === BookingStatus::Cancelled) {
                throw new HorseAssignmentRejected('This booking was cancelled.');
            }

            $from = $locked->horse_id;

            if ($from === $lockedHorse?->id) {
                return new AssignmentResult($booking, false, $lockedHorse === null ? [] : self::warnings($locked, $lockedHorse));
            }

            $warnings = [];

            if ($lockedHorse !== null) {
                self::assertCanWork($locked, $lockedHorse);
                $warnings = self::warnings($locked, $lockedHorse);
            }

            $locked->horse_id = $lockedHorse?->id;
            $locked->save();

            activity('bookings')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('horse_assigned')
                ->withProperties(['from' => $from, 'to' => $lockedHorse?->id, 'warnings' => $warnings])
                ->log(sprintf('Booking %s: horse %s', $locked->ticket_code, $lockedHorse === null ? 'removed' : $lockedHorse->name));

            $booking->setRawAttributes($locked->getAttributes(), true);
            $booking->unsetRelation('horse');

            return new AssignmentResult($booking, true, $warnings);
        });
    }

    /** @throws HorseAssignmentRejected */
    private static function assertCanWork(Booking $booking, Horse $horse): void
    {
        $slot = $booking->slot;
        $day = $slot->starts_at->setTimezone(Slot::displayTimezone())->startOfDay();

        if ($horse->isRetired()) {
            throw new HorseAssignmentRejected(sprintf('%s is retired.', $horse->name));
        }

        if (! $horse->is_active) {
            throw new HorseAssignmentRejected(sprintf('%s is not working at the moment (inactive).', $horse->name));
        }

        $off = $horse->unavailableOn($day);

        if ($off !== null) {
            throw new HorseAssignmentRejected(sprintf('%s has a %s on %s.', $horse->name, strtolower($off->reason->label()), $day->format('D j M')));
        }

        $others = Booking::query()
            ->where('horse_id', $horse->id)
            ->whereKeyNot($booking->id)
            ->where('status', '!=', BookingStatus::Cancelled);

        $clash = (clone $others)
            ->whereHas('slot', fn (Builder $query) => $query
                ->where('starts_at', '<', $slot->ends_at)
                ->where('ends_at', '>', $slot->starts_at))
            ->with('rider')
            ->first();

        if ($clash !== null) {
            throw new HorseAssignmentRejected(sprintf('%s already carries %s at this time.', $horse->name, $clash->rider->name));
        }

        $sameDay = (clone $others)
            ->whereHas('slot', fn (Builder $query) => $query
                ->where('starts_at', '>=', $day->utc())
                ->where('starts_at', '<', $day->addDay()->utc()))
            ->count();

        if ($sameDay >= $horse->max_sessions_per_day) {
            throw new HorseAssignmentRejected(sprintf(
                '%s has reached %d %s on %s.',
                $horse->name,
                $horse->max_sessions_per_day,
                $horse->max_sessions_per_day === 1 ? 'session' : 'sessions',
                $day->format('D j M'),
            ));
        }
    }

    /**
     * Level warnings (never blocking) for putting this rider on this horse.
     *
     * @return list<string>
     */
    public static function warnings(Booking $booking, Horse $horse): array
    {
        $rider = $booking->rider;
        $experience = $rider->experience_level;

        if ($experience === null) {
            return [sprintf('%s\'s riding experience isn\'t recorded: check %s suits them.', $rider->name, $horse->name)];
        }

        $level = HorseLevel::fromExperience($experience);

        if (! $horse->suits($level)) {
            return [sprintf('%s isn\'t listed for %s riders (%s: %s).', $horse->name, strtolower($level->label()), $rider->name, strtolower($experience->label()))];
        }

        return [];
    }
}
