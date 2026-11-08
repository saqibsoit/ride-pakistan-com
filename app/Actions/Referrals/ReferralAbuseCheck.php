<?php

declare(strict_types=1);

namespace App\Actions\Referrals;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Basic referral abuse checks (milestone 10e), run when a referral is
 * attached and again before the reward. Returns the reason a referral
 * must be rejected, or null when it is fine:
 *
 * - self-referral (the same account);
 * - the same email once normalised (case, and for every domain a
 *   "+tag"; for Gmail also dots and googlemail.com);
 * - the same phone number (E.164), when both have one;
 * - a referrer who isn't an active customer (team account, deleted,
 *   anonymised).
 */
final class ReferralAbuseCheck
{
    public function reason(User $referrer, User $referred): ?string
    {
        if ($referrer->is($referred)) {
            return 'Self-referral';
        }

        if ($referrer->role !== UserRole::Customer || $referrer->trashed() || $referrer->isAnonymised()) {
            return 'The referrer is not an active customer';
        }

        if (self::normaliseEmail((string) $referrer->email) === self::normaliseEmail((string) $referred->email)) {
            return 'Same email address as the referrer';
        }

        $phone = (string) $referrer->phone;

        if ($phone !== '' && $phone === (string) $referred->phone) {
            return 'Same phone number as the referrer';
        }

        return null;
    }

    public static function normaliseEmail(string $email): string
    {
        $email = mb_strtolower(trim($email));
        $at = strrpos($email, '@');

        if ($at === false) {
            return $email;
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        $plus = strpos($local, '+');

        if ($plus !== false) {
            $local = substr($local, 0, $plus);
        }

        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return $local.'@'.$domain;
    }
}
