<?php

declare(strict_types=1);

namespace App\Actions\Progress;

use App\Models\Booking;
use App\Models\RiderSkill;
use App\Models\Skill;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * After a session the trainer ticks the skills a rider has shown and adds
 * a short note the customer sees (milestone 10d). $skillIds is the full
 * set of ticked skills from the run sheet form: new ones are recorded
 * against this session, unticked ones removed (to fix a mistake). Only
 * active skills can be ticked; skills switched off later stay on the
 * rider. The audit log ("progress") records the skill ids and whether the
 * note changed, never its text.
 */
final class RecordRiderProgress
{
    public const NOTE_MAX_LENGTH = 500;

    /**
     * @param  list<int>  $skillIds
     *
     * @throws AuthorizationException
     */
    public function handle(Booking $booking, array $skillIds, ?string $note, User $actor): Booking
    {
        if (! $actor->can('recordProgress', $booking)) {
            throw new AuthorizationException('You can\'t record progress for this session.');
        }

        $note = $note === null ? null : trim($note);
        $note = $note === '' || $note === null ? null : mb_substr($note, 0, self::NOTE_MAX_LENGTH);

        return DB::transaction(function () use ($booking, $skillIds, $note, $actor): Booking {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $riderId = $locked->rider_id;

            $active = Skill::query()->active()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            $wanted = array_values(array_intersect(array_unique(array_map('intval', $skillIds)), $active));

            $current = RiderSkill::query()->where('rider_id', $riderId)->lockForUpdate()->get()->keyBy('skill_id');

            // Unticking applies to active skills only: a switched-off skill isn't on the form.
            $removed = $current->keys()
                ->map(fn (mixed $id): int => (int) $id)
                ->filter(fn (int $id): bool => in_array($id, $active, true) && ! in_array($id, $wanted, true))
                ->values()
                ->all();
            $added = array_values(array_diff($wanted, $current->keys()->map(fn (mixed $id): int => (int) $id)->all()));

            if ($removed !== []) {
                RiderSkill::query()->where('rider_id', $riderId)->whereIn('skill_id', $removed)->delete();
            }

            $now = CarbonImmutable::now();

            foreach ($added as $skillId) {
                RiderSkill::query()->create([
                    'rider_id' => $riderId,
                    'skill_id' => $skillId,
                    'booking_id' => $locked->id,
                    'ticked_by' => $actor->id,
                    'achieved_at' => $now,
                ]);
            }

            $noteChanged = $locked->progress_note !== $note;

            if ($noteChanged) {
                $locked->progress_note = $note;
                $locked->save();
            }

            if ($added !== [] || $removed !== [] || $noteChanged) {
                activity('progress')
                    ->performedOn($locked)
                    ->causedBy($actor)
                    ->event('progress_recorded')
                    ->withProperties(array_filter([
                        'rider_id' => $riderId,
                        'skills_added' => $added,
                        'skills_removed' => $removed,
                        'note' => $noteChanged ? ($note === null ? 'cleared' : 'saved') : null,
                    ], fn (mixed $value): bool => $value !== null && $value !== []))
                    ->log(sprintf('Booking %s: progress recorded', $locked->ticket_code));
            }

            $booking->setRawAttributes($locked->getAttributes(), true);

            return $booking;
        });
    }
}
