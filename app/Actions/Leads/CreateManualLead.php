<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Enums\LeadInterest;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A lead the team types in: mostly Instagram DMs, also WhatsApp, calls
 * and walk-ins. Needs a name and at least one way to reply (phone,
 * Instagram handle or email). The note becomes the lead's first note.
 */
final class CreateManualLead
{
    public const HANDLE_PATTERN = '/^[a-z0-9._]{1,30}$/';

    /**
     * @param  array{name: string, phone?: string|null, instagram_handle?: string|null, email?: string|null, interest?: string|null, note?: string|null, source?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function handle(array $data, User $author): Lead
    {
        $handle = self::normaliseHandle($data['instagram_handle'] ?? null);
        $phone = self::nullable($data['phone'] ?? null);
        $email = self::nullable($data['email'] ?? null);
        $email = $email === null ? null : Str::lower($email);
        $source = LeadSource::tryFrom((string) ($data['source'] ?? '')) ?? LeadSource::InstagramDm;

        if ($source->isWeb()) {
            $source = LeadSource::Other;
        }

        if ($handle === false) {
            throw ValidationException::withMessages(['instagram_handle' => 'Use letters, numbers, dots and underscores only (up to 30).']);
        }

        if ($handle === null && $phone === null && $email === null) {
            throw ValidationException::withMessages(['instagram_handle' => 'Add an Instagram handle, a phone number or an email.']);
        }

        return DB::transaction(function () use ($data, $author, $handle, $phone, $email, $source): Lead {
            $lead = Lead::query()->create([
                'name' => trim($data['name']),
                'phone' => $phone,
                'email' => $email,
                'instagram_handle' => $handle,
                'interest' => LeadInterest::tryFrom((string) ($data['interest'] ?? '')),
                'status' => LeadStatus::New,
                'source' => $source,
                'created_by' => $author->id,
            ]);

            $note = self::nullable($data['note'] ?? null);

            if ($note !== null) {
                $entry = new LeadNote(['body' => $note]);
                $entry->lead()->associate($lead);
                $entry->author()->associate($author);
                $entry->save();
            }

            activity('leads')
                ->performedOn($lead)
                ->causedBy($author)
                ->event('created')
                ->withProperties(['source' => $source->value])
                ->log(sprintf('Lead #%d added by hand (%s)', $lead->id, $source->label()));

            return $lead;
        });
    }

    /**
     * Open leads with the same handle or phone, to warn about duplicates.
     *
     * @return list<Lead>
     */
    public static function possibleDuplicates(?string $handle, ?string $phone, ?int $except = null): array
    {
        $handle = self::normaliseHandle($handle);
        $phone = self::nullable($phone);

        if (! is_string($handle) && $phone === null) {
            return [];
        }

        return array_values(Lead::query()
            ->whereIn('status', [LeadStatus::New, LeadStatus::Contacted, LeadStatus::Qualified])
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except))
            ->where(function ($q) use ($handle, $phone): void {
                if (is_string($handle)) {
                    $q->orWhere('instagram_handle', $handle);
                }
                if ($phone !== null) {
                    $q->orWhere('phone', $phone);
                }
            })
            ->limit(5)
            ->get()
            ->all());
    }

    /**
     * "@Ride.PK", "instagram.com/ride.pk/" → "ride.pk". Null when empty;
     * false when it can't be a handle.
     */
    public static function normaliseHandle(?string $value): string|false|null
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $value = (string) preg_replace('#^(https?://)?(www\.)?instagram\.com/#i', '', $value);
        $value = Str::lower(trim(ltrim($value, '@'), '/ '));

        return preg_match(self::HANDLE_PATTERN, $value) ? $value : false;
    }

    private static function nullable(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
