<?php

declare(strict_types=1);

namespace App\Actions\Guest;

use App\Actions\Riders\SaveRider;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The riders a guest typed, added as new riders of the account behind the
 * email (guest checkout, decided 2026-11-12). Riders the account already
 * has are never reused: a saved rider could already be booked in the slot
 * or hold a waitlist place, and the refusal would tell a guest about
 * someone else's bookings. A name typed twice is added once.
 */
final class CreateGuestRiders
{
    public function __construct(private readonly SaveRider $save) {}

    /**
     * @param  list<array{name: string, age_bracket?: string|null, experience_level?: string|null}>  $riders
     * @return list<int> rider ids, in the order typed
     */
    public function handle(User $user, array $riders): array
    {
        $ids = [];

        foreach ($riders as $data) {
            $key = Str::lower((string) preg_replace('/\s+/u', ' ', trim($data['name'])));

            if ($key === '' || isset($ids[$key])) {
                continue;
            }

            $ids[$key] = $this->save->handle($user, [
                'name' => $data['name'],
                'age_bracket' => $data['age_bracket'] ?? null,
                'experience_level' => $data['experience_level'] ?? null,
            ])->id;
        }

        return array_values($ids);
    }
}
