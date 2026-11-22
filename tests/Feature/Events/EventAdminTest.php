<?php

declare(strict_types=1);

use App\Actions\Events\CreateEventOrder;
use App\Enums\EventPaymentMode;
use App\Enums\LessonClosure;
use App\Enums\RideEventStatus;
use App\Enums\SlotStatus;
use App\Filament\Resources\RideEvents\Pages\CreateRideEvent;
use App\Filament\Resources\RideEvents\Pages\EditRideEvent;
use App\Filament\Resources\RideEvents\RelationManagers\OccurrencesRelationManager;
use App\Filament\Resources\RideEvents\RelationManagers\TiersRelationManager;
use App\Models\RideEvent;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

function eventAdmin(): User
{
    $admin = User::factory()->admin()->withMfa()->create();
    test()->actingAs($admin);

    return $admin;
}

it('opens the event screens for admins only', function (): void {
    $event = RideEvent::factory()->create();

    $this->actingAs(User::factory()->staff()->withMfa()->create());
    $this->get('/test-admin/ride-events')->assertForbidden();

    eventAdmin();
    $this->get('/test-admin/ride-events')->assertOk()->assertSee($event->title);
    $this->get('/test-admin/ride-events/create')->assertOk();
    $this->get('/test-admin/ride-events/'.$event->slug.'/edit')->assertOk()->assertSee('Dates')->assertSee('Prices');
    $this->get('/test-admin/ride-event-categories')->assertOk();
});

it('builds an event in the admin: details, content blocks, questions, a price and a date, then publishes it', function (): void {
    $admin = eventAdmin();

    Livewire::test(CreateRideEvent::class)
        ->fillForm([
            'title' => 'Sunset Trail Ride',
            'slug' => 'sunset-trail-ride',
            'summary' => 'Two hours on the trails at golden hour.',
            'payment_mode' => EventPaymentMode::Deposit->value,
            'deposit_percent' => 30,
            'non_refundable_percent' => 30,
            'cancellation_cutoff_days' => 5,
            'min_per_booking' => 1,
            'booking_closes_hours' => 12,
            'acknowledgement_text' => 'I can ride at a walk and trot.',
            'blocks' => [
                ['type' => 'programme', 'data' => ['items' => [['time' => '16:00', 'activity' => 'Meet the horses']]]],
            ],
            'questions' => [
                ['key' => 'q1', 'label' => 'Riding experience', 'type' => 'select', 'options' => ['None', 'Some'], 'scope' => 'attendee', 'required' => true],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $event = RideEvent::query()->where('slug', 'sunset-trail-ride')->firstOrFail();

    expect($event->status)->toBe(RideEventStatus::Draft)
        ->and($event->deposit_percent)->toBe(30)
        ->and($event->blocksOf('programme')[0]['items'][0]['activity'])->toBe('Meet the horses')
        ->and($event->questions[0]['label'])->toBe('Riding experience')
        ->and($event->tiers()->count())->toBe(1);

    Livewire::test(TiersRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditRideEvent::class])
        ->callAction(TestAction::make('edit')->table($event->tiers()->firstOrFail()), ['name' => 'Per person', 'price_minor' => 8000, 'sort' => 0])
        ->assertHasNoFormErrors();

    expect($event->tiers()->value('price_minor'))->toBe(800_000);

    $start = CarbonImmutable::now('Asia/Karachi')->addDays(15)->setTime(16, 0);

    Livewire::test(OccurrencesRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditRideEvent::class])
        ->callAction(TestAction::make('addDate')->table(), [
            'starts_at' => $start->format('Y-m-d H:i:s'),
            'ends_at' => $start->addHours(2)->format('Y-m-d H:i:s'),
            'capacity' => 8,
            'lesson_closure' => LessonClosure::Overlap->value,
        ])
        ->assertHasNoFormErrors();

    $date = Slot::query()->events()->where('ride_event_id', $event->id)->firstOrFail();

    expect($date->starts_at->equalTo($start->utc()))->toBeTrue()
        ->and($date->capacity)->toBe(8)
        ->and($date->lesson_closure)->toBe(LessonClosure::Overlap);

    Livewire::test(EditRideEvent::class, ['record' => $event->slug])
        ->callAction('status_published')
        ->assertNotified();

    expect($event->refresh()->status)->toBe(RideEventStatus::Published)
        ->and(Activity::query()->where('log_name', 'content')->where('event', 'status_changed')->where('subject_id', $event->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('log_name', 'slots')->where('event', 'event_date_created')->exists())->toBeTrue();

    expect($admin->id)->toBeInt();
});

it('cancels a date from the admin with a reason', function (): void {
    eventAdmin();
    $event = RideEvent::factory()->published()->create();
    $date = eventDate($event);

    Livewire::test(OccurrencesRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditRideEvent::class])
        ->callAction(TestAction::make('date_cancelled')->table($date), ['reason' => 'Not enough riders'])
        ->assertHasNoFormErrors();

    expect($date->refresh()->status)->toBe(SlotStatus::Cancelled)
        ->and($date->cancel_reason)->toBe('Not enough riders');
});

it('archives an event with bookings instead of deleting it', function (): void {
    eventAdmin();
    $event = RideEvent::factory()->published()->create();
    $date = eventDate($event);
    app(CreateEventOrder::class)->handle(User::factory()->create(), $event, $date->id, eventPeople($event, 1));

    Livewire::test(EditRideEvent::class, ['record' => $event->slug])
        ->assertActionHidden('deleteEvent')
        ->callAction('status_archived');

    expect($event->refresh()->status)->toBe(RideEventStatus::Archived)
        ->and($event->archived_at)->not->toBeNull();
});
