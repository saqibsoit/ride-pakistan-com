<?php

declare(strict_types=1);

namespace App\Support\Tracking;

/**
 * Which tracking integrations are switched on. Each one is off when its
 * key is empty in .env, and nothing else changes: no banner, no config on
 * the page, no server calls.
 */
final class TrackingConfig
{
    public function metaPixelId(): ?string
    {
        return self::value('services.meta.pixel_id', '/^\d{5,20}$/');
    }

    public function metaCapiToken(): ?string
    {
        return self::value('services.meta.capi_token');
    }

    public function metaCapiTestCode(): ?string
    {
        return self::value('services.meta.capi_test_code');
    }

    public function metaGraphVersion(): string
    {
        return self::value('services.meta.graph_version', '/^v\d+\.\d+$/') ?? 'v21.0';
    }

    public function ga4Id(): ?string
    {
        return self::value('services.ga4.measurement_id', '/^G-[A-Z0-9]{4,20}$/');
    }

    /** Any browser tracking configured: show the consent banner. */
    public function anyEnabled(): bool
    {
        return $this->metaPixelId() !== null || $this->ga4Id() !== null;
    }

    public function capiEnabled(): bool
    {
        return $this->metaPixelId() !== null && $this->metaCapiToken() !== null;
    }

    private static function value(string $key, ?string $pattern = null): ?string
    {
        $value = config($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        return $pattern === null || preg_match($pattern, $value) ? $value : null;
    }
}
