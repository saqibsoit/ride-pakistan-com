<?php

declare(strict_types=1);

namespace App\Actions\Slots;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-at-a-time guard for writes that check "does this time clash with
 * another session?" and then create or move a slot (prompt 15): private
 * request approvals and the bulk schedule editor. A row lock can't cover
 * a slot that doesn't exist yet, so this is a MariaDB named lock
 * (GET_LOCK), held around the whole transaction and released after it.
 */
final class ScheduleLock
{
    public const NAME = 'ride-pakistan:schedule';

    public const WAIT_SECONDS = 10;

    /**
     * @template T
     *
     * @param  callable(): T  $work  runs while the lock is held (usually a DB::transaction)
     * @return T
     */
    public function run(callable $work): mixed
    {
        if (DB::getDriverName() === 'sqlite') {
            return $work();
        }

        $got = DB::selectOne('SELECT GET_LOCK(?, ?) AS got', [self::NAME, self::WAIT_SECONDS]);

        if ((int) ($got->got ?? 0) !== 1) {
            throw new RuntimeException('The schedule is busy. Please try again.');
        }

        try {
            return $work();
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [self::NAME]);
        }
    }
}
