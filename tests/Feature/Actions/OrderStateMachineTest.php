<?php

declare(strict_types=1);

use App\Actions\Orders\OrderStateMachine;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Order;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * Every [from, to] pair, split by whether PLAN §3.3 allows it.
 *
 * @return array<string, array{OrderStatus, OrderStatus}>
 */
function orderTransitions(bool $allowed): array
{
    $valid = [
        'pending_payment' => ['paid', 'awaiting_transfer', 'expired', 'cancelled'],
        'awaiting_transfer' => ['awaiting_verification', 'expired', 'cancelled'],
        'awaiting_verification' => ['paid', 'rejected', 'expired', 'cancelled'],
        'paid' => ['partially_refunded', 'refunded'],
        'partially_refunded' => ['partially_refunded', 'refunded'],
    ];

    $pairs = [];

    foreach (OrderStatus::cases() as $from) {
        foreach (OrderStatus::cases() as $to) {
            if (in_array($to->value, $valid[$from->value] ?? [], true) === $allowed) {
                $pairs[$from->value.' → '.$to->value] = [$from, $to];
            }
        }
    }

    return $pairs;
}

function orderAudit(Order $order): ?Activity
{
    return Activity::query()
        ->where('log_name', 'orders')
        ->where('subject_type', $order->getMorphClass())
        ->where('subject_id', $order->id)
        ->latest('id')
        ->first();
}

it('matches the transition table in PLAN §3.3', function (): void {
    foreach (orderTransitions(true) as [$from, $to]) {
        expect($from->canTransitionTo($to))->toBeTrue();
    }

    foreach (orderTransitions(false) as [$from, $to]) {
        expect($from->canTransitionTo($to))->toBeFalse();
    }
});

it('marks terminal and unpaid statuses', function (): void {
    expect(array_values(array_filter(OrderStatus::cases(), fn (OrderStatus $s): bool => $s->isTerminal())))
        ->toBe([OrderStatus::Rejected, OrderStatus::Expired, OrderStatus::Cancelled, OrderStatus::Refunded])
        ->and(array_values(array_filter(OrderStatus::cases(), fn (OrderStatus $s): bool => $s->isUnpaid())))
        ->toBe([OrderStatus::PendingPayment, OrderStatus::AwaitingTransfer, OrderStatus::AwaitingVerification]);
});

it('applies every allowed transition and logs actor, from, to and reason', function (OrderStatus $from, OrderStatus $to): void {
    $order = Order::factory()->status($from)->create();
    $admin = User::factory()->admin()->create();

    app(OrderStateMachine::class)->transition($order, $to, $admin, 'Checked by phone', ['source' => 'test']);

    $log = orderAudit($order);

    expect($order->status)->toBe($to)
        ->and($order->refresh()->status)->toBe($to)
        ->and($log)->not->toBeNull()
        ->and($log?->causer_id)->toBe($admin->id)
        ->and($log?->event)->toBe('status_changed')
        ->and($log?->properties->all())->toMatchArray([
            'from' => $from->value,
            'to' => $to->value,
            'reason' => 'Checked by phone',
            'source' => 'test',
        ]);
})->with(orderTransitions(true));

it('rejects every other transition and changes nothing', function (OrderStatus $from, OrderStatus $to): void {
    $order = Order::factory()->status($from)->create();

    expect(fn () => app(OrderStateMachine::class)->transition($order, $to, null, 'Any reason'))
        ->toThrow(InvalidStatusTransition::class);

    expect($order->refresh()->status)->toBe($from)
        ->and(orderAudit($order))->toBeNull();
})->with(orderTransitions(false));

it('stamps paid_at when an order is paid', function (): void {
    $this->freezeTime();
    $order = Order::factory()->create();

    app(OrderStateMachine::class)->transition($order, OrderStatus::Paid);

    expect($order->refresh()->paid_at?->toDateTimeString())->toBe(now()->toDateTimeString());
});

it('requires a reason to reject, cancel or refund', function (OrderStatus $from, OrderStatus $to, ?string $reason): void {
    $order = Order::factory()->status($from)->create();

    expect(fn () => app(OrderStateMachine::class)->transition($order, $to, null, $reason))
        ->toThrow(InvalidStatusTransition::class, 'needs a reason');

    expect($order->refresh()->status)->toBe($from);
})->with([
    'reject without reason' => [OrderStatus::AwaitingVerification, OrderStatus::Rejected, null],
    'cancel with blank reason' => [OrderStatus::PendingPayment, OrderStatus::Cancelled, '   '],
    'refund without reason' => [OrderStatus::Paid, OrderStatus::Refunded, null],
    'partial refund without reason' => [OrderStatus::Paid, OrderStatus::PartiallyRefunded, ''],
]);

it('does not need a reason for system transitions', function (): void {
    $order = Order::factory()->status(OrderStatus::AwaitingTransfer)->create();

    app(OrderStateMachine::class)->transition($order, OrderStatus::Expired);

    expect($order->refresh()->status)->toBe(OrderStatus::Expired)
        ->and(orderAudit($order)?->causer_id)->toBeNull();
});

it('checks the stored status, not a stale copy in memory', function (): void {
    $order = Order::factory()->status(OrderStatus::AwaitingVerification)->create();
    $stale = Order::query()->findOrFail($order->id);

    // Someone else approves first.
    app(OrderStateMachine::class)->transition($order, OrderStatus::Paid);

    expect(fn () => app(OrderStateMachine::class)->transition($stale, OrderStatus::Rejected, null, 'Wrong amount'))
        ->toThrow(InvalidStatusTransition::class);

    expect($order->refresh()->status)->toBe(OrderStatus::Paid);
});
