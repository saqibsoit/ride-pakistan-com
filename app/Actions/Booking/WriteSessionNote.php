<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The trainer's note for one rider's session (milestone 10b): how it went,
 * what to work on next. Stored encrypted; the audit log records who wrote
 * it and when, never the text. An empty note clears it.
 */
final class WriteSessionNote
{
    public const MAX_LENGTH = 2000;

    public function handle(Booking $booking, ?string $note, User $actor): Booking
    {
        if (! $actor->can('writeNote', $booking)) {
            throw new AuthorizationException('You can\'t write notes for this session.');
        }

        $note = $note === null ? null : trim($note);
        $note = $note === '' || $note === null ? null : mb_substr($note, 0, self::MAX_LENGTH);

        return DB::transaction(function () use ($booking, $note, $actor): Booking {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($locked->session_note === $note) {
                return $booking;
            }

            $locked->session_note = $note;
            $locked->save();

            activity('bookings')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event($note === null ? 'session_note_cleared' : 'session_note_written')
                ->withProperties(['fields' => ['session_note']])
                ->log(sprintf('Booking %s: session note %s', $locked->ticket_code, $note === null ? 'cleared' : 'saved'));

            $booking->setRawAttributes($locked->getAttributes(), true);

            return $booking;
        });
    }
}
