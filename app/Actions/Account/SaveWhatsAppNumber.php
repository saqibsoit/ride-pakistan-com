<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\User;
use Propaganistas\LaravelPhone\Rules\Phone;

/**
 * Stores the customer's WhatsApp number (E.164) on their profile. Asked
 * for in the booking flow (prompt 14) and editable on /account/profile.
 * Pakistani numbers may be typed nationally (0334…); others need +country.
 */
final class SaveWhatsAppNumber
{
    public const MESSAGE = 'Enter a valid WhatsApp number, for example 0334 4554500 or +44 7911 123456.';

    /** @return list<mixed> */
    public static function rules(bool $required = true): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'max:32',
            (new Phone)->international()->country(UpdateProfile::DEFAULT_PHONE_COUNTRY),
        ];
    }

    public function handle(User $user, string $number): User
    {
        $e164 = UpdateProfile::toE164($number);

        if ($e164 !== null && $e164 !== $user->whatsapp_phone) {
            // Logged (redacted) through the model's activity log.
            $user->forceFill(['whatsapp_phone' => $e164])->save();
        }

        return $user;
    }
}
