<?php

declare(strict_types=1);

namespace App\Actions\Referrals;

use App\Actions\Orders\GenerateOrderReference;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * A customer's personal referral code (milestone 10e), created the first
 * time it is needed: 8 characters from the unambiguous alphabet, used in
 * the link /r/{code}. It never changes once set.
 */
final class EnsureReferralCode
{
    public const LENGTH = 8;

    private const MAX_ATTEMPTS = 10;

    public function handle(User $user): string
    {
        if ($user->referral_code !== null) {
            return $user->referral_code;
        }

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $code = self::random();

            try {
                // Only set it if still empty (two tabs at once keep one code).
                $updated = User::query()->whereKey($user->id)->whereNull('referral_code')->update(['referral_code' => $code]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }

            $stored = $updated === 1 ? $code : (string) User::withTrashed()->whereKey($user->id)->value('referral_code');
            $user->referral_code = $stored;
            $user->syncOriginalAttribute('referral_code');

            return $stored;
        }

        throw new RuntimeException('Could not generate a unique referral code.');
    }

    public static function random(): string
    {
        $alphabet = GenerateOrderReference::ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    public static function url(User $user): string
    {
        return route('referral', ['code' => app(self::class)->handle($user)]);
    }
}
