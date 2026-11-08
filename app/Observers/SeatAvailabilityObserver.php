<?php

declare(strict_types=1);

namespace App\Observers;

use App\Support\PageCache;
use Illuminate\Database\Eloquent\Model;

/**
 * Slots, bookings and holds change the seats shown on public pages:
 * clear the page cache after the change commits.
 */
final class SeatAvailabilityObserver
{
    public function __construct(private readonly PageCache $cache) {}

    public function saved(Model $model): void
    {
        $this->cache->invalidate();
    }

    public function deleted(Model $model): void
    {
        $this->cache->invalidate();
    }
}
