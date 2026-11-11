<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\Order;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Bulk capacity change against a booking for the last seat (prompt 15A)
|--------------------------------------------------------------------------
|
| Separate PHP processes with their own MariaDB connections. The bulk
| editor re-checks the seats under the slot row lock, so the capacity
| never ends up below the seats taken.
|
*/

/**
 * @param  list<list<string>>  $commands  [script, ...args] without startAt (inserted second)
 * @return list<array{ok: bool, reason?: string}>
 */
function runBulkRaceProbes(array $commands): array
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
        [$script, $first] = $args;
        $process = new Process([PHP_BINARY, base_path('tests/Concurrency/'.$script), ...($script === 'waitlist-probe.php' ? ['hold', $first, $startAt] : [$first, $startAt]), ...array_slice($args, 2)], base_path(), $env, null, 60);
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

it('never lets a bulk capacity cut and a booking for the last seat both win', function (): void {
    $slot = Slot::factory()->at(CarbonImmutable::now((string) config('app.display_timezone'))->addDays(3)->setTime(8, 0))->create(['capacity' => 2]);
    Booking::factory()->create(['slot_id' => $slot->id]);

    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $rider = Rider::factory()->for($user)->create();
    $order = Order::factory()->for($user)->create(['expires_at' => now()->addMinutes(30)]);

    $results = runBulkRaceProbes([
        ['bulk-probe.php', (string) $slot->id, '1', (string) $admin->id],
        ['waitlist-probe.php', (string) $slot->id, (string) $order->id, (string) $rider->id],
    ]);

    fwrite(STDOUT, "\n  Bulk capacity 2 → 1 against a hold for the last seat: ".json_encode($results)."\n");

    $slot->refresh();
    $taken = Booking::query()->where('slot_id', $slot->id)->whereIn('status', BookingStatus::seatOccupyingValues())->count()
        + (int) BookingHold::query()->where('slot_id', $slot->id)->where('expires_at', '>', now())->sum('seats');

    expect(collect($results)->where('ok', true)->count())->toBe(1)
        ->and($taken)->toBeLessThanOrEqual($slot->capacity);
});
