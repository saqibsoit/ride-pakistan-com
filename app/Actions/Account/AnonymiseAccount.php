<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\LoginCode;
use App\Models\Rider;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Removes the personal data of a deleted account (PLAN §7), keeping the
 * order, booking and payment records the business must retain:
 *  - account: name, phone and consent cleared; email replaced with a
 *    placeholder (frees the address for a new account);
 *  - riders: deleted when never booked or ordered, otherwise renamed and
 *    their emergency contact and notes removed;
 *  - enquiries (leads) linked to the account: contact details, Instagram
 *    handle, message, lost reason, landing page, referrer, click ids and
 *    notes (source, status and dates stay for reporting);
 *  - sign-in codes for the email;
 *  - audit-log values recorded for the account and its riders.
 *
 * Runs with model logging off so the old values are not written to the
 * audit log on the way out. Only AnonymiseDeletedAccounts decides when.
 */
final class AnonymiseAccount
{
    public const FORMER_RIDER = 'Former rider';

    public function handle(User $user): void
    {
        if ($user->isAnonymised()) {
            return;
        }

        $email = $user->email;
        $now = CarbonImmutable::now();

        activity()->withoutLogs(function () use ($user, $email, $now): void {
            DB::transaction(function () use ($user, $email, $now): void {
                $riders = $user->riders()->get();

                $riders->each(function (Rider $rider): void {
                    if ($rider->isInUse()) {
                        $rider->forceFill([
                            'name' => self::FORMER_RIDER,
                            'emergency_contact_name' => null,
                            'emergency_contact_phone' => null,
                            'notes' => null,
                        ])->save();

                        return;
                    }

                    $rider->delete();
                });

                $leads = Lead::query()->where('user_id', $user->id);

                LeadNote::query()->whereIn('lead_id', (clone $leads)->select('id'))->delete();

                // Source, status, dates and the order link stay for reporting.
                $leads->update([
                    'name' => 'Former customer',
                    'phone' => null,
                    'email' => null,
                    'instagram_handle' => null,
                    'message' => null,
                    'lost_reason' => null,
                    'landing_url' => null,
                    'referrer' => null,
                    'fbclid' => null,
                    'gclid' => null,
                    'tracking_event_id' => null,
                ]);

                LoginCode::query()->where('destination', $email)->delete();

                $this->redactAuditLog($user, $riders);

                $user->forceFill([
                    'name' => null,
                    'email' => 'deleted-'.$user->id.'@anonymised.invalid',
                    'phone' => null,
                    'marketing_consent' => false,
                    'marketing_consent_at' => null,
                    'password' => null,
                    'remember_token' => null,
                    'anonymised_at' => $now,
                ])->save();
            });
        });

        activity('account')
            ->performedOn($user)
            ->event('anonymised')
            ->log('Account anonymised after deletion');
    }

    /**
     * @param  Collection<int, Rider>  $riders
     */
    private function redactAuditLog(User $user, Collection $riders): void
    {
        Activity::query()
            ->where(function ($query) use ($user, $riders): void {
                $query->where(fn ($q) => $q->where('subject_type', $user->getMorphClass())->where('subject_id', $user->id))
                    ->orWhere(fn ($q) => $q->where('subject_type', (new Rider)->getMorphClass())->whereIn('subject_id', $riders->modelKeys()));
            })
            ->each(function (Activity $activity): void {
                $properties = $activity->properties;

                if ($properties === null) {
                    return;
                }

                foreach (['attributes', 'old'] as $set) {
                    $values = $properties->get($set);

                    if (is_array($values)) {
                        $properties = $properties->put($set, array_map(fn (): string => User::REDACTED, $values));
                    }
                }

                $activity->properties = $properties;
                $activity->save();
            });
    }
}
