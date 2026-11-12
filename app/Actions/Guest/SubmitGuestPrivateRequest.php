<?php

declare(strict_types=1);

namespace App\Actions\Guest;

use App\Actions\PrivateRequests\SubmitPrivateRequest;
use App\Exceptions\GuestCheckoutRejected;
use App\Exceptions\PrivateRequestRejected;
use App\Models\PrivateSessionRequest;
use App\Models\Product;
use App\Models\User;
use App\Notifications\PrivateRequestNotSent;
use App\Support\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A private session request sent without signing in (guest checkout,
 * decided 2026-11-12): the account behind the email, the typed riders,
 * then SubmitPrivateRequest in guest mode.
 *
 * Refusals that depend only on the time (hours, notice, a clash) are shown
 * to the guest as usual. "This account already has an open request" is
 * not: nothing is saved, the guest sees the same "request sent" answer as
 * everyone else, and the account's owner gets an email instead
 * (PrivateRequestNotSent). Returns null in that case.
 */
final class SubmitGuestPrivateRequest
{
    public function __construct(
        private readonly ResolveGuestCustomer $customers,
        private readonly CreateGuestRiders $riders,
        private readonly SubmitPrivateRequest $submit,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @param  array{name: string, email: string, whatsapp: string}  $contact  whatsapp in E.164
     * @param  list<array{name: string, age_bracket?: string|null, experience_level?: string|null}>  $riders
     *
     * @throws GuestCheckoutRejected
     * @throws PrivateRequestRejected
     */
    public function handle(array $contact, Product $product, CarbonImmutable $startsAt, array $riders, ?string $note = null): ?PrivateSessionRequest
    {
        $owner = null;

        try {
            return DB::transaction(function () use ($contact, $product, $startsAt, $riders, $note, &$owner): PrivateSessionRequest {
                $owner = $this->customers->handle($contact['email'], $contact['name'], $contact['whatsapp']);

                return $this->submit->handle(
                    $owner,
                    $product,
                    $startsAt,
                    $this->riders->handle($owner, $riders),
                    $note,
                    guest: ['name' => trim($contact['name']), 'whatsapp' => $contact['whatsapp']],
                );
            });
        } catch (PrivateRequestRejected $e) {
            if (! $e->alreadyOpen || ! $owner instanceof User) {
                throw $e;
            }

            // The account was found or created inside the rolled-back
            // transaction; an existing one is still there to email.
            $account = User::query()->whereKey($owner->id)->first();

            if ($account !== null) {
                $this->notifier->send($account, new PrivateRequestNotSent($startsAt));
            }

            return null;
        }
    }
}
