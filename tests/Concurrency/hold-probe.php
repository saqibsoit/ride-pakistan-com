<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Hold probe (tests/Concurrency only)
|--------------------------------------------------------------------------
|
| A separate PHP process that tries to hold seats, so the race test runs
| against MariaDB with real parallel connections, not inside one process.
|
|   php tests/Concurrency/hold-probe.php <orderId> <slotId> <riderId> <private 0|1> <startAt>
|
| Waits until the unix time <startAt> (a shared start barrier), calls
| HoldSeats once and prints one JSON line: {"ok":true} or
| {"ok":false,"reason":"slot_full"}.
|
*/

use App\Actions\Booking\HoldSeats;
use App\Exceptions\BookingRejected;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) config('database.connections.'.config('database.default').'.database');

if (! str_ends_with($database, '_testing')) {
    fwrite(STDERR, 'Refusing to run against '.$database."\n");
    exit(2);
}

[, $orderId, $slotId, $riderId, $private, $startAt] = $argv + [null, null, null, null, '0', '0'];

$order = Order::query()->findOrFail((int) $orderId);

// Warm the connection before the barrier so every process starts its
// transaction at the same moment.
DB::select('select 1');

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

$started = microtime(true);

try {
    app(HoldSeats::class)->handle($order, (int) $slotId, [(int) $riderId], $private === '1', CarbonImmutable::now()->addMinutes(30));
    $result = ['ok' => true];
} catch (BookingRejected $e) {
    $result = ['ok' => false, 'reason' => $e->reason];
}

$result['waited_ms'] = (int) round((microtime(true) - $started) * 1000);

echo json_encode($result), "\n";
