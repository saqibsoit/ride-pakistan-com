<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Discount probe (tests/Concurrency only, milestone 10e Part B)
|--------------------------------------------------------------------------
|
| A separate PHP process that applies a gift voucher to an order at the
| same instant as other processes, against real parallel MariaDB
| connections.
|
|   php tests/Concurrency/discount-probe.php <orderId> <code> <startAt>
|
| Prints one JSON line: {"ok":true} or {"ok":false,"error":"..."}.
|
*/

use App\Actions\Discounts\ChangeOrderDiscounts;
use App\Exceptions\DiscountRejected;
use App\Models\Order;
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

[, $orderId, $code, $startAt] = $argv + [null, '0', '', '0'];

$order = Order::query()->findOrFail((int) $orderId);

DB::select('select 1');

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    app(ChangeOrderDiscounts::class)->applyCode($order, $order->user, (string) $code);
    $result = ['ok' => true];
} catch (DiscountRejected $e) {
    $result = ['ok' => false, 'error' => $e->getMessage()];
} catch (Throwable $e) {
    $result = ['ok' => false, 'crash' => $e::class.': '.mb_substr($e->getMessage(), 0, 300)];
}

echo json_encode($result), "\n";
