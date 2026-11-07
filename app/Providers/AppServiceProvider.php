<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Support\BusinessFacts;
use App\Support\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Intervention\Image\ImageManager;
use Spatie\Activitylog\Models\Activity;

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
        // Full admin rights (content, settings, customers, orders, audit log).
        // Staff reach the panel too, but only what their policies allow.
        Gate::define('access-admin', fn (User $user): bool => $user->isAdmin());
        Gate::policy(Activity::class, ActivityPolicy::class);

        // Strong passwords everywhere a password is set (PLAN §7). The
        // breached-password (HIBP) check calls an API, so production only.
        Password::defaults(function (): Password {
            $rule = Password::min(12)->letters()->mixedCase()->numbers()->symbols();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        // Contact form: a few honest retries, not a flood (PLAN §7).
        RateLimiter::for('leads', fn (Request $request): array => [
            Limit::perMinute(3)->by('leads-minute:'.$request->ip()),
            Limit::perDay(20)->by('leads-day:'.$request->ip()),
        ]);
    }
}
