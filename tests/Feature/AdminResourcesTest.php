<?php

declare(strict_types=1);

use App\Actions\Orders\OrderStateMachine;
use App\Enums\OrderStatus;
use App\Enums\SlotStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\RelationManagers\RidersRelationManager;
use App\Filament\Resources\Slots\Pages\ListSlots;
use App\Filament\Resources\Slots\Pages\SlotCalendar;
use App\Filament\Resources\Slots\Pages\ViewSlot;
use App\Models\Booking;
use App\Models\Order;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00', 'Asia/Karachi'));
    $this->seed();
});

function actingAsAdmin(): User
{
    $admin = User::factory()->admin()->withMfa()->create();
    test()->actingAs($admin);

    return $admin;
}

function actingAsStaff(): User
{
    $staff = User::factory()->staff()->withMfa()->create();
    test()->actingAs($staff);

    return $staff;
}

describe('as an admin', function (): void {
    beforeEach(function (): void {
        $this->admin = actingAsAdmin();
    });

    it('opens every admin page', function (string $path): void {
        $this->get('/test-admin/'.$path)->assertOk();
    })->with([
        'dashboard' => '',
        'slots' => 'slots',
        'slot calendar' => 'slots/calendar',
        'customers' => 'customers',
        'orders' => 'orders',
        'activity log' => 'activity-log',
        'settings' => 'manage-settings',
        'profile' => 'profile',
    ]);

    it('opens record pages', function (): void {
        $slot = Slot::query()->firstOrFail();
        $order = Order::factory()->create();
        $customer = User::factory()->create();
        app(OrderStateMachine::class)->transition($order, OrderStatus::Paid, $this->admin);

        $this->get('/test-admin/slots/'.$slot->id)->assertOk()->assertSee($slot->label());
        $this->get('/test-admin/orders/'.$order->getRouteKey())->assertOk()->assertSee($order->reference)->assertSee('Pending payment → Paid');
        $this->get('/test-admin/customers/'.$customer->id.'/edit')->assertOk();
        $this->get('/test-admin/activity-log/'.Activity::query()->latest('id')->firstOrFail()->id)->assertOk();
    });

    it('has no routes to create, edit or delete slots and orders', function (): void {
        $names = collect(Route::getRoutes()->getRoutes())->map(fn ($route): ?string => $route->getName())->filter()->values();

        expect($names)->toContain('filament.admin.resources.slots.index', 'filament.admin.resources.orders.view')
            ->not->toContain('filament.admin.resources.slots.create')
            ->not->toContain('filament.admin.resources.slots.edit')
            ->not->toContain('filament.admin.resources.orders.create')
            ->not->toContain('filament.admin.resources.orders.edit')
            ->not->toContain('filament.admin.resources.customers.create');
    });

    it('lists upcoming slots with booked, held and free seats', function (): void {
        $slot = Slot::query()->orderBy('starts_at')->firstOrFail();
        Booking::factory()->count(2)->create(['slot_id' => $slot->id]);

        Livewire::test(ListSlots::class)
            ->assertCanSeeTableRecords([$slot])
            ->assertTableColumnStateSet('seats_booked', 2, $slot)
            ->assertTableColumnStateSet('seats_free', 4, $slot);
    });

    it('shows the week with seat counts', function (): void {
        Livewire::test(SlotCalendar::class)
            ->assertSee('Sat 7 Nov')
            ->assertSee('08:00–10:00')
            ->assertSee('0 booked · 0 held · 6 free of 6')
            ->call('nextWeek')
            ->assertSee('Sat 14 Nov')
            ->call('thisWeek')
            ->assertSee('Sat 7 Nov');
    });

    it('cancels a slot only with a reason, through the audit log', function (): void {
        $slot = Slot::query()->firstOrFail();

        Livewire::test(ViewSlot::class, ['record' => $slot->id])
            ->callAction('cancel', data: ['reason' => ''])
            ->assertHasActionErrors(['reason' => 'required']);

        expect($slot->refresh()->status)->toBe(SlotStatus::Open);

        Livewire::test(ViewSlot::class, ['record' => $slot->id])
            ->callAction('cancel', data: ['reason' => 'Horses at the vet'])
            ->assertHasNoActionErrors();

        expect($slot->refresh()->status)->toBe(SlotStatus::Cancelled)
            ->and($slot->cancel_reason)->toBe('Horses at the vet')
            ->and(Activity::query()->where('log_name', 'slots')->where('subject_id', $slot->id)->where('causer_id', $this->admin->id)->exists())->toBeTrue();
    });

    it('closes and reopens a slot from the list', function (): void {
        $slot = Slot::query()->firstOrFail();

        Livewire::test(ListSlots::class)
            ->callAction(TestAction::make('close')->table($slot));

        expect($slot->refresh()->status)->toBe(SlotStatus::Closed);

        Livewire::test(ListSlots::class)
            ->callAction(TestAction::make('reopen')->table($slot));

        expect($slot->refresh()->status)->toBe(SlotStatus::Open);
    });

    it('generates slots on demand', function (): void {
        Slot::query()->orderByDesc('starts_at')->firstOrFail()->delete();

        Livewire::test(ListSlots::class)->callAction('generate');

        expect(Slot::query()->count())->toBe(32);
    });

    it('changes a customer role through the audit log, never their own', function (): void {
        $customer = User::factory()->create();

        Livewire::test(EditCustomer::class, ['record' => $customer->id])
            ->fillForm(['role' => UserRole::Staff->value, 'phone' => '+923001234567'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($customer->refresh()->role)->toBe(UserRole::Staff)
            ->and($customer->phone)->toBe('+923001234567')
            ->and(Activity::query()->where('log_name', 'users')->where('event', 'role_changed')->where('subject_id', $customer->id)->exists())->toBeTrue();

        Livewire::test(EditCustomer::class, ['record' => $this->admin->id])
            ->assertFormFieldIsDisabled('role')
            ->fillForm(['role' => UserRole::Customer->value])
            ->call('save');

        expect($this->admin->refresh()->role)->toBe(UserRole::Admin);
    });

    it('validates phone numbers as E.164', function (): void {
        Livewire::test(EditCustomer::class, ['record' => User::factory()->create()->id])
            ->fillForm(['phone' => '0300 1234567'])
            ->call('save')
            ->assertHasFormErrors(['phone' => 'regex']);
    });

    it('manages riders on a customer and redacts sensitive fields in the audit log', function (): void {
        $customer = User::factory()->create();

        Livewire::test(RidersRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => EditCustomer::class])
            ->callAction(TestAction::make('create')->table(), data: [
                'name' => 'Ayesha',
                'age_bracket' => '12_15',
                'experience_level' => 'none',
                'emergency_contact_name' => 'Sara',
                'emergency_contact_phone' => '+923009876543',
                'notes' => 'Asthma inhaler in bag',
            ])
            ->assertHasNoActionErrors();

        $rider = Rider::query()->where('user_id', $customer->id)->firstOrFail();
        $log = Activity::query()->where('subject_type', $rider->getMorphClass())->where('subject_id', $rider->id)->firstOrFail();

        expect($rider->notes)->toBe('Asthma inhaler in bag')
            ->and($log->properties->get('attributes'))->toMatchArray([
                'name' => 'Ayesha',
                'notes' => Rider::REDACTED,
                'emergency_contact_phone' => Rider::REDACTED,
            ]);
    });
});

describe('as staff', function (): void {
    beforeEach(function (): void {
        actingAsStaff();
    });

    it('reads slots and the week view', function (): void {
        $slot = Slot::query()->firstOrFail();

        $this->get('/test-admin/slots')->assertOk();
        $this->get('/test-admin/slots/calendar')->assertOk();
        $this->get('/test-admin/slots/'.$slot->id)->assertOk();
    });

    it('cannot change slots or generate them', function (): void {
        $slot = Slot::query()->firstOrFail();

        Livewire::test(ViewSlot::class, ['record' => $slot->id])
            ->assertActionHidden('cancel')
            ->assertActionHidden('close');

        Livewire::test(ListSlots::class)->assertActionHidden('generate');
    });

    it('is kept out of admin-only areas', function (string $path): void {
        $this->get('/test-admin/'.$path)->assertForbidden();
    })->with(['customers', 'orders', 'activity-log', 'manage-settings', 'products', 'pages', 'schedule-rules']);
});
