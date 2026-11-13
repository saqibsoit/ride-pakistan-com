<?php

declare(strict_types=1);

namespace App\Actions\Guest;

use App\Auth\LoginCodes\LoginCodeChannel;
use App\Exceptions\GuestCheckoutRejected;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The customer account behind a guest checkout (decided 2026-11-12).
 *
 * - No account for the email: one is created (unverified; the first
 *   sign-in code verifies it, VerifyLoginCode).
 * - A customer account: it is reused and never changed, apart from filling
 *   a missing name or WhatsApp number. Its name, number, riders, credit and
 *   orders are never shown to the guest; the details typed for this
 *   booking are stored on the order (contact_name, contact_whatsapp).
 * - A team account, an account scheduled for deletion or an anonymised
 *   one: refused with one neutral message (GuestCheckoutRejected::EMAIL),
 *   and the account is left alone.
 *
 * Call inside a transaction: the account row is locked so two guest
 * checkouts for the same new email end up on one account.
 */
final class ResolveGuestCustomer
{
    public function __construct(private readonly LoginCodeChannel $channel) {}

    /**
     * @throws GuestCheckoutRejected
     */
    public function handle(string $email, string $name, ?string $whatsappE164): User
    {
        $email = $this->channel->normalise($email);
        $name = trim($name);

        $user = $this->find($email);

        if ($user === null) {
            try {
                $user = new User(['email' => $email, 'name' => $name, 'whatsapp_phone' => $whatsappE164]);
                $user->save();

                return $user;
            } catch (UniqueConstraintViolationException) {
                // Another guest checkout for the same email created it first.
                $user = $this->find($email);
            }
        }

        if ($user === null || $user->trashed() || $user->isPanelUser() || $user->isAnonymised()) {
            throw GuestCheckoutRejected::email();
        }

        $fill = [];

        if (blank($user->name) && $name !== '') {
            $fill['name'] = $name;
        }

        if ($user->whatsapp_phone === null && $whatsappE164 !== null) {
            $fill['whatsapp_phone'] = $whatsappE164;
        }

        if ($fill !== []) {
            $user->forceFill($fill)->save();
        }

        return $user;
    }

    private function find(string $email): ?User
    {
        return User::withTrashed()->where('email', $email)->lockForUpdate()->first();
    }
}
