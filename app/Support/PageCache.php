<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seats changed (a hold, booking, cancellation or slot change): pages that
 * show seats left must render fresh. Milestone 11: instead of emptying
 * the whole page cache, this moves on the "seats version" that
 * PublicPageHasher puts in the cache key of seat pages only (home, the
 * schedule, product pages). Every other cached page stays warm. Old seat
 * page copies are never served again and expire with their short
 * lifetime (SEAT_PAGES_LIFETIME). Once per transaction, after it commits.
 *
 * Content changes (products, prices, FAQs, settings, ...) still empty the
 * whole cache (RefreshPublicCaches).
 */
final class PageCache
{
    public const VERSION_KEY = 'pagecache.seats_version';

    /** Lifetime of seat page copies in the full-page cache (seconds). */
    public const SEAT_PAGES_LIFETIME = 3600;

    /** Path patterns of the pages that show seats left. */
    public const SEAT_PAGES = ['/', 'schedule', 'sessions/*'];

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
            Cache::forever(self::VERSION_KEY, Str::random(12));
        });
    }

    public static function seatsVersion(): string
    {
        return (string) Cache::get(self::VERSION_KEY, '0');
    }

    public static function showsSeats(Request $request): bool
    {
        return $request->is(...self::SEAT_PAGES);
    }
}
