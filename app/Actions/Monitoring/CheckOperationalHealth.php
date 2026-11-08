<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Enums\BankTransferStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Enums\VoucherOrderStatus;
use App\Models\BankTransfer;
use App\Models\User;
use App\Models\VoucherOrder;
use App\Notifications\OperationsAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Operational alerts (milestone 12): queued jobs waiting longer than
 * QUEUE_MAX_MINUTES (the cron worker has stopped) and bank transfers
 * (orders and gift vouchers) waiting for review longer than
 * TRANSFER_MAX_HOURS. Every admin is emailed when a problem starts, again
 * every REPEAT_HOURS while it lasts, and once when it clears.
 *
 * Emails are sent straight away, never queued: a stuck queue must not
 * swallow its own alert. State per problem lives in the default cache.
 */
final class CheckOperationalHealth
{
    public const QUEUE_MAX_MINUTES = 5;

    public const TRANSFER_MAX_HOURS = 12;

    public const REPEAT_HOURS = 6;

    public const PROBLEM_QUEUE = 'queue';

    public const PROBLEM_TRANSFERS = 'transfers';

    /**
     * @return array<string, string> problem => what happened (started, repeated, resolved, none)
     */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        return [
            self::PROBLEM_QUEUE => $this->evaluate(self::PROBLEM_QUEUE, $this->queueProblem($now), $now),
            self::PROBLEM_TRANSFERS => $this->evaluate(self::PROBLEM_TRANSFERS, $this->transferProblem($now), $now),
        ];
    }

    /**
     * @return array{count: int, oldest: CarbonImmutable}|null
     */
    public function queueProblem(CarbonImmutable $now): ?array
    {
        $table = (string) config('queue.connections.database.table', 'jobs');
        $cutoff = $now->subMinutes(self::QUEUE_MAX_MINUTES)->getTimestamp();

        // Waiting jobs only: delayed jobs aren't due yet, and reserved ones
        // are being worked on (a crashed worker's job is released again).
        $query = DB::table($table)->whereNull('reserved_at')->where('available_at', '<=', $cutoff);
        $count = (clone $query)->count();

        if ($count === 0) {
            return null;
        }

        $oldest = (int) $query->min('available_at');

        return ['count' => $count, 'oldest' => CarbonImmutable::createFromTimestamp($oldest)];
    }

    /**
     * @return array{count: int, oldest: CarbonImmutable}|null
     */
    public function transferProblem(CarbonImmutable $now): ?array
    {
        $cutoff = $now->subHours(self::TRANSFER_MAX_HOURS);

        $orders = BankTransfer::query()
            ->where('status', BankTransferStatus::Submitted)
            ->where('submitted_at', '<', $cutoff)
            ->whereHas('order', fn ($query) => $query->where('status', OrderStatus::AwaitingVerification));

        $vouchers = VoucherOrder::query()
            ->where('status', VoucherOrderStatus::AwaitingVerification)
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '<', $cutoff);

        $count = (clone $orders)->count() + (clone $vouchers)->count();

        if ($count === 0) {
            return null;
        }

        $oldest = collect([$orders->min('submitted_at'), $vouchers->min('submitted_at')])
            ->filter()
            ->map(fn (mixed $value): CarbonImmutable => CarbonImmutable::parse((string) $value))
            ->sort()
            ->first();

        return ['count' => $count, 'oldest' => $oldest ?? $cutoff];
    }

    /**
     * @param  array{count: int, oldest: CarbonImmutable}|null  $problem
     */
    private function evaluate(string $name, ?array $problem, CarbonImmutable $now): string
    {
        $key = 'ops-alert:'.$name;

        /** @var array{since: int, last_sent: int}|null $state */
        $state = Cache::get($key);

        if ($problem === null) {
            if ($state === null) {
                return 'none';
            }

            Cache::forget($key);
            $this->notifyAdmins(new OperationsAlert($name, OperationsAlert::RESOLVED, 0, null, CarbonImmutable::createFromTimestamp($state['since'])));

            return 'resolved';
        }

        if ($state === null) {
            Cache::forever($key, ['since' => $now->getTimestamp(), 'last_sent' => $now->getTimestamp()]);
            $this->notifyAdmins(new OperationsAlert($name, OperationsAlert::STARTED, $problem['count'], $problem['oldest'], $now));

            return 'started';
        }

        if ($now->getTimestamp() - $state['last_sent'] < self::REPEAT_HOURS * 3600) {
            return 'none';
        }

        Cache::forever($key, ['since' => $state['since'], 'last_sent' => $now->getTimestamp()]);
        $this->notifyAdmins(new OperationsAlert($name, OperationsAlert::REPEATED, $problem['count'], $problem['oldest'], CarbonImmutable::createFromTimestamp($state['since'])));

        return 'repeated';
    }

    private function notifyAdmins(OperationsAlert $alert): void
    {
        Log::warning('Operations alert', ['problem' => $alert->problem, 'state' => $alert->state, 'count' => $alert->count]);

        User::query()
            ->where('role', UserRole::Admin)
            ->whereNull('anonymised_at')
            ->get()
            ->reject(fn (User $admin): bool => (string) $admin->email === '' || str_ends_with((string) $admin->email, '.invalid'))
            ->each(fn (User $admin) => $admin->notifyNow($alert));
    }
}
