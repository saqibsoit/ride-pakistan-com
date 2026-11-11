<?php

declare(strict_types=1);

use App\Actions\Booking\BookWithCredit;
use App\Actions\Booking\CreateOrder;
use App\Actions\Booking\FulfilOrder;
use App\Actions\Slots\BulkSchedule\ApplyBulkSchedule;
use App\Actions\Slots\BulkSchedule\BulkScheduleCriteria;
use App\Actions\Slots\BulkSchedule\BulkScheduleResult;
use App\Actions\Slots\BulkSchedule\PreviewBulkSchedule;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;

/*
| Helpers for the prompt 15A tests (bulk schedule editor and the slot change
| links). Prefixed `bs` so they never clash with other test files' helpers.
*/

/**
 * Bulk criteria covering one slot's Pakistan date and weekday.
 *
 * @param  array<string, mixed>  $overrides
 */
function bsCriteria(Slot $slot, string $operation, array $overrides = []): BulkScheduleCriteria
{
    $date = $slot->localStartsAt()->toDateString();

    return BulkScheduleCriteria::fromForm([
        'operation' => $operation,
        'from' => $date,
        'until' => $date,
        'days' => [$slot->localStartsAt()->dayOfWeek],
        ...$overrides,
    ]);
}

/** Preview, then apply every actionable row, as the admin page does. */
function bsApply(BulkScheduleCriteria $criteria, ?User $admin = null): BulkScheduleResult
{
    $preview = app(PreviewBulkSchedule::class)->handle($criteria);

    return app(ApplyBulkSchedule::class)->handle($criteria, $preview->actionable(), $admin ?? User::factory()->admin()->create());
}

/** A credit booking in this slot for a new customer. */
function bsCreditBooking(Slot $slot): Booking
{
    $user = User::factory()->create();
    $rider = Rider::factory()->for($user)->create();
    CreditPack::factory()->create(['order_id' => Order::factory()->for($user)->status(OrderStatus::Paid), 'expires_at' => now()->addWeeks(10)]);

    return app(BookWithCredit::class)->handle($user, $slot->id, [$rider->id])->sole();
}

/** A directly paid single-session booking in this slot. */
function bsPaidBooking(Slot $slot, ?User $user = null): Booking
{
    $user ??= User::factory()->create();
    $product = Product::factory()->create(['type' => ProductType::Single]);
    $order = app(CreateOrder::class)->handle($user, $product, $slot->id, [Rider::factory()->for($user)->create()->id]);
    app(FulfilOrder::class)->handle($order);

    return Booking::query()->where('order_id', $order->id)->sole();
}

/** A no-charge booking (admin manual booking without an order or credit). */
function bsFreeBooking(Slot $slot): Booking
{
    return Booking::factory()->create(['slot_id' => $slot->id]);
}
