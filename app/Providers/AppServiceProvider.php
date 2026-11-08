<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\LoginCodes\EmailLoginCodeChannel;
use App\Auth\LoginCodes\LoginCodeChannel;
use App\Http\Middleware\EnsureSessionIsCurrent;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Support\BusinessFacts;
use App\Support\PageCache;
use App\Support\Settings;
use App\Support\Stripe\StripeClientGateway;
use App\Support\Stripe\StripeGateway;
use App\Support\Tracking\BrowserEvents;
use Closure;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
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
        $this->app->scoped(PageCache::class);
        $this->app->scoped(BrowserEvents::class);

        // One-time sign-in codes go by email; a phone/WhatsApp channel
        // can replace or sit beside this binding later.
        $this->app->bind(LoginCodeChannel::class, EmailLoginCodeChannel::class);

        $this->app->singleton(StripeGateway::class, fn (): StripeGateway => new StripeClientGateway(
            secret: (string) config('services.stripe.secret'),
            webhookSecret: (string) config('services.stripe.webhook_secret'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Debug mode shows stack traces and config to visitors (PLAN §7).
        if ($this->app->isProduction() && config('app.debug') === true) {
            Log::critical('APP_DEBUG is on in production. Set APP_DEBUG=false now.');
        }

        // Full admin rights (content, settings, customers, orders, audit log).
        // Staff reach the panel too, but only what their policies allow.
        Gate::define('access-admin', fn (User $user): bool => $user->isAdmin());

        // Gate check-in at /checkin (PLAN §6): staff and admins, and trainers
        // for their own slots (BookingPolicy::checkIn decides per ticket).
        // Staff get nothing else beyond their read access.
        Gate::define('check-in', fn (User $user): bool => $user->isTeamMember() || $user->isTrainer());
        // Run sheets and rider history at /team (milestone 10b): staff,
        // admins and trainers; SlotPolicy and RiderPolicy decide per record.
        // Exports of orders, payments, refunds, bookings and leads (milestone 10b).
        Gate::define('export-data', fn (User $user): bool => $user->isAdmin());
        Gate::define('team-area', fn (User $user): bool => $user->isTeamMember() || $user->isTrainer());
        Gate::policy(Activity::class, ActivityPolicy::class);

        // Strong passwords everywhere a password is set (PLAN §7). The
        // breached-password (HIBP) check calls an API, so production only.
        Password::defaults(function (): Password {
            $rule = Password::min(12)->letters()->mixedCase()->numbers()->symbols();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        // ` nonce="…"` for our own <script> tags (PLAN §7). Vite, Livewire
        // and Filament add it to theirs from Vite::cspNonce().
        Blade::directive('nonce', fn (): string => '<?php echo \App\Support\Csp\Nonce::attribute(); ?>');

        // Lead forms: a few honest retries, not a flood (PLAN §7).
        RateLimiter::for('leads', fn (Request $request): array => [
            Limit::perMinute(3)->by('leads-minute:'.$request->ip()),
            Limit::perDay(20)->by('leads-day:'.$request->ip()),
        ]);

        $this->configureLoginRateLimits();

        // "Download my data": a few per hour is plenty.
        RateLimiter::for('account-export', fn (Request $request): Limit => Limit::perHour(5)
            ->by('account-export:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Starting card payment calls Stripe: enough for retries, not a loop.
        RateLimiter::for('checkout', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('checkout:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Gate check-in: generous for a busy morning, but not a scanner loop.
        RateLimiter::for('checkin', fn (Request $request): Limit => Limit::perMinute(60)
            ->by('checkin:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Transfer proof uploads (PLAN §7): a few replacements, not a flood.
        RateLimiter::for('transfer-upload', fn (Request $request): Limit => Limit::perHour(10)
            ->by('transfer-upload:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Stripe sends bursts on retries; anything far above this is not Stripe.
        RateLimiter::for('stripe-webhook', fn (Request $request): Limit => Limit::perMinute(300)
            ->by('stripe-webhook:'.$request->ip()));

        // CSP reports: a page with a broken policy sends a few per view.
        RateLimiter::for('csp-report', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('csp-report:'.$request->ip()));

        // Account changes (profile, reschedule, cancel, sign out, delete,
        // restore): plenty for a person, not for a script.
        RateLimiter::for('account-actions', fn (Request $request): Limit => Limit::perMinute(20)
            ->by('account-actions:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Every new sign-in (customer code or admin panel) records the
        // account's session version for "sign out everywhere".
        Event::listen(function (Login $event): void {
            $request = request();

            if ($event->user instanceof User && $request->hasSession()) {
                $request->session()->put(EnsureSessionIsCurrent::SESSION_KEY, $event->user->session_version);
            }
        });
    }

    /**
     * Customer code sign-in (PLAN §7): requests limited per email and per
     * IP, code submissions per IP (each code also dies after five wrong
     * guesses). Limits are in config/login.php.
     */
    private function configureLoginRateLimits(): void
    {
        $tooMany = fn (string $field, string $message): Closure => fn (Request $request): RedirectResponse => back()
            ->withInput($request->only('email'))
            ->withErrors([$field => $message]);

        RateLimiter::for('login-code-request', function (Request $request) use ($tooMany): array {
            $email = hash('sha256', Str::lower(trim($request->string('email')->toString())));
            $response = $tooMany('email', 'Too many code requests. Please wait a few minutes and try again.');

            return [
                Limit::perMinutes(10, (int) config('login.request_limits.per_email_per_10_minutes'))->by('login-email-10m:'.$email)->response($response),
                Limit::perDay((int) config('login.request_limits.per_email_per_day'))->by('login-email-day:'.$email)->response($response),
                Limit::perHour((int) config('login.request_limits.per_ip_per_hour'))->by('login-ip-hour:'.$request->ip())->response($response),
            ];
        });

        RateLimiter::for('login-code-verify', fn (Request $request): Limit => Limit::perMinute((int) config('login.verify_limits.per_ip_per_minute'))
            ->by('login-verify:'.$request->ip())
            ->response($tooMany('code', 'Too many attempts. Please wait a minute and try again.')));
    }
}
