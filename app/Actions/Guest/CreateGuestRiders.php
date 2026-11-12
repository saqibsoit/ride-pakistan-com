<?php

declare(strict_types=1);

namespace App\Actions\Guest;

use App\Actions\Riders\SaveRider;
use App\Models\Rider;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The riders a guest typed, as riders of the account behind the email
 * (guest checkout, decided 2026-11-12). A rider of that account with the
 * same name (case and spacing ignored) is reused, and its saved details
 * are never changed or shown: the guest only ever sees the names they
 * typed. Everyone else is added as a new rider.
 */
final class CreateGuestRiders
{
    public function __construct(private readonly SaveRider $save) {}

    /**
     * @param  list<array{name: string, age_bracket?: string|null, experience_level?: string|null}>  $riders
     * @return list<int> rider ids, in the order typed, without duplicates
     */
    public function handle(User $user, array $riders): array
    {
        $existing = $user->riders()->get()->keyBy(fn (Rider $rider): string => self::key($rider->name));
        $ids = [];

        foreach ($riders as $data) {
            $key = self::key($data['name']);

            if ($key === '') {
                continue;
            }

            $rider = $existing->get($key);

            if ($rider === null) {
                $rider = $this->save->handle($user, [
                    'name' => $data['name'],
                    'age_bracket' => $data['age_bracket'] ?? null,
                    'experience_level' => $data['experience_level'] ?? null,
                ]);
                $existing->put($key, $rider);
            }

            $ids[] = $rider->id;
        }

        return array_values(array_unique($ids));
    }

    private static function key(string $name): string
    {
        return Str::lower((string) preg_replace('/\s+/u', ' ', trim($name)));
    }
}
