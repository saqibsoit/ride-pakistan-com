<?php

declare(strict_types=1);

use App\Actions\Booking\GenerateTicketCode;
use App\Actions\Orders\GenerateOrderReference;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\TransferProofController;
use App\Models\BankTransfer;
use App\Models\Booking;
use App\Models\Order;
use App\Models\Rider;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

/*
| Another customer's order reference or ticket code must never open
| anything: every customer route answers 404 (or 403), and nothing about
| the other order appears.
*/

beforeEach(function (): void {
    $this->seed();
    $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00', 'Asia/Karachi'));

    $this->owner = User::factory()->create(['name' => 'Owner Person']);
    $this->order = Order::factory()->for($this->owner)->status(OrderStatus::PendingPayment)->create();
    $this->booking = Booking::factory()
        ->for(Rider::factory()->for($this->owner)->state(['name' => 'Owner Rider']))
        ->status(BookingStatus::Confirmed)
        ->create(['slot_id' => nearSlot(3, 11)->id]);
    $this->intruder = User::factory()->create();
});

it('hides another customer’s order on every GET route', function (string $route): void {
    $response = $this->actingAs($this->intruder)->get(str_replace('{ref}', $this->order->reference, $route));

    expect($response->status())->toBeIn([403, 404]);
    $response->assertDontSee('Owner Person');
})->with([
    '/checkout/{ref}',
    '/checkout/{ref}/status',
    '/checkout/{ref}/transfer',
    '/account/orders/{ref}',
]);

it('refuses to act on another customer’s order', function (string $route): void {
    // Valid input, so the request gets past validation to the ownership check.
    $response = $this->actingAs($this->intruder)->post(str_replace('{ref}', $this->order->reference, $route), [
        'proof' => UploadedFile::fake()->image('proof.jpg', 800, 600),
    ]);

    expect($response->status())->toBeIn([403, 404])
        ->and($this->order->refresh()->status)->toBe(OrderStatus::PendingPayment);
})->with([
    '/checkout/{ref}/card',
    '/checkout/{ref}/transfer',
    '/checkout/{ref}/transfer/proof',
]);

it('hides another customer’s ticket and booking changes', function (): void {
    $code = $this->booking->ticket_code;
    $this->actingAs($this->intruder);

    $this->get('/account/tickets/'.$code)->assertNotFound();
    $this->get('/account/bookings/'.$code.'/reschedule')->assertNotFound();
    $this->get('/account/bookings/'.$code.'/cancel')->assertNotFound();
    $this->post('/account/bookings/'.$code.'/cancel')->assertNotFound();
    $this->post('/account/bookings/'.$code.'/reschedule', ['slot' => nearSlot(4, 11)->id])->assertNotFound();

    expect($this->booking->refresh()->status)->toBe(BookingStatus::Confirmed);
});

it('does not open gate check-in for customers, even the ticket holder', function (): void {
    $this->actingAs($this->owner)->get('/checkin/'.$this->booking->ticket_code)->assertForbidden();
    $this->actingAs($this->owner)->post('/checkin/'.$this->booking->ticket_code)->assertForbidden();
});

it('does not open a transfer proof for customers, even the payer', function (): void {
    $transfer = BankTransfer::factory()->create();

    $url = TransferProofController::signedUrl($transfer);

    $this->actingAs($transfer->order->user)->get($url)->assertForbidden();
});

it('uses random references and ticket codes, never sequential ids', function (): void {
    // Ownership is checked on every route; the codes add a second layer.
    expect(GenerateOrderReference::isValid($this->order->reference))->toBeTrue()
        ->and(GenerateTicketCode::LENGTH)->toBeGreaterThanOrEqual(12);
});
