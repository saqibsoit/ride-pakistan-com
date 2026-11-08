<?php

declare(strict_types=1);

namespace App\Support\Cache;

use Illuminate\Http\Request;
use Spatie\ResponseCache\CacheProfiles\CacheAllSuccessfulGetRequests;

/**
 * Which public page requests use the full-page cache (milestone 11).
 * As the package default (successful, non-ajax GETs), and also not:
 *
 * - for signed-in visitors: their pages render fresh, so the cache holds
 *   one copy per page for everyone instead of one per account;
 * - while the session carries flash data or validation errors: those
 *   belong to this visitor only (e.g. tracking events after a purchase),
 *   so the page must neither be served from the cache (it wouldn't show
 *   them) nor stored (everyone else would get them).
 */
final class PublicCacheProfile extends CacheAllSuccessfulGetRequests
{
    /**
     * Checked before serving a cached copy and before storing one (the
     * package serves from the cache on enabled() alone).
     */
    public function enabled(Request $request): bool
    {
        return parent::enabled($request)
            && $request->user() === null
            && ! self::hasVisitorState($request);
    }

    public function shouldCacheRequest(Request $request): bool
    {
        return parent::shouldCacheRequest($request)
            && $request->user() === null
            && ! self::hasVisitorState($request);
    }

    /** Nothing per account: signed-in visitors never use the cache. */
    public function useCacheNameSuffix(Request $request): string
    {
        return '';
    }

    private static function hasVisitorState(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $session = $request->session();

        return (array) $session->get('_flash.old', []) !== []
            || (array) $session->get('_flash.new', []) !== []
            || $session->has('errors');
    }
}
