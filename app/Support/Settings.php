<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\SettingKey;
use App\Enums\SettingType;
use App\Models\Setting;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\QueryException;

/**
 * Typed, cached access to admin-editable settings. Stored values override
 * the defaults in config/settings.php. Write through App\Actions\Settings\UpdateSettings.
 */
final class Settings
{
    public const CACHE_KEY = 'settings.values';

    /** @var array<string, mixed>|null */
    private ?array $stored = null;

    public function __construct(private readonly Cache $cache) {}

    public function get(SettingKey $key): mixed
    {
        $stored = $this->stored();

        return array_key_exists($key->value, $stored) ? $stored[$key->value] : $key->default();
    }

    public function string(SettingKey $key): string
    {
        $value = $this->get($key);

        return is_scalar($value) ? (string) $value : '';
    }

    public function float(SettingKey $key): ?float
    {
        $value = $this->get($key);

        return is_numeric($value) ? (float) $value : null;
    }

    public function bool(SettingKey $key): bool
    {
        return (bool) $this->get($key);
    }

    public function int(SettingKey $key): ?int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return list<string>
     */
    public function list(SettingKey $key): array
    {
        $value = $this->get($key);

        return is_array($value) ? self::cleanList($value) : [];
    }

    /**
     * A policy list with ":cutoff" and ":min_age" filled in. Items that
     * mention an unset value are left out rather than shown half-empty.
     *
     * @return list<string>
     */
    public function policies(SettingKey $key): array
    {
        $replacements = [
            ':cutoff' => $this->int(SettingKey::CancellationCutoffHours),
            ':min_age' => $this->int(SettingKey::MinRiderAge),
        ];

        $items = [];

        foreach ($this->list($key) as $item) {
            foreach ($replacements as $token => $value) {
                if (! str_contains($item, $token)) {
                    continue;
                }

                if ($value === null) {
                    continue 2;
                }

                $item = str_replace($token, (string) $value, $item);
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * Normalise a raw (form) value to the stored shape for the key's type.
     */
    public static function normalise(SettingKey $key, mixed $value): mixed
    {
        return match ($key->type()) {
            SettingType::Integer => is_numeric($value) ? (int) $value : null,
            SettingType::Decimal => is_numeric($value) ? (float) $value : null,
            SettingType::Roles => self::cleanList(is_array($value) ? $value : []),
            SettingType::Boolean => (bool) $value,
            SettingType::List => self::cleanList(is_array($value) ? $value : []),
            default => is_scalar($value) ? trim((string) $value) : '',
        };
    }

    public function flush(): void
    {
        $this->stored = null;
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * @param  array<mixed>  $items
     * @return list<string>
     */
    private static function cleanList(array $items): array
    {
        $clean = [];

        foreach ($items as $item) {
            $item = is_scalar($item) ? trim((string) $item) : '';

            if ($item !== '') {
                $clean[] = $item;
            }
        }

        return $clean;
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        if ($this->stored !== null) {
            return $this->stored;
        }

        try {
            /** @var array<string, mixed> $values */
            $values = $this->cache->rememberForever(
                self::CACHE_KEY,
                static fn (): array => Setting::query()->pluck('value', 'key')->all(),
            );
        } catch (QueryException) {
            // Before migrations have run (fresh install): defaults only.
            return [];
        }

        return $this->stored = $values;
    }
}
