<?php

declare(strict_types=1);

namespace App\Actions\Weather;

use App\Enums\SettingKey;
use App\Enums\UserRole;
use App\Models\Slot;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Collection;

/**
 * Who gets a slot's weather alert (setting weather.recipients, milestone
 * 10f): every admin and/or every staff member, and with "trainer" only the
 * trainer who runs that slot. Customers never.
 */
final class WeatherRecipients
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @return Collection<int, User>
     */
    public function for(Slot $slot): Collection
    {
        $roles = array_values(array_filter(
            array_map(static fn (string $role): ?UserRole => UserRole::tryFrom($role), $this->settings->list(SettingKey::WeatherRecipients)),
        ));

        $teamRoles = array_values(array_filter($roles, static fn (UserRole $role): bool => in_array($role, [UserRole::Admin, UserRole::Staff], true)));

        $users = $teamRoles === []
            ? collect()
            : User::query()->whereIn('role', $teamRoles)->orderBy('id')->get()->toBase();

        if (in_array(UserRole::Trainer, $roles, true) && $slot->trainer_id !== null) {
            $trainer = User::query()->whereKey($slot->trainer_id)->where('role', UserRole::Trainer)->first();

            if ($trainer !== null) {
                $users->push($trainer);
            }
        }

        return $users->unique('id')->values();
    }
}
