<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Seo\Seo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Outside production every response says "noindex, nofollow", including
 * files and pages the robots meta tag cannot reach (PLAN §11: staging noindex).
 */
final class AddRobotsHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! Seo::siteIndexable()) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
