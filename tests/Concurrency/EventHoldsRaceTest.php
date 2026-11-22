<?php

declare(strict_types=1);

use App\Actions\Events\CreateEventOrder;
use App\Enums\EventPaymentMode;
use App\Enums\RideEventStatus;
use App\Models\BookingHold;
use App\Models\RideEvent;
use App\Models\RideEventTier;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Symfony\Component\Process\Process;

/*
| Event overbooking under real concurrency (decided 2026-11-22): event
| orders go through HoldSeats and the slot row lock, so an occurrence's
| capacity and each tier's own seats are never exceeded, even when many
| processes book the last place at the same instant. Helpers are prefixed
| `er` (test files share one namespace).
*/

/** @return array<string, string> */
function erEnv(): array
{
    $connection = (string) config('database.default');
    $db = (array) config("database.connections.{$connection}");

    return [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => $connection,
        'DB_HOST' => (string) ($db['host'] ?? '127.0.0.1'),
        'DB_PORT' => (string) ($db['port'] ?? '3306'),
        'DB_DATABASE' => (string) $db['database'],
        'DB_USERNAME' => (string) ($db['username'] ?? ''),
        'DB_PASSWORD' => (string) ($db['password'] ?? ''),
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'RESPONSE_CACHE_DRIVER' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array',
    ];
}

/**
 * @return list<array{ok: bool, reason?: string}>
 */
function erRace(RideEvent $event, Slot $slot, RideEventTier $tier, int $contenders): array
{
    $startAt = sprintf('%.6F', microtime(true) + 4.0);
    $processes = [];

    for ($i = 0; $i < $contenders; $i++) {
        $user = User::factory()->create();
        $process = new Process(
            [PHP_BINARY, base_path('tests/Concurrency/event-probe.php'), (string) $user->id, (string) $event->id, (string) $slot->id, (string) $tier->id, 'Racer '.$i, $startAt],
            base_path(),
            erEnv(),
            null,
            60,
        );
        $process->start();
        $processes[] = $process;
    }

    return array_map(function (Process $process): array {
        $process->wait();
        expect($process->getExitCode())->toBe(0, 'probe failed: '.$process->getErrorOutput().$process->getOutput());
        $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));

        return json_decode((string) end($lines), true, flags: JSON_THROW_ON_ERROR);
    }, $processes);
}

function erEvent(): RideEvent
{
    return RideEvent::factory()->create(['status' => RideEventStatus::Published, 'payment_mode' => EventPaymentMode::Deposit, 'deposit_percent' => 50, 'non_refundable_percent' => 50]);
}

function erDate(RideEvent $event, int $capacity): Slot
{
    return Slot::factory()->forEvent($event)->at(CarbonImmutable::now((string) config('app.display_timezone'))->addDays(20)->setTime(10, 0), 7)->create(['capacity' => $capacity]);
}

it('gives the last place on an event date to exactly one of ten parallel bookings', function (): void {
    $event = erEvent();
    $slot = erDate($event, 3);
    $tier = $event->tiers()->firstOrFail();

    // Two places already taken by an earlier booking.
    app(CreateEventOrder::class)->handle(User::factory()->create(), $event, $slot->id, [
        ['tier_id' => $tier->id, 'name' => 'Early 1'],
        ['tier_id' => $tier->id, 'name' => 'Early 2'],
    ]);

    $results = erRace($event, $slot, $tier, 10);

    fwrite(STDOUT, "\n  Ten processes, last event place: ".json_encode($results)."\n");

    expect(collect($results)->where('ok', true))->toHaveCount(1)
        ->and(collect($results)->where('ok', false)->pluck('reason')->unique()->values()->all())->toBe(['slot_full'])
        ->and((int) BookingHold::query()->where('slot_id', $slot->id)->sum('seats'))->toBe(3);
});

it('gives the last place of a tier to exactly one of ten parallel bookings, with room left in the date', function (): void {
    $event = erEvent();
    $slot = erDate($event, 20);
    $child = RideEventTier::factory()->for($event, 'event')->create(['name' => 'Child', 'price_minor' => 500_000, 'seats_per_occurrence' => 1]);

    $results = erRace($event, $slot, $child, 10);

    fwrite(STDOUT, "\n  Ten processes, last child place: ".json_encode($results)."\n");

    expect(collect($results)->where('ok', true))->toHaveCount(1)
        ->and(collect($results)->where('ok', false)->pluck('reason')->unique()->values()->all())->toBe(['tier_full'])
        ->and((int) BookingHold::query()->where('slot_id', $slot->id)->sum('seats'))->toBe(1);
});
