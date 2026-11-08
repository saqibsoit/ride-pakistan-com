<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\LeadRef;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tracked DM links (milestone 10c): `?ref=ig-dm&lead={signed id}`. A valid
 * reference is kept in the session (no cookie, no consent needed), so an
 * order placed in this visit is credited to the lead (CreateOrder,
 * MarkLeadsBooked). The visitor is always redirected to the same URL
 * without the two parameters, so lead ids don't linger in shared URLs and
 * cached pages keep one cache entry. Runs before the full-page cache.
 */
final class CaptureLeadReference
{
    public const SESSION_KEY = 'lead_ref';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') || $request->query('ref') !== LeadRef::REF || ! $request->query->has('lead')) {
            return $next($request);
        }

        $leadId = LeadRef::resolveId(is_string($request->query('lead')) ? $request->query('lead') : null);

        if ($leadId !== null && $request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $leadId);
        }

        $query = $request->query();
        unset($query['ref'], $query['lead']);

        return redirect()->to($request->url().($query === [] ? '' : '?'.http_build_query($query)));
    }
}
