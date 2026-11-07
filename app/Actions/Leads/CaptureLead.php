<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Enums\LeadInterest;
use App\Enums\LeadStatus;
use App\Models\Lead;
use Carbon\CarbonImmutable;

/**
 * Stores an enquiry as a new lead with its marketing attribution.
 * Milestone 9 adds first-touch cookies, notifications and the CRM.
 */
final class CaptureLead
{
    public const ATTRIBUTION_FIELDS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'fbclid',
        'gclid',
        'landing_url',
        'referrer',
    ];

    /**
     * @param  array{name: string, phone?: string|null, email?: string|null, interest?: string|null, preferred_date?: string|null, message?: string|null}  $details
     * @param  array<string, mixed>  $attribution  keys from ATTRIBUTION_FIELDS; others ignored
     */
    public function handle(array $details, array $attribution = [], string $source = 'contact_form'): Lead
    {
        $tracking = [];

        foreach (self::ATTRIBUTION_FIELDS as $field) {
            $value = $attribution[$field] ?? null;
            $value = is_string($value) ? mb_substr(trim($value), 0, in_array($field, ['landing_url', 'referrer'], true) ? 2000 : 255) : null;
            $tracking[$field] = $value === '' ? null : $value;
        }

        $preferred = $details['preferred_date'] ?? null;

        return Lead::query()->create([
            'name' => trim($details['name']),
            'phone' => self::nullable($details['phone'] ?? null),
            'email' => self::nullable($details['email'] ?? null),
            'interest' => LeadInterest::tryFrom((string) ($details['interest'] ?? '')),
            'preferred_date' => $preferred ? CarbonImmutable::parse($preferred) : null,
            'message' => self::nullable($details['message'] ?? null),
            'status' => LeadStatus::New,
            'source' => $source,
            ...$tracking,
        ]);
    }

    private static function nullable(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
