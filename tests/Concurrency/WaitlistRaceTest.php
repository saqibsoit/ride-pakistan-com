<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\WaitlistStatus;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\Order;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use App\Models\WaitlistEntry;
use Carbon\CarbonImmutable;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Waitlist offers under real concurrency (milestone 10d)
|--------------------------------------------------------------------------
|
| Committed data (DatabaseTruncation) and separate PHP processes, each
| with its own MariaDB connection: offers take the same slot row lock as
| every booking, so a freed seat is held once, never twice.
|
*/

/**
 * @param  list<list<string>>  $commands  probe arguments after the script name, without startAt
 * @return list<array{ok: bool, reason?: string, offers?: int}>
 */
function runWaitlistProbes(array $commands): array
{
    $connection = (string) config('database.default');
    $db = (array) config("database.connections.{$connection}");
    $env = [
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
    $startAt = sprintf('%.6F', microtime(true) + 4.0);
    $processes = [];

    foreach ($commands as $args) {
        [$mode, $slotId] = $args;
        $process = new Process([PHP_BINARY, base_path('tests/Concurrency/waitlist-probe.php'), $mode, $slotId, $startAt, ...array_slice($args, 2)], base_path(), $env, null, 60);
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

/** A slot 3 days ahead, full, with $waiting one-rider entries; then one seat freed without running the waitlist. */
function waitlistRaceSlot(int $capacity, int $waiting): Slot
{
    $slot = Slot::factory()->at(CarbonImmutable::now((string) config('app.display_timezone'))->addDays(3)->setTime(8, 0))->create(['capacity' => $capacity]);
    Booking::factory()->count($capacity)->create(['slot_id' => $slot->id]);

    for ($i = 0; $i < $waiting; $i++) {
        WaitlistEntry::factory()->create(['slot_id' => $slot->id, 'seats' => 1]);
    }

    Booking::query()->where('slot_id', $slot->id)->limit(1)->update(['status' => BookingStatus::Cancelled]);

    return $slot;
}

function waitlistSeatsTaken(Slot $slot): int
{
    return Booking::query()->where('slot_id', $slot->id)->whereIn('status', BookingStatus::seatOccupyingValues())->count()
        + (int) BookingHold::query()->where('slot_id', $slot->id)->where('expires_at', '>', now())->sum('seats');
}

it('makes exactly one offer for one freed seat when offers run in parallel', function (): void {
    $slot = waitlistRaceSlot(capacity: 3, waiting: 5);

    $results = runWaitlistProbes(array_fill(0, 6, ['offer', (string) $slot->id]));

    fwrite(STDOUT, "\n  Six parallel offer runs, one seat: ".json_encode($results)."\n");

    expect(collect($results)->sum('offers'))->toBe(1)
        ->and(WaitlistEntry::query()->where('status', WaitlistStatus::Offered)->count())->toBe(1)
        ->and(WaitlistEntry::query()->where('status', WaitlistStatus::Offered)->value('id'))->toBe(WaitlistEntry::query()->min('id'))
        ->and(waitlistSeatsTaken($slot))->toBe(3);
});

it('never exceeds capacity when public holds race a waitlist offer for the freed seat', function (): void {
    $slot = waitlistRaceSlot(capacity: 3, waiting: 1);

    $commands = [['offer', (string) $slot->id]];

    for ($i = 0; $i < 4; $i++) {
        $user = User::factory()->create();
        $rider = Rider::factory()->for($user)->create();
        $order = Order::factory()->for($user)->create(['expires_at' => now()->addMinutes(30)]);
        $commands[] = ['hold', (string) $slot->id, (string) $order->id, (string) $rider->id];
    }

    $results = runWaitlistProbes($commands);

    fwrite(STDOUT, "\n  One offer run against four holds: ".json_encode($results)."\n");

    $offers = (int) ($results[0]['offers'] ?? 0);
    $holds = collect(array_slice($results, 1))->where('ok', true)->count();

    expect($offers + $holds)->toBe(1)
        ->and(waitlistSeatsTaken($slot))->toBe(3);
});
