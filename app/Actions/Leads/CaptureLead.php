<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Enums\LeadInterest;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Events\LeadCaptured;
use App\Models\Lead;
use App\Support\Tracking\Attribution;
use App\Support\Tracking\VisitorContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Stores a site enquiry (contact form, /prices question) as a new lead
 * with its attribution. The source is chosen by the route, never by the
 * request. `$visitor` is passed only when the visitor granted tracking
 * consent; it marks the lead and lets Meta CAPI send the Lead event with
 * the same event id the browser Pixel uses.
 */
final class CaptureLead
{
    /**
     * @param  array{name: string, phone?: string|null, email?: string|null, interest?: string|null, preferred_date?: string|null, message?: string|null}  $details
     * @param  Attribution|array<string, mixed>  $attribution
     */
    public function handle(
        array $details,
        Attribution|array $attribution = [],
        LeadSource $source = LeadSource::ContactForm,
        ?VisitorContext $visitor = null,
    ): Lead {
        $attribution = is_array($attribution) ? Attribution::fromArray($attribution) : $attribution;
        $preferred = $details['preferred_date'] ?? null;

        $lead = Lead::query()->create([
            'name' => trim($details['name']),
            'phone' => self::nullable($details['phone'] ?? null),
            'email' => self::nullable($details['email'] ?? null),
            'interest' => LeadInterest::tryFrom((string) ($details['interest'] ?? '')),
            'preferred_date' => $preferred ? CarbonImmutable::parse($preferred) : null,
            'message' => self::nullable($details['message'] ?? null),
            'status' => LeadStatus::New,
            'source' => $source,
            ...$attribution->fields,
            'first_touch_at' => $attribution->firstTouchAt,
            'consent_tracking' => $visitor !== null,
            'tracking_event_id' => (string) Str::uuid(),
        ]);

        event(new LeadCaptured($lead, $visitor));

        return $lead;
    }

    private static function nullable(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
