<?php

declare(strict_types=1);

use App\Actions\Leads\CaptureLead;
use App\Enums\LeadStatus;

it('creates a new lead and trims and truncates attribution', function (): void {
    $lead = app(CaptureLead::class)->handle(
        ['name' => '  Sana  ', 'email' => ' sana@example.com ', 'phone' => '', 'interest' => 'unknown'],
        ['utm_source' => ' ig ', 'utm_term' => str_repeat('x', 400), 'gclid' => '', 'unrelated' => 'dropped'],
    );

    expect($lead->name)->toBe('Sana')
        ->and($lead->email)->toBe('sana@example.com')
        ->and($lead->phone)->toBeNull()
        ->and($lead->interest)->toBeNull()
        ->and($lead->status)->toBe(LeadStatus::New)
        ->and($lead->utm_source)->toBe('ig')
        ->and(mb_strlen((string) $lead->utm_term))->toBe(255)
        ->and($lead->gclid)->toBeNull();
});

it('records the source it was given', function (): void {
    $lead = app(CaptureLead::class)->handle(['name' => 'Omar'], [], 'instagram_dm');

    expect($lead->source)->toBe('instagram_dm');
});
