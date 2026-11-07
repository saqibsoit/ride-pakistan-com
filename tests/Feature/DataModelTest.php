<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Models\BankTransfer;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPack;
use App\Models\LeadNote;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\StripeEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('creates every model from its factory', function (string $model): void {
    $record = $model::factory()->create();

    expect($record->exists)->toBeTrue();
})->with([
    Rider::class,
    Slot::class,
    Order::class,
    OrderItem::class,
    BookingHold::class,
    Booking::class,
    CreditPack::class,
    CreditLedgerEntry::class,
    BankTransfer::class,
    Payment::class,
    StripeEvent::class,
    LeadNote::class,
]);

it('encrypts rider emergency contacts and notes at rest', function (): void {
    $rider = Rider::factory()->create(['emergency_contact_phone' => '+923001112233', 'notes' => 'Nervous around horses']);

    $raw = DB::table('riders')->where('id', $rider->id)->first();

    expect($raw->emergency_contact_phone)->not->toContain('3001112233')
        ->and($raw->notes)->not->toContain('Nervous')
        ->and($rider->refresh()->notes)->toBe('Nervous around horses')
        ->and($rider->toArray())->not->toHaveKeys(['notes', 'emergency_contact_phone']);
});

it('keeps one slot per start time', function (): void {
    $slot = Slot::factory()->create();

    Slot::factory()->create(['starts_at' => $slot->starts_at, 'ends_at' => $slot->ends_at]);
})->throws(QueryException::class);

it('keeps order references unique', function (): void {
    Order::factory()->create(['reference' => 'RP-ABC234']);
    Order::factory()->create(['reference' => 'RP-ABC234']);
})->throws(QueryException::class);

it('never mass-assigns order or slot status', function (): void {
    $order = new Order(['status' => OrderStatus::Paid->value]);
    $slot = new Slot(['status' => 'cancelled']);

    expect($order->status)->toBe(OrderStatus::PendingPayment)
        ->and($slot->status->value)->toBe('open');
});

it('counts booked seats, active holds and free seats', function (): void {
    $slot = Slot::factory()->create(['capacity' => 6]);

    Booking::factory()->count(2)->create(['slot_id' => $slot->id]);
    Booking::factory()->status(BookingStatus::Cancelled)->create(['slot_id' => $slot->id]);
    BookingHold::factory()->create(['slot_id' => $slot->id, 'seats' => 3]);
    BookingHold::factory()->expired()->create(['slot_id' => $slot->id, 'seats' => 5]);

    $counted = Slot::query()->withSeatCounts()->findOrFail($slot->id);

    expect($counted->seats_booked)->toBe(2)
        ->and($counted->seats_held)->toBe(3)
        ->and($counted->seatsFree())->toBe(1)
        ->and($slot->refresh()->seatsFree())->toBe(1)
        ->and($slot->isInUse())->toBeTrue();
});

it('shows slot times in Pakistan time while storing UTC', function (): void {
    $slot = Slot::factory()->at(CarbonImmutable::parse('2026-12-05 08:00', 'Asia/Karachi'))->create();

    expect(DB::table('slots')->where('id', $slot->id)->value('starts_at'))->toBe('2026-12-05 03:00:00')
        ->and($slot->refresh()->localStartsAt()->format('H:i'))->toBe('08:00')
        ->and($slot->label())->toBe('Sat 5 Dec 2026, 08:00–10:00');
});
