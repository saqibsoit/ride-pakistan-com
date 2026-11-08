<?php

declare(strict_types=1);

use App\Actions\Leads\ChangeLeadStatus;
use App\Enums\LeadStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Lead;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->change = app(ChangeLeadStatus::class);
});

it('moves open leads freely and writes the audit log', function (): void {
    $admin = User::factory()->admin()->create();
    $lead = Lead::factory()->create();

    $this->change->handle($lead, LeadStatus::Qualified, $admin);
    $this->change->handle($lead, LeadStatus::Contacted, $admin);

    expect($lead->fresh()?->status)->toBe(LeadStatus::Contacted);

    $log = Activity::query()->where('log_name', 'leads')->latest('id')->firstOrFail();

    expect($log->causer_id)->toBe($admin->id)
        ->and($log->getExtraProperty('from'))->toBe('qualified')
        ->and($log->getExtraProperty('to'))->toBe('contacted');
});

it('needs a reason to mark a lead lost and clears it when reopened', function (): void {
    $lead = Lead::factory()->create();

    expect(fn () => $this->change->handle($lead, LeadStatus::Lost, reason: '  '))
        ->toThrow(InvalidStatusTransition::class);

    $this->change->handle($lead, LeadStatus::Lost, reason: 'Too far to drive');
    expect($lead->fresh()?->lost_reason)->toBe('Too far to drive');

    $this->change->handle($lead, LeadStatus::Contacted);
    expect($lead->fresh())
        ->status->toBe(LeadStatus::Contacted)
        ->lost_reason->toBeNull();
});

it('keeps booked final and refuses a move to the same status', function (): void {
    $lead = Lead::factory()->create();

    $this->change->handle($lead, LeadStatus::Booked);

    expect(fn () => $this->change->handle($lead, LeadStatus::Contacted))->toThrow(InvalidStatusTransition::class)
        ->and(fn () => $this->change->handle($lead, LeadStatus::Lost, reason: 'x'))->toThrow(InvalidStatusTransition::class)
        ->and(fn () => $this->change->handle(Lead::factory()->create(), LeadStatus::New))->toThrow(InvalidStatusTransition::class);

    expect($lead->fresh()?->booked_at)->not->toBeNull();
});

it('lets a lost lead become booked', function (): void {
    $lead = Lead::factory()->status(LeadStatus::Lost)->create();

    $this->change->handle($lead, LeadStatus::Booked);

    expect($lead->fresh())
        ->status->toBe(LeadStatus::Booked)
        ->lost_reason->toBeNull();
});
