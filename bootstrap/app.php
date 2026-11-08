<?php

declare(strict_types=1);

use App\Http\Middleware\AddRobotsHeader;
use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\EnsureSessionIsCurrent;
use App\Http\Middleware\SetCspNonce;
use App\Support\Tracking\Attribution;
use App\Support\Tracking\Consent;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Nonce first, then the security headers (CSP built from that nonce)
        // around everything else, including the full-page cache.
        $middleware->web(prepend: [
            SetCspNonce::class,
            AddSecurityHeaders::class,
        ], append: [
            AddRobotsHeader::class,
            EnsureSessionIsCurrent::class,
        ]);

        // Written by resources/js/tracking.js (consent answer, first-touch
        // attribution) and the Meta Pixel: plain values, validated on read.
        $middleware->encryptCookies(except: [Consent::COOKIE, Attribution::COOKIE, '_fbp', '_fbc']);

        // Stripe signs its webhooks; it can't send a CSRF token (PLAN §6).
        $middleware->validateCsrfTokens(except: ['webhooks/stripe']);

        $middleware->redirectGuestsTo(fn (): string => route('login'));
        $middleware->redirectUsersTo(fn (): string => route('account.dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
