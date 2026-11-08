<?php

declare(strict_types=1);

use App\Models\BankTransfer;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPack;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Rider;
use App\Models\ScheduleRule;
use App\Models\Slot;
use App\Models\StripeEvent;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

function roleUser(string $role): User
{
    return match ($role) {
        'admin' => User::factory()->admin()->create(),
        'staff' => User::factory()->staff()->create(),
        default => User::factory()->create(),
    };
}

it('lets only team members see slots and bookings', function (string $role, bool $allowed): void {
    $user = roleUser($role);
    $slot = Slot::factory()->create();
    $booking = Booking::factory()->create(['slot_id' => $slot->id]);

    expect($user->can('viewAny', Slot::class))->toBe($allowed)
        ->and($user->can('view', $slot))->toBe($allowed)
        ->and($user->can('viewAny', Booking::class))->toBe($allowed)
        ->and($user->can('view', $booking))->toBe($allowed);
})->with([
    ['customer', false],
    ['staff', true],
    ['admin', true],
]);

it('lets only admins change slot status, add manual slots or run the generator', function (string $role, bool $allowed): void {
    $user = roleUser($role);
    $slot = Slot::factory()->create();

    expect($user->can('changeStatus', $slot))->toBe($allowed)
        ->and($user->can('create', Slot::class))->toBe($allowed)
        ->and($user->can('generate', Slot::class))->toBe($allowed);
})->with([
    ['customer', false],
    ['staff', false],
    ['admin', true],
]);

it('never allows editing or deleting slots, or creating, editing or deleting bookings in the panel', function (): void {
    $admin = roleUser('admin');
    $slot = Slot::factory()->manual()->create();
    $booking = Booking::factory()->create(['slot_id' => $slot->id]);

    expect($admin->can('update', $slot))->toBeFalse()
        ->and($admin->can('delete', $slot))->toBeFalse()
        ->and($admin->can('create', Booking::class))->toBeFalse()
        ->and($admin->can('update', $booking))->toBeFalse()
        ->and($admin->can('delete', $booking))->toBeFalse();
});

it('keeps admin-only records away from staff and customers', function (string $model): void {
    $record = $model::factory()->create();

    foreach (['customer', 'staff'] as $role) {
        $user = roleUser($role);

        expect($user->can('viewAny', $model))->toBeFalse()
            ->and($user->can('update', $record))->toBeFalse();
    }

    expect(roleUser('admin')->can('viewAny', $model))->toBeTrue();
})->with([
    Product::class,
    ScheduleRule::class,
    Lead::class,
    LeadNote::class,
    Order::class,
    OrderItem::class,
    BookingHold::class,
    CreditPack::class,
    CreditLedgerEntry::class,
    BankTransfer::class,
    Payment::class,
    StripeEvent::class,
    Rider::class,
    User::class,
]);

it('makes payment records read-only even for admins', function (string $model): void {
    $admin = roleUser('admin');
    $record = $model::factory()->create();

    expect($admin->can('view', $record))->toBeTrue()
        ->and($admin->can('create', $model))->toBeFalse()
        ->and($admin->can('update', $record))->toBeFalse()
        ->and($admin->can('delete', $record))->toBeFalse();
})->with([
    Order::class,
    OrderItem::class,
    BookingHold::class,
    CreditPack::class,
    CreditLedgerEntry::class,
    BankTransfer::class,
    Payment::class,
    StripeEvent::class,
]);

it('lets customers see their own orders, bookings and credit packs only', function (): void {
    $owner = roleUser('customer');
    $other = roleUser('customer');

    $order = Order::factory()->create(['user_id' => $owner->id]);
    $rider = Rider::factory()->create(['user_id' => $owner->id]);
    $booking = Booking::factory()->create(['rider_id' => $rider->id]);
    $pack = CreditPack::factory()->create(['order_id' => Order::factory()->create(['user_id' => $owner->id])->id]);

    expect($owner->can('view', $order))->toBeTrue()
        ->and($owner->can('view', $booking))->toBeTrue()
        ->and($owner->can('view', $pack))->toBeTrue()
        ->and($owner->can('update', $rider))->toBeTrue()
        ->and($other->can('view', $order))->toBeFalse()
        ->and($other->can('view', $booking))->toBeFalse()
        ->and($other->can('view', $pack))->toBeFalse()
        ->and($other->can('update', $rider))->toBeFalse();
});

it('keeps riders with bookings and products with orders', function (): void {
    $admin = roleUser('admin');
    $booking = Booking::factory()->create();
    $order = Order::factory()->create();

    expect($admin->can('delete', $booking->rider))->toBeFalse()
        ->and($admin->can('delete', Rider::factory()->create()))->toBeTrue()
        ->and($admin->can('delete', $order->product))->toBeFalse()
        ->and($admin->can('delete', Product::factory()->create()))->toBeTrue();
});

it('lets admins change other users roles but not their own', function (): void {
    $admin = roleUser('admin');

    expect($admin->can('changeRole', roleUser('customer')))->toBeTrue()
        ->and($admin->can('changeRole', $admin))->toBeFalse()
        ->and(roleUser('staff')->can('changeRole', roleUser('customer')))->toBeFalse()
        ->and($admin->can('create', User::class))->toBeFalse()
        ->and($admin->can('delete', roleUser('customer')))->toBeFalse();
});

it('shows the audit log to admins only and never lets anyone edit it', function (): void {
    activity('test')->log('Something happened');
    $entry = Activity::query()->latest('id')->firstOrFail();

    expect(roleUser('admin')->can('viewAny', Activity::class))->toBeTrue()
        ->and(roleUser('admin')->can('update', $entry))->toBeFalse()
        ->and(roleUser('admin')->can('delete', $entry))->toBeFalse()
        ->and(roleUser('staff')->can('viewAny', Activity::class))->toBeFalse();
});
