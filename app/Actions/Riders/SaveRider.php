<?php

declare(strict_types=1);

namespace App\Actions\Riders;

use App\Actions\Account\UpdateProfile;
use App\Enums\AgeBracket;
use App\Enums\ExperienceLevel;
use App\Models\Rider;
use App\Models\User;
use InvalidArgumentException;

/**
 * Adds or updates one of a customer's riders (themselves or others).
 * Emergency contact and notes are encrypted at rest and redacted in the
 * audit log (Rider casts).
 */
final class SaveRider
{
    /**
     * @param  array{name: string, age_bracket?: string|null, experience_level?: string|null, emergency_contact_name?: string|null, emergency_contact_phone?: string|null, notes?: string|null}  $data
     */
    public function handle(User $owner, array $data, ?Rider $rider = null): Rider
    {
        if ($rider !== null && $rider->user_id !== $owner->id) {
            throw new InvalidArgumentException('Riders can only be changed by their own account.');
        }

        $attributes = [
            'name' => trim($data['name']),
            'age_bracket' => AgeBracket::tryFrom((string) ($data['age_bracket'] ?? '')),
            'experience_level' => ExperienceLevel::tryFrom((string) ($data['experience_level'] ?? '')),
            'emergency_contact_name' => self::blankToNull($data['emergency_contact_name'] ?? null),
            'emergency_contact_phone' => UpdateProfile::toE164($data['emergency_contact_phone'] ?? null),
            'notes' => self::blankToNull($data['notes'] ?? null),
        ];

        if ($rider === null) {
            return $owner->riders()->create($attributes);
        }

        $rider->update($attributes);

        return $rider;
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
