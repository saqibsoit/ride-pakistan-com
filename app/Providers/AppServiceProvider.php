<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Support\BusinessFacts;
use App\Support\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\ImageManager;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // GD driver; EXIF orientation applied and metadata stripped on encode.
        $this->app->singleton(ImageManager::class, fn (): ImageManager => ImageManager::gd(
            autoOrientation: true,
            strip: true,
        ));

        // Per request (and per queued job): memoised, never stale across jobs.
        $this->app->scoped(Settings::class);
        $this->app->scoped(BusinessFacts::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Admin panel and content management. Deny by default: staff/admin
        // roles arrive in milestone 3 and replace this check.
        Gate::define('access-admin', fn (User $user): bool => false);

        // Contact form: a few honest retries, not a flood (PLAN §7).
        RateLimiter::for('leads', fn (Request $request): array => [
            Limit::perMinute(3)->by('leads-minute:'.$request->ip()),
            Limit::perDay(20)->by('leads-day:'.$request->ip()),
        ]);
    }
}
