<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Event order probe (tests/Concurrency only, decided 2026-11-22)
|--------------------------------------------------------------------------
|
| A separate PHP process that books one person on an event date through
| CreateEventOrder (so through HoldSeats and the slot row lock), for the
| event race test.
|
|   php tests/Concurrency/event-probe.php <userId> <eventId> <slotId> <tierId> <name> <startAt>
|
| Waits until <startAt>, tries once and prints one JSON line.
|
*/

use App\Actions\Events\CreateEventOrder;
use App\Exceptions\BookingRejected;
use App\Models\RideEvent;
use App\Models\User;
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

[, $userId, $eventId, $slotId, $tierId, $name, $startAt] = $argv + [null, null, null, null, null, 'Guest', '0'];

$user = User::query()->findOrFail((int) $userId);
$event = RideEvent::query()->findOrFail((int) $eventId);

DB::select('select 1');

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    app(CreateEventOrder::class)->handle($user, $event, (int) $slotId, [['tier_id' => (int) $tierId, 'name' => (string) $name]]);
    $result = ['ok' => true];
} catch (BookingRejected $e) {
    $result = ['ok' => false, 'reason' => $e->reason];
}

echo json_encode($result), "\n";
