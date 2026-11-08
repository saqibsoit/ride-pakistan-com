<?php

declare(strict_types=1);

use App\Actions\Leads\SendFollowUpDigest;
use App\Actions\Leads\SuggestFollowUp;
use App\Enums\LeadStatus;
use App\Enums\SettingKey;
use App\Filament\Pages\LeadBoard;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\FollowUpDigest;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

function digestNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-11-11 09:00', 'Asia/Karachi');
}

beforeEach(function (): void {
    $this->travelTo(digestNow());
});

it('suggests a follow-up two days after contact, from the setting', function (): void {
    expect(app(SuggestFollowUp::class)->date()?->toDateString())->toBe('2026-11-13');

    Setting::query()->updateOrCreate(['key' => SettingKey::LeadFollowUpDays->value], ['value' => 0]);
    app(Settings::class)->flush();

    expect(app(SuggestFollowUp::class)->date())->toBeNull();
});

it('prefills the suggestion when a lead is moved to contacted', function (): void {
    $staff = User::factory()->staff()->withMfa()->create();
    $lead = Lead::factory()->create();

    Livewire::actingAs($staff)->test(ViewLead::class, ['record' => $lead->getKey()])
        ->mountAction('changeStatus')
        ->assertSchemaStateSet(['next_follow_up_at' => '2026-11-13'])
        ->setActionData(['status' => 'contacted', 'next_follow_up_at' => '2026-11-16'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $lead->refresh();
    expect($lead->status)->toBe(LeadStatus::Contacted)
        ->and($lead->next_follow_up_at?->toDateString())->toBe('2026-11-16');
});

it('sets the suggested date when a card is dragged to contacted, unless one is already set', function (): void {
    $staff = User::factory()->staff()->withMfa()->create();
    $fresh = Lead::factory()->create();
    $planned = Lead::factory()->create(['next_follow_up_at' => '2026-11-20']);

    Livewire::actingAs($staff)->test(LeadBoard::class)
        ->call('moveLead', $fresh->id, 'contacted')
        ->call('moveLead', $planned->id, 'contacted');

    expect($fresh->refresh()->next_follow_up_at?->toDateString())->toBe('2026-11-13')
        ->and($planned->refresh()->next_follow_up_at?->toDateString())->toBe('2026-11-20');
});

it('emails admins and staff the leads due today and overdue, their own first', function (): void {
    Notification::fake();
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->staff()->create();
    $trainer = User::factory()->trainer()->create();
    $customer = User::factory()->create();

    Lead::factory()->create(['name' => 'Due Today', 'next_follow_up_at' => '2026-11-11']);
    Lead::factory()->create(['name' => 'Overdue Mine', 'next_follow_up_at' => '2026-11-09', 'assigned_to' => $staff->id]);
    Lead::factory()->create(['name' => 'Tomorrow', 'next_follow_up_at' => '2026-11-12']);
    Lead::factory()->status(LeadStatus::Lost)->create(['name' => 'Lost One', 'next_follow_up_at' => '2026-11-10', 'lost_reason' => 'x']);

    expect(app(SendFollowUpDigest::class)->handle())->toBe(2);

    Notification::assertSentTo($staff, FollowUpDigest::class, function (FollowUpDigest $digest): bool {
        $names = array_column($digest->leads, 'name');

        return $names === ['Overdue Mine', 'Due Today'] && $digest->leads[0]['mine'] && $digest->leads[0]['overdue'];
    });
    Notification::assertSentTo($admin, FollowUpDigest::class, fn (FollowUpDigest $digest): bool => array_column($digest->leads, 'name') === ['Overdue Mine', 'Due Today']);
    Notification::assertNotSentTo([$trainer, $customer], FollowUpDigest::class);

    // Once a day.
    expect(app(SendFollowUpDigest::class)->handle())->toBe(0);
});

it('renders the digest email with links and escaped names', function (): void {
    $staff = User::factory()->staff()->create();
    $lead = Lead::factory()->create(['name' => 'Zara *bold* Khan', 'instagram_handle' => 'zara.k', 'next_follow_up_at' => '2026-11-11']);

    $lines = [[
        'name' => $lead->name, 'contact' => $lead->contactLine(), 'status' => 'New', 'due' => 'Wed 11 Nov',
        'overdue' => false, 'assignee' => 'Nobody', 'mine' => false, 'url' => 'https://example.test/lead/1',
    ]];

    $html = (string) (new FollowUpDigest('2026-11-11', $lines))->toMail($staff)->render();

    expect($html)->toContain('Follow-ups for Wednesday 11 Nov')
        ->toContain('@zara.k')
        ->toContain('https://example.test/lead/1')
        ->not->toContain('<strong>bold</strong>');
    expect((new FollowUpDigest('2026-11-11', $lines))->toMail($staff)->subject)->toBe('1 lead to follow up today');
});

it('sends nothing when no follow-up is due', function (): void {
    Notification::fake();
    User::factory()->admin()->create();
    Lead::factory()->create(['next_follow_up_at' => '2026-11-12']);

    expect(app(SendFollowUpDigest::class)->handle())->toBe(0);
    Notification::assertNothingSent();
});

it('runs every day at 09:00 Pakistan time', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'leads:digest'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 9 * * *')
        ->and($event->timezone)->toBe('Asia/Karachi');
});

it('lists due leads on the dashboard for staff', function (): void {
    Lead::factory()->create(['name' => 'Dashboard Lead', 'next_follow_up_at' => '2026-11-10']);

    $this->actingAs(User::factory()->staff()->withMfa()->create())->get('/test-admin')
        ->assertOk()
        ->assertSee('Dashboard Lead');
});
