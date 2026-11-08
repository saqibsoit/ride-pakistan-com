<?php

declare(strict_types=1);

namespace App\Support\Cache;

use App\Support\PageCache;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Spatie\ResponseCache\Hasher\DefaultHasher;

/**
 * The full-page cache key of a public page (milestone 11):
 *
 * - Campaign and click tags (utm_*, fbclid, gclid, _gl) and the DM lead
 *   reference are left out, so every ad click doesn't create its own copy.
 *   No cached page renders them on the server: lead forms read them in the
 *   browser (lead-attribution.js) and the canonical URL never has a query
 *   string. (ref/lead links are redirected to the clean URL anyway.)
 * - The other query parameters are sorted, so ?a=1&b=2 and ?b=2&a=1 share
 *   one copy.
 * - Pages that show seats left carry the seats version (PageCache): a
 *   booking or hold makes those pages render fresh, while every other
 *   page keeps its copy.
 */
final class PublicPageHasher extends DefaultHasher
{
    public const IGNORED_PARAMETERS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
        'fbclid', 'gclid', 'gbraid', 'wbraid', '_gl', 'ref', 'lead',
    ];

    protected function getNormalizedRequestUri(Request $request): string
    {
        $query = Arr::except($request->query(), self::IGNORED_PARAMETERS);
        ksort($query);

        $uri = $request->getBaseUrl().$request->getPathInfo().($query === [] ? '' : '?'.http_build_query($query));

        return PageCache::showsSeats($request) ? $uri.'#seats-'.PageCache::seatsVersion() : $uri;
    }
}
