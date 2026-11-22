<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Models\RideEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Copies an event with all its content, price tiers, questions, media and
 * rules into a new draft (decided 2026-11-22), so the next camp only needs
 * its dates. Dates and bookings are never copied. The slug gets "-copy"
 * (then "-copy-2"…) until it is free.
 */
final class DuplicateRideEvent
{
    public function handle(RideEvent $event, User $actor): RideEvent
    {
        return DB::transaction(function () use ($event, $actor): RideEvent {
            $source = RideEvent::query()->with('tiers')->whereKey($event->id)->firstOrFail();

            $copy = $source->replicate(['status', 'archived_at', 'created_at', 'updated_at', 'is_featured']);
            $copy->title = Str::limit($source->title.' (copy)', 120, '');
            $copy->slug = $this->freeSlug($source->slug);
            $copy->is_featured = false;
            $copy->booking_opens_at = null;
            $copy->save();

            foreach ($source->tiers as $tier) {
                $copy->tiers()->create($tier->only(['name', 'price_minor', 'seats_per_occurrence', 'sort']));
            }

            activity('content')
                ->performedOn($copy)
                ->causedBy($actor)
                ->event('duplicated')
                ->withProperties(['from' => $source->slug])
                ->log('Event '.$copy->title.' copied from '.$source->title);

            return $copy;
        });
    }

    private function freeSlug(string $slug): string
    {
        $base = Str::limit($slug, 110, '').'-copy';
        $candidate = $base;

        for ($i = 2; RideEvent::query()->where('slug', $candidate)->exists(); $i++) {
            $candidate = $base.'-'.$i;
        }

        return $candidate;
    }
}
