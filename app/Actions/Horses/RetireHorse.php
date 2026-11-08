<?php

declare(strict_types=1);

namespace App\Actions\Horses;

use App\Models\Horse;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Retires a horse (milestone 10b): off the assignment lists for good, kept
 * for the bookings it carried. Existing assignments stay (history).
 * Reason required, logged.
 */
final class RetireHorse
{
    public function handle(Horse $horse, User $actor, string $reason): Horse
    {
        if (! $actor->can('retire', $horse)) {
            throw new AuthorizationException('Only an admin can retire a horse.');
        }

        return DB::transaction(function () use ($horse, $actor, $reason): Horse {
            $locked = Horse::query()->whereKey($horse->id)->lockForUpdate()->firstOrFail();

            $locked->retired_at = now()->toImmutable();
            $locked->is_active = false;
            $locked->save();

            activity('horses')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('retired')
                ->withProperties(['reason' => $reason])
                ->log('Horse retired: '.$locked->name);

            $horse->setRawAttributes($locked->getAttributes(), true);

            return $horse;
        });
    }
}
