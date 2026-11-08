<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Spatie\ResponseCache\Facades\ResponseCache;

/**
 * Clears the full-page cache once, after the surrounding transaction
 * commits, however many seat changes happened in it. Public pages
 * (/schedule, home, product pages) show seats left, so they must not
 * outlive a hold, booking, cancellation or slot change.
 */
final class PageCache
{
    private bool $pending = false;

    public function invalidate(): void
    {
        if ($this->pending) {
            return;
        }

        $this->pending = true;

        // Runs straight away outside a transaction; dropped on rollback.
        DB::afterCommit(function (): void {
            $this->pending = false;
            ResponseCache::clear();
        });
    }
}
