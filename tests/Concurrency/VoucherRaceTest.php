<?php

declare(strict_types=1);

use App\Actions\Booking\CreateOrder;
use App\Enums\DiscountKind;
use App\Models\OrderDiscount;
use App\Models\Product;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use App\Models\Voucher;
use Carbon\CarbonImmutable;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Gift voucher balance under real concurrency (milestone 10e Part B)
|--------------------------------------------------------------------------
|
| Several customers apply the same voucher at the same instant from
| separate processes. The voucher row lock means its balance is spent at
| most once: the lines on all orders add up to the balance, never more.
|
*/

it('spends a gift voucher balance only once when orders race for it', function (): void {
    $voucher = Voucher::factory()->create(['initial_minor' => 500_000, 'balance_minor' => 500_000]);
    $product = Product::factory()->create(['price_minor' => 400_000]);
    $orders = [];

    for ($i = 0; $i < 4; $i++) {
        $user = User::factory()->create();
        $slot = Slot::factory()->at(CarbonImmutable::now('Asia/Karachi')->addDays(3 + $i)->setTime(8, 0))->create(['capacity' => 6]);
        $orders[] = app(CreateOrder::class)->handle($user, $product, $slot->id, [Rider::factory()->for($user)->create()->id]);
    }

    $connection = (string) config('database.default');
    $db = (array) config("database.connections.{$connection}");
    $env = [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => $connection, 'DB_HOST' => (string) ($db['host'] ?? '127.0.0.1'),
        'DB_PORT' => (string) ($db['port'] ?? '3306'), 'DB_DATABASE' => (string) $db['database'],
        'DB_USERNAME' => (string) ($db['username'] ?? ''), 'DB_PASSWORD' => (string) ($db['password'] ?? ''), 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'RESPONSE_CACHE_DRIVER' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    ];
    $startAt = sprintf('%.6F', microtime(true) + 4.0);
    $processes = [];

    foreach ($orders as $order) {
        $process = new Process([PHP_BINARY, base_path('tests/Concurrency/discount-probe.php'), (string) $order->id, $voucher->code, $startAt], base_path(), $env, null, 60);
        $process->start();
        $processes[] = $process;
    }

    $results = array_map(function (Process $process): array {
        $process->wait();
        expect($process->getExitCode())->toBe(0, 'probe failed: '.$process->getErrorOutput().$process->getOutput());
        $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));

        return json_decode((string) end($lines), true, flags: JSON_THROW_ON_ERROR);
    }, $processes);

    fwrite(STDOUT, "\n  Four orders, one PKR 5,000 voucher: ".json_encode($results)."\n");

    $used = (int) OrderDiscount::query()->where('kind', DiscountKind::Voucher)->sum('amount_minor');

    expect($used)->toBe(500_000)
        ->and($voucher->refresh()->balance_minor)->toBe(0)
        ->and(collect($results)->where('ok', true)->count())->toBe(2); // 4,000 + the remaining 1,000
});
