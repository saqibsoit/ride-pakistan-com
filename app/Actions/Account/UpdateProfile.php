<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\User;
use Carbon\CarbonImmutable;
use Propaganistas\LaravelPhone\PhoneNumber;

/**
 * Customer profile: name, phone (stored as E.164) and marketing consent.
 * Consent is off unless the customer ticks it; every change of consent
 * (given or withdrawn) is timestamped and lands in the audit log.
 */
final class UpdateProfile
{
    public const DEFAULT_PHONE_COUNTRY = 'PK';

    /**
     * @param  array{name: string, phone?: string|null, marketing_consent?: bool}  $data
     */
    public function handle(User $user, array $data): User
    {
        $consent = (bool) ($data['marketing_consent'] ?? false);

        $user->fill([
            'name' => trim($data['name']),
            'phone' => self::toE164($data['phone'] ?? null),
        ]);

        if ($consent !== (bool) $user->marketing_consent) {
            $user->fill([
                'marketing_consent' => $consent,
                'marketing_consent_at' => CarbonImmutable::now(),
            ]);
        }

        $user->save();

        return $user;
    }

    /** Pakistani numbers may be typed nationally (0300…); others need +country. */
    public static function toE164(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        return (new PhoneNumber($phone, [self::DEFAULT_PHONE_COUNTRY]))->formatE164();
    }
}
