<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Private request approval probe (tests/Concurrency only, prompt 15B)
|--------------------------------------------------------------------------
|
|   php tests/Concurrency/approve-probe.php <requestId> <startAt> <adminId>
|
| Waits until the unix time <startAt> (a shared start barrier), approves
| the request once and prints one JSON line: {"ok":true} or
| {"ok":false,"reason":"..."}.
|
*/

use App\Actions\PrivateRequests\ApprovePrivateRequest;
use App\Exceptions\PrivateRequestRejected;
use App\Models\PrivateSessionRequest;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) config('database.connections.'.config('database.default').'.database');

if (! str_ends_with($database, '_testing')) {
    fwrite(STDERR, 'Refusing to run against '.$database."\n");
    exit(2);
}

[, $requestId, $startAt, $adminId] = $argv + [null, '0', '0', '0'];

Notification::fake();

$request = PrivateSessionRequest::query()->findOrFail((int) $requestId);
$admin = User::query()->findOrFail((int) $adminId);
DB::select('select 1');

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    app(ApprovePrivateRequest::class)->handle($request, $admin);
    $result = ['ok' => true];
} catch (PrivateRequestRejected $e) {
    $result = ['ok' => false, 'reason' => $e->getMessage()];
}

echo json_encode($result), "\n";
