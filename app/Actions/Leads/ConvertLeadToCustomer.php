<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Enums\UserRole;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Convert to customer" (admins): links the lead to a customer account.
 * With `$existing` it links that account; otherwise it finds one by the
 * lead's email (then phone) or creates a customer with the lead's email.
 * Sign-in is by email, so creating needs one. The customer gets no email
 * from this; they sign in with a one-time code like everyone else.
 */
final class ConvertLeadToCustomer
{
    /**
     * @throws ValidationException
     */
    public function handle(Lead $lead, User $actor, ?User $existing = null, ?string $email = null): User
    {
        return DB::transaction(function () use ($lead, $actor, $existing, $email): User {
            $locked = Lead::query()->whereKey($lead->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->user_id !== null) {
                throw ValidationException::withMessages(['user' => 'This lead is already linked to a customer.']);
            }

            $email = Str::lower(trim((string) ($email ?? $locked->email)));
            $created = false;
            $user = $existing ?? $this->find($email, $locked->phone);

            if ($user === null) {
                if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    throw ValidationException::withMessages(['email' => 'Add the customer’s email: they sign in with it.']);
                }

                $user = new User(['email' => $email, 'name' => $locked->name, 'phone' => self::e164($locked->phone)]);
                $user->role = UserRole::Customer;
                $user->save();
                $created = true;
            }

            if ($user->isTeamMember() || $user->trashed() || $user->isAnonymised()) {
                throw ValidationException::withMessages(['user' => 'Leads can only be linked to an active customer account.']);
            }

            $locked->user_id = $user->id;

            if ($locked->email === null && $email !== '') {
                $locked->email = $email;
            }

            $locked->save();
            $lead->setRawAttributes($locked->getAttributes(), true);

            activity('leads')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('converted')
                ->withProperties(['user_id' => $user->id, 'created' => $created])
                ->log(sprintf('Lead #%d %s customer #%d', $locked->id, $created ? 'converted to new' : 'linked to', $user->id));

            return $user;
        });
    }

    private function find(string $email, ?string $phone): ?User
    {
        if ($email !== '') {
            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

            if ($user !== null) {
                return $user;
            }
        }

        $phone = self::e164($phone);

        return $phone === null ? null : User::query()->where('phone', $phone)->first();
    }

    /** "+92 300 123-4567" / "0300…" → "+923001234567"; null if not a number. */
    public static function e164(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = '92'.substr($digits, 1);
        }

        return strlen($digits) >= 8 && strlen($digits) <= 15 ? '+'.$digits : null;
    }
}
