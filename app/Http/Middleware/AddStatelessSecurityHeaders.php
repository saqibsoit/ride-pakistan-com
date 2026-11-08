<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The same security headers for routes that drop the `web` group (Stripe
 * webhook, CSP reports). withoutMiddleware(['web']) strips every class in
 * that group and their subclasses, so this one wraps AddSecurityHeaders.
 */
final class AddStatelessSecurityHeaders
{
    public function __construct(private readonly AddSecurityHeaders $headers) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $this->headers->handle($request, $next);
    }
}
