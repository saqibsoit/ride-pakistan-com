<?php

declare(strict_types=1);

namespace App\Actions\Horses;

use App\Models\Horse;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Brings a retired horse back, inactive until an admin switches it on (milestone 10b). Logged. */
final class RestoreHorse
{
    public function handle(Horse $horse, User $actor): Horse
    {
        if (! $actor->can('restore', $horse)) {
            throw new AuthorizationException('Only an admin can bring a horse back.');
        }

        return DB::transaction(function () use ($horse, $actor): Horse {
            $locked = Horse::query()->whereKey($horse->id)->lockForUpdate()->firstOrFail();

            $locked->retired_at = null;
            $locked->save();

            activity('horses')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('restored')
                ->log('Horse back from retirement: '.$locked->name);

            $horse->setRawAttributes($locked->getAttributes(), true);

            return $horse;
        });
    }
}
