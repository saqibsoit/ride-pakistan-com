<?php

declare(strict_types=1);

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Filament\Pages\LeadBoard;
use App\Filament\Resources\Leads\LeadActions;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Filament\Widgets\LeadConversionTable;
use App\Filament\Widgets\LeadsBySourceChart;
use App\Models\Lead;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

function leadsUser(string $role = 'staff'): User
{
    $user = ($role === 'admin' ? User::factory()->admin() : User::factory()->staff())->withMfa()->create();
    test()->actingAs($user);

    return $user;
}

it('lets staff and admins open the leads list and board; customers get nothing', function (string $role): void {
    leadsUser($role);
    $lead = Lead::factory()->create(['name' => 'Fatima Board']);

    $this->get('/test-admin/leads')->assertOk()->assertSee('Fatima Board');
    $this->get('/test-admin/lead-board')->assertOk()->assertSee('Fatima Board')->assertSee('Move to');
    $this->get('/test-admin/leads/'.$lead->id)->assertOk();
})->with(['staff', 'admin']);

it('keeps customers out', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get('/test-admin/leads')->assertForbidden();
});

it('adds an Instagram DM lead in one step with its note', function (): void {
    $staff = leadsUser();

    Livewire::test(ListLeads::class)
        ->callAction('addLead', data: [
            'name' => 'Maryam',
            'instagram_handle' => '@Maryam.Rides',
            'interest' => 'package',
            'source' => LeadSource::InstagramDm->value,
            'note' => 'Wants 8 classes for her and her sister',
        ])
        ->assertNotified('Lead added');

    $lead = Lead::query()->sole();

    expect($lead->instagram_handle)->toBe('maryam.rides')
        ->and($lead->source)->toBe(LeadSource::InstagramDm)
        ->and($lead->status)->toBe(LeadStatus::New)
        ->and($lead->created_by)->toBe($staff->id)
        ->and($lead->notes()->sole()->body)->toBe('Wants 8 classes for her and her sister')
        ->and($lead->notes()->sole()->author_id)->toBe($staff->id);
});

it('needs a way to reply and a valid handle', function (array $data): void {
    leadsUser();

    Livewire::test(ListLeads::class)
        ->callAction('addLead', data: ['name' => 'Nobody', 'source' => 'instagram_dm', ...$data])
        ->assertNotified('Lead not added');

    expect(Lead::query()->count())->toBe(0);
})->with([
    'nothing' => [[]],
    'bad handle' => [['instagram_handle' => 'not a handle!']],
]);

it('warns about a possible duplicate handle', function (): void {
    leadsUser();
    Lead::factory()->instagramDm()->create(['name' => 'Existing', 'instagram_handle' => 'ali.rides']);

    expect(LeadActions::duplicateHint('@Ali.Rides', null))->toContain('Existing');
});

it('moves cards on the board and asks why a lead was lost', function (): void {
    leadsUser();
    $lead = Lead::factory()->create();

    Livewire::test(LeadBoard::class)
        ->call('moveLead', $lead->id, LeadStatus::Contacted->value)
        ->assertNotified();

    expect($lead->fresh()?->status)->toBe(LeadStatus::Contacted);

    Livewire::test(LeadBoard::class)
        ->call('moveLead', $lead->id, LeadStatus::Lost->value)
        ->assertActionMounted('markLost')
        ->setActionData(['reason' => ''])
        ->callMountedAction()
        ->assertHasActionErrors(['reason' => 'required']);

    Livewire::test(LeadBoard::class)
        ->callAction(TestAction::make('markLost')->arguments(['lead' => $lead->id]), data: ['reason' => 'Booked elsewhere']);

    expect($lead->fresh())
        ->status->toBe(LeadStatus::Lost)
        ->lost_reason->toBe('Booked elsewhere');
});

it('filters the board by source', function (): void {
    leadsUser();
    Lead::factory()->instagramDm()->create(['name' => 'From Instagram']);
    Lead::factory()->create(['name' => 'From Form']);

    Livewire::test(LeadBoard::class)
        ->set('source', LeadSource::InstagramDm->value)
        ->assertSee('From Instagram')
        ->assertDontSee('From Form');
});

it('lets staff assign, follow up and note, but not convert or delete', function (): void {
    $staff = leadsUser();
    $lead = Lead::factory()->create();

    Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->callAction('assign', data: ['assigned_to' => $staff->id])
        ->callAction('followUp', data: ['next_follow_up_at' => now()->addDays(2)->toDateString()])
        ->callAction('addNote', data: ['body' => 'Called, no answer'])
        ->assertActionHidden('convert')
        ->assertActionHidden('delete');

    expect($lead->fresh())
        ->assigned_to->toBe($staff->id)
        ->next_follow_up_at->not->toBeNull()
        ->and($lead->notes()->count())->toBe(1);
});

it('lets admins convert a lead from its page', function (): void {
    leadsUser('admin');
    $lead = Lead::factory()->create(['email' => 'new.rider@example.com']);

    Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->callAction('convert', data: ['email' => 'new.rider@example.com'])
        ->assertNotified('Linked to new.rider@example.com');

    expect($lead->fresh()?->user?->email)->toBe('new.rider@example.com');
});

it('changes status from the list with a reason for lost', function (): void {
    leadsUser();
    $lead = Lead::factory()->create();

    Livewire::test(ListLeads::class)
        ->callAction(TestAction::make('changeStatus')->table($lead), data: ['status' => 'lost', 'reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    Livewire::test(ListLeads::class)
        ->callAction(TestAction::make('changeStatus')->table($lead), data: ['status' => 'qualified']);

    expect($lead->fresh()?->status)->toBe(LeadStatus::Qualified);
});

it('shows the lead reports to admins only', function (): void {
    leadsUser();
    expect(LeadsBySourceChart::canView())->toBeFalse()
        ->and(LeadConversionTable::canView())->toBeFalse();

    leadsUser('admin');
    Lead::factory()->instagramDm()->create();

    expect(LeadsBySourceChart::canView())->toBeTrue();
    Livewire::test(LeadConversionTable::class)->assertSee('Instagram DM');
});
