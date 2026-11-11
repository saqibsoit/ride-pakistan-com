<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Bulk schedule probe (tests/Concurrency only, prompt 15A)
|--------------------------------------------------------------------------
|
|   php tests/Concurrency/bulk-probe.php <slotId> <startAt> <capacity> <adminId>
|
| Previews a capacity change for one slot, waits until the unix time
| <startAt> (a shared start barrier), then applies it and prints one JSON
| line: {"ok":true} or {"ok":false,"reason":"..."}.
|
*/

use App\Actions\Slots\BulkSchedule\ApplyBulkSchedule;
use App\Actions\Slots\BulkSchedule\BulkScheduleCriteria;
use App\Actions\Slots\BulkSchedule\PreviewBulkSchedule;
use App\Models\Slot;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Notification;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) config('database.connections.'.config('database.default').'.database');

if (! str_ends_with($database, '_testing')) {
    fwrite(STDERR, 'Refusing to run against '.$database."\n");
    exit(2);
}

[, $slotId, $startAt, $capacity, $adminId] = $argv + [null, '0', '0', '1', '0'];

Notification::fake();

$slot = Slot::query()->findOrFail((int) $slotId);
$date = $slot->localStartsAt()->toDateString();
$criteria = BulkScheduleCriteria::fromForm([
    'operation' => 'update',
    'from' => $date,
    'until' => $date,
    'days' => [$slot->localStartsAt()->dayOfWeek],
    'capacity' => (int) $capacity,
]);
$preview = app(PreviewBulkSchedule::class)->handle($criteria);
$admin = User::query()->findOrFail((int) $adminId);

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

$result = app(ApplyBulkSchedule::class)->handle($criteria, $preview->actionable(), $admin);

echo json_encode($result->updated === 1 ? ['ok' => true] : ['ok' => false, 'reason' => $result->failures[0] ?? 'nothing applied']), "\n";
