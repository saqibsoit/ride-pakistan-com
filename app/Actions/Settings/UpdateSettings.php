<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Enums\SettingKey;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Saves admin-edited settings: normalises each value to its type, writes
 * only what changed, logs the change and refreshes caches.
 */
final class UpdateSettings
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  array<string, mixed>  $values  keyed by SettingKey value
     * @return list<string> the keys that changed
     */
    public function handle(array $values, ?User $actor = null): array
    {
        $changed = DB::transaction(function () use ($values): array {
            $changed = [];

            foreach ($values as $rawKey => $value) {
                $key = SettingKey::tryFrom($rawKey);

                if ($key === null) {
                    continue;
                }

                $value = Settings::normalise($key, $value);

                if ($value === $this->settings->get($key)) {
                    continue;
                }

                Setting::query()->updateOrCreate(['key' => $key->value], ['value' => $value]);
                $changed[] = $key->value;
            }

            return $changed;
        });

        if ($changed === []) {
            return [];
        }

        // Values may be sensitive (bank details), so only key names go in the log.
        activity('settings')
            ->causedBy($actor)
            ->withProperties(['keys' => $changed])
            ->log('Settings updated');

        // Each saved Setting fires PublicContentChanged (observer), which
        // flushes settings and the page cache.
        $this->settings->flush();

        return $changed;
    }
}
