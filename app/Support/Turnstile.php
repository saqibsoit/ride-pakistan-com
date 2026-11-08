<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cloudflare Turnstile (PLAN §4 bot protection). Keys come from
 * TURNSTILE_SITE_KEY / TURNSTILE_SECRET_KEY. Without a secret the check
 * passes outside production (local, CI) and fails closed in production.
 */
final class Turnstile
{
    public const RESPONSE_FIELD = 'cf-turnstile-response';

    public const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function siteKey(): ?string
    {
        $key = config('services.turnstile.site_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function enabled(): bool
    {
        return $this->secret() !== null;
    }

    /**
     * Both keys set: the widget can render and its token can be checked.
     * Lead forms use Turnstile only then, so a missing key switches it
     * off instead of rejecting every enquiry (PLAN §7).
     */
    public function configured(): bool
    {
        return $this->siteKey() !== null && $this->secret() !== null;
    }

    public function verify(?string $token, ?string $ip = null): bool
    {
        $secret = $this->secret();

        if ($secret === null) {
            return ! app()->isProduction();
        }

        if ($token === null || $token === '' || strlen($token) > 2048) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->post(self::VERIFY_URL, array_filter([
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $ip,
                ]));
        } catch (Throwable $e) {
            Log::warning('Turnstile verification failed to connect.', ['exception' => $e->getMessage()]);

            return false;
        }

        return $response->successful() && $response->json('success') === true;
    }

    private function secret(): ?string
    {
        $secret = config('services.turnstile.secret_key');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
