<?php

declare(strict_types=1);

use App\Enums\PrivateRequestStatus;
use App\Models\Order;
use App\Models\PrivateSessionRequest;
use App\Models\Product;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Two approvals of overlapping private requests at once (prompt 15B)
|--------------------------------------------------------------------------
|
| Separate PHP processes with their own MariaDB connections. The schedule
| lock (GET_LOCK) runs approvals one after another, so the second sees the
| first one's slot and is refused: never two private slots at once.
|
*/

/**
 * @param  list<string>  $requestIds
 * @return list<array{ok: bool, reason?: string}>
 */
function runApprovalProbes(array $requestIds, int $adminId): array
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

    foreach ($requestIds as $id) {
        $process = new Process([PHP_BINARY, base_path('tests/Concurrency/approve-probe.php'), $id, $startAt, (string) $adminId], base_path(), $env, null, 60);
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

it('approves only one of two overlapping requests approved at the same moment', function (): void {
    $product = Product::factory()->private()->create();
    $admin = User::factory()->admin()->create();
    $day = CarbonImmutable::now((string) config('app.display_timezone'))->addDays(4);
    $ids = [];

    foreach (['10:00', '11:00'] as $time) {
        $user = User::factory()->create();
        $rider = Rider::factory()->for($user)->create();
        $start = $day->setTime(...array_map('intval', explode(':', $time)))->utc();

        $ids[] = (string) PrivateSessionRequest::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'rider_ids' => [$rider->id],
            'starts_at' => $start,
            'ends_at' => $start->addHours(2),
        ])->id;
    }

    $results = runApprovalProbes($ids, $admin->id);

    fwrite(STDOUT, "\n  Two overlapping approvals in parallel: ".json_encode($results)."\n");

    expect(collect($results)->where('ok', true)->count())->toBe(1)
        ->and(Slot::query()->count())->toBe(1)
        ->and(Order::query()->count())->toBe(1)
        ->and(PrivateSessionRequest::query()->where('status', PrivateRequestStatus::Approved)->count())->toBe(1)
        ->and(PrivateSessionRequest::query()->where('status', PrivateRequestStatus::Requested)->count())->toBe(1);
});
