<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\UserRole;
use App\Models\User;

/** Trainer accounts for "who runs this session" selects (milestone 10b). */
final class TrainerOptions
{
    /** @return array<int, string> */
    public static function all(): array
    {
        return User::query()
            ->where('role', UserRole::Trainer->value)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [$user->id => $user->displayName()])
            ->all();
    }
}
