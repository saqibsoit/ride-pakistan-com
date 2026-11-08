<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Enums\LeadStatus;
use App\Enums\SettingKey;
use App\Models\Lead;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;

/**
 * Follow-up suggestion (milestone 10c): when a lead is moved to
 * "Contacted", its next follow-up is suggested `leads.follow_up_days` [2]
 * days later (Pakistan date). 0 turns the suggestion off. The change
 * status form shows it to edit; the board applies it when the lead has no
 * follow-up date from today on.
 */
final class SuggestFollowUp
{
    public const MAX_DAYS = 30;

    public function __construct(
        private readonly Settings $settings,
        private readonly UpdateLeadPipeline $pipeline,
    ) {}

    public function date(): ?CarbonImmutable
    {
        $days = min(self::MAX_DAYS, max(0, $this->settings->int(SettingKey::LeadFollowUpDays) ?? 2));

        return $days === 0 ? null : CarbonImmutable::now((string) config('app.display_timezone'))->startOfDay()->addDays($days);
    }

    /** Board: apply the suggestion after a move to Contacted. Returns the date set, if any. */
    public function applyAfterContact(Lead $lead, User $actor): ?CarbonImmutable
    {
        $date = $this->date();
        $today = CarbonImmutable::now((string) config('app.display_timezone'))->startOfDay();

        if ($date === null || $lead->status !== LeadStatus::Contacted) {
            return null;
        }

        if ($lead->next_follow_up_at !== null && $lead->next_follow_up_at->toDateString() >= $today->toDateString()) {
            return null;
        }

        $this->pipeline->followUp($lead, $date, $actor);

        return $date;
    }
}
