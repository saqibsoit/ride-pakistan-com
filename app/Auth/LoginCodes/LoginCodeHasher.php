<?php

declare(strict_types=1);

namespace App\Auth\LoginCodes;

use RuntimeException;

/**
 * HMAC-SHA256 of a code, bound to its destination and keyed with the app
 * key, so a leaked login_codes table can't be brute-forced offline.
 */
final class LoginCodeHasher
{
    public function hash(string $destination, string $code): string
    {
        return hash_hmac('sha256', $destination.'|'.$code, $this->key());
    }

    public function matches(string $hash, string $destination, string $code): bool
    {
        return hash_equals($hash, $this->hash($destination, $code));
    }

    private function key(): string
    {
        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('APP_KEY is required to hash sign-in codes.');
        }

        return $key;
    }
}
