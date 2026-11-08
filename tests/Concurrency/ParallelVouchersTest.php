<?php

declare(strict_types=1);

use App\Actions\Referrals\AttachReferral;
use App\Actions\Referrals\EnsureReferralCode;
use App\Actions\Vouchers\CreateVoucherOrder;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Models\AccountCreditEntry;
use App\Models\Booking;
use App\Models\Order;
use App\Models\Slot;
use App\Models\User;
use App\Models\Voucher;
use Carbon\CarbonImmutable;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Vouchers and referral rewards under real concurrency (milestone 10e)
|--------------------------------------------------------------------------
|
| A webhook redelivery racing an admin approval, or two attendance
| check-ins at once, must still issue one voucher and pay one reward.
| Committed data (DatabaseTruncation) and separate PHP processes.
|
*/

/**
 * @return list<array<string, mixed>>
 */
function runVoucherProbes(string $mode, int $id, int $count = 4): array
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

    for ($i = 0; $i < $count; $i++) {
        $process = new Process([PHP_BINARY, base_path('tests/Concurrency/voucher-probe.php'), $mode, (string) $id, $startAt], base_path(), $env, null, 60);
        $process->start();
        $processes[] = $process;
    }

    $results = [];

    foreach ($processes as $process) {
        $process->wait();
        $line = trim((string) strrchr("\n".trim($process->getOutput()), "\n"));
        $decoded = json_decode($line, true);
        $results[] = is_array($decoded) ? $decoded : ['ok' => false, 'error' => $process->getErrorOutput()];
    }

    return $results;
}

it('issues exactly one voucher when several processes confirm the same payment', function (): void {
    $order = app(CreateVoucherOrder::class)->handle(User::factory()->create(), null, 500_000, 'Sara', 'sara@example.com', null, null);

    $results = runVoucherProbes('issue', $order->id);

    expect(collect($results)->every(fn (array $r): bool => $r['ok'] === true))->toBeTrue()
        ->and(collect($results)->pluck('id')->unique()->count())->toBe(1)
        ->and(Voucher::query()->count())->toBe(1);
});

it('pays one referral reward when several processes see the attendance', function (): void {
    $referrer = User::factory()->create();
    $friend = User::factory()->create();
    app(AttachReferral::class)->handle(app(EnsureReferralCode::class)->handle($referrer), $friend);
    $order = Order::factory()->status(OrderStatus::Paid)->create(['user_id' => $friend->id]);
    $booking = Booking::factory()->create([
        'user_id' => $friend->id,
        'order_id' => $order->id,
        'slot_id' => Slot::factory()->at(CarbonImmutable::now()->subHour())->create()->id,
    ]);
    $booking->forceFill(['status' => BookingStatus::Attended])->save();

    $results = runVoucherProbes('reward', $booking->id);

    expect(collect($results)->every(fn (array $r): bool => $r['ok'] === true))->toBeTrue()
        ->and(AccountCreditEntry::query()->count())->toBe(1);
});
