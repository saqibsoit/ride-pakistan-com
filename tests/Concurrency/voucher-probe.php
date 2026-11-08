<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Voucher and referral probe (tests/Concurrency only, milestone 10e)
|--------------------------------------------------------------------------
|
|   php tests/Concurrency/voucher-probe.php issue <voucherOrderId> <startAt>
|   php tests/Concurrency/voucher-probe.php reward <bookingId> <startAt>
|
| Waits for the shared start barrier, runs IssueVoucher or RewardReferral
| once and prints one JSON line: {"ok":true,"id":...} or {"ok":false,"error":"..."}.
|
*/

use App\Actions\Referrals\RewardReferral;
use App\Actions\Vouchers\IssueVoucher;
use App\Models\Booking;
use App\Models\VoucherOrder;
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

[, $mode, $id, $startAt] = $argv + [null, 'issue', '0', '0'];

DB::select('select 1');

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    $result = match ($mode) {
        'issue' => ['ok' => true, 'id' => app(IssueVoucher::class)->handle(VoucherOrder::query()->findOrFail((int) $id))->id],
        'reward' => ['ok' => true, 'id' => app(RewardReferral::class)->handle(Booking::query()->findOrFail((int) $id))?->id],
        default => ['ok' => false, 'error' => 'unknown mode'],
    };
} catch (Throwable $e) {
    $result = ['ok' => false, 'error' => $e::class.': '.$e->getMessage()];
}

echo json_encode($result), "\n";
