<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Waitlist probe (tests/Concurrency only, milestone 10d)
|--------------------------------------------------------------------------
|
| A separate PHP process racing for freed seats against real parallel
| MariaDB connections.
|
|   php tests/Concurrency/waitlist-probe.php offer <slotId> <startAt>
|   php tests/Concurrency/waitlist-probe.php hold <slotId> <startAt> <orderId> <riderId>
|
| Waits until the unix time <startAt> (a shared start barrier), then runs
| OfferWaitlistSeats or HoldSeats once and prints one JSON line:
| {"ok":true,"offers":1} or {"ok":false,"reason":"slot_full"}.
|
*/

use App\Actions\Booking\HoldSeats;
use App\Actions\Waitlist\OfferWaitlistSeats;
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

[, $mode, $slotId, $startAt, $orderId, $riderId] = $argv + [null, 'offer', '0', '0', '0', '0'];

$order = $mode === 'hold' ? Order::query()->findOrFail((int) $orderId) : null;

DB::select('select 1');

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    if ($order !== null) {
        app(HoldSeats::class)->handle($order, (int) $slotId, [(int) $riderId], false, CarbonImmutable::now()->addMinutes(30));
        $result = ['ok' => true];
    } else {
        $result = ['ok' => true, 'offers' => app(OfferWaitlistSeats::class)->handle((int) $slotId)];
    }
} catch (BookingRejected $e) {
    $result = ['ok' => false, 'reason' => $e->reason];
}

echo json_encode($result), "\n";
