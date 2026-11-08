<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\Order;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Overbooking under real concurrency (PLAN §5)
|--------------------------------------------------------------------------
|
| These tests commit their data (DatabaseTruncation, see tests/Pest.php)
| and start separate PHP processes, each with its own MariaDB connection,
| so the slot row lock is exercised for real.
|
*/

/** Environment for the probe processes: same database as this test run. */
function probeEnv(): array
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
 * Start one probe per [orderId, riderId] pair, all released at the same instant.
 *
 * @param  list<array{0: int, 1: int}>  $contenders
 * @return list<Process>
 */
function startProbes(int $slotId, array $contenders, bool $private = false, float $barrierSeconds = 4.0): array
{
    $startAt = sprintf('%.6F', microtime(true) + $barrierSeconds);
    $processes = [];

    foreach ($contenders as [$orderId, $riderId]) {
        $process = new Process(
            [PHP_BINARY, base_path('tests/Concurrency/hold-probe.php'), (string) $orderId, (string) $slotId, (string) $riderId, $private ? '1' : '0', $startAt],
            base_path(),
            probeEnv(),
            null,
            60,
        );
        $process->start();
        $processes[] = $process;
    }

    return $processes;
}

/**
 * @param  list<Process>  $processes
 * @return list<array{ok: bool, reason?: string, waited_ms: int}>
 */
function probeResults(array $processes): array
{
    return array_map(function (Process $process): array {
        $process->wait();

        expect($process->getExitCode())->toBe(0, 'probe failed: '.$process->getErrorOutput().$process->getOutput());

        $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));

        return json_decode((string) end($lines), true, flags: JSON_THROW_ON_ERROR);
    }, $processes);
}

/**
 * Customers with one rider and one unpaid order each.
 *
 * @return list<array{0: int, 1: int}> [orderId, riderId]
 */
function contenders(int $count): array
{
    $pairs = [];

    for ($i = 0; $i < $count; $i++) {
        $user = User::factory()->create();
        $rider = Rider::factory()->for($user)->create();
        $order = Order::factory()->for($user)->create(['expires_at' => now()->addMinutes(30)]);
        $pairs[] = [$order->id, $rider->id];
    }

    return $pairs;
}

function raceSlot(int $capacity): Slot
{
    return Slot::factory()->at(CarbonImmutable::now((string) config('app.display_timezone'))->addDays(3)->setTime(8, 0))->create(['capacity' => $capacity]);
}

function seatsTaken(Slot $slot): int
{
    return Booking::query()->where('slot_id', $slot->id)->count()
        + (int) BookingHold::query()->where('slot_id', $slot->id)->where('expires_at', '>', now())->sum('seats');
}

it('gives the last seat to exactly one of two parallel holds', function (): void {
    $slot = raceSlot(6);
    Booking::factory()->count(5)->create(['slot_id' => $slot->id]);

    $results = probeResults(startProbes($slot->id, contenders(2)));

    fwrite(STDOUT, "\n  Two processes, last seat: ".json_encode($results)."\n");

    expect(collect($results)->where('ok', true))->toHaveCount(1)
        ->and(collect($results)->where('reason', 'slot_full'))->toHaveCount(1)
        ->and(seatsTaken($slot))->toBe(6);
});

it('gives the last seat to exactly one of ten parallel holds', function (): void {
    $slot = raceSlot(6);
    Booking::factory()->count(5)->create(['slot_id' => $slot->id]);

    $results = probeResults(startProbes($slot->id, contenders(10)));

    fwrite(STDOUT, "\n  Ten processes, last seat: ".json_encode($results)."\n");

    expect(collect($results)->where('ok', true))->toHaveCount(1)
        ->and(collect($results)->where('reason', 'slot_full'))->toHaveCount(9)
        ->and(seatsTaken($slot))->toBe(6)
        ->and(BookingHold::query()->where('slot_id', $slot->id)->count())->toBe(1);
});

it('never lets a private and a group booking share an empty slot', function (): void {
    $slot = raceSlot(6);
    [$privateContender, $groupContender] = contenders(2);

    $processes = [
        ...startProbes($slot->id, [$privateContender], private: true),
        ...startProbes($slot->id, [$groupContender]),
    ];
    $results = probeResults($processes);

    fwrite(STDOUT, "\n  Private vs group on an empty slot: ".json_encode($results)."\n");

    expect(collect($results)->where('ok', true))->toHaveCount(1);

    // Whoever won, the slot holds one booking only: all 6 seats (private) or 1 (group).
    $hold = BookingHold::query()->where('slot_id', $slot->id)->sole();
    expect($hold->seats)->toBe($hold->is_private ? 6 : 1);
});

it('makes a hold wait for the slot row lock and then see the seat taken meanwhile', function (): void {
    $slot = raceSlot(1);
    [[$orderId, $riderId]] = contenders(1);
    $occupant = Rider::factory()->create();

    // A second connection takes the slot lock, as another booking would.
    $config = (array) config('database.connections.'.config('database.default'));
    config(['database.connections.race' => $config]);
    $other = DB::connection('race');
    $other->beginTransaction();
    $other->select('select id from slots where id = ? for update', [$slot->id]);

    $probe = startProbes($slot->id, [[$orderId, $riderId]], barrierSeconds: 2.0)[0];

    // The probe is blocked on the lock, not finished.
    usleep(4_500_000);
    expect($probe->isRunning())->toBeTrue();

    // Take the last seat inside the locking transaction, then release.
    $other->table('bookings')->insert([
        'slot_id' => $slot->id,
        'rider_id' => $occupant->id,
        'user_id' => $occupant->user_id,
        'status' => 'confirmed',
        'is_private' => false,
        'ticket_code' => 'RACE'.bin2hex(random_bytes(4)),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $other->commit();

    [$result] = probeResults([$probe]);

    fwrite(STDOUT, "\n  Blocked hold after the lock was released: ".json_encode($result)."\n");

    expect($result['ok'])->toBeFalse()
        ->and($result['reason'])->toBe('slot_full')
        ->and($result['waited_ms'])->toBeGreaterThan(1000)
        ->and(BookingHold::query()->count())->toBe(0)
        ->and(seatsTaken($slot))->toBe(1);
});
