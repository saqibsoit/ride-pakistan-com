<?php

declare(strict_types=1);

use App\Filament\Pages\BulkSchedule;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

require_once __DIR__.'/BulkScheduleHelpers.php';

beforeEach(fn () => Notification::fake());

it('is for admins only', function (): void {
    $this->actingAs(User::factory()->staff()->withMfa()->create())->get('/test-admin/bulk-schedule')->assertForbidden();
    $this->actingAs(User::factory()->trainer()->withMfa()->create())->get('/test-admin/bulk-schedule')->assertForbidden();

    $this->actingAs(User::factory()->admin()->withMfa()->create())->get('/test-admin/bulk-schedule')
        ->assertOk()
        ->assertSee('Bulk schedule')
        ->assertSee('Preview');
});

it('previews without saving, then applies and shows a summary', function (): void {
    $slot = nearSlot(5, 8);
    $booking = bsCreditBooking($slot);
    $date = $slot->localStartsAt()->toDateString();

    $page = Livewire::actingAs(User::factory()->admin()->withMfa()->create())->test(BulkSchedule::class)
        ->fillForm([
            'operation' => 'update',
            'from' => $date,
            'until' => $date,
            'days' => [$slot->localStartsAt()->dayOfWeek],
            'start_time' => '07:00',
            'end_time' => '09:00',
        ])
        ->call('preview')
        ->assertSee('Preview (nothing is saved yet)')
        ->assertSee('07:00–09:00')
        ->assertSee('1 booked');

    expect($slot->fresh()?->localStartsAt()->format('H:i'))->toBe('08:00');

    $page->callAction('apply')
        ->assertSee('Slots changed:')
        ->assertSee('Customers emailed:')
        ->assertSee('Everything in the preview was applied.');

    expect($slot->fresh()?->localStartsAt()->format('H:i'))->toBe('07:00')
        ->and($booking->fresh()?->calendar_sequence)->toBe(1);
});

it('explains a form it cannot preview', function (): void {
    $slot = nearSlot(5, 8);
    $date = $slot->localStartsAt()->toDateString();

    Livewire::actingAs(User::factory()->admin()->withMfa()->create())->test(BulkSchedule::class)
        ->fillForm(['operation' => 'update', 'from' => $date, 'until' => $date, 'days' => [1]])
        ->call('preview')
        ->assertNotified('Can’t preview')
        ->assertDontSee('Preview (nothing is saved yet)');
});

it('links from the slots list', function (): void {
    $this->actingAs(User::factory()->admin()->withMfa()->create())->get('/test-admin/slots')->assertSee('Bulk schedule');
});
