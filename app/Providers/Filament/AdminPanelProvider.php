<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Auth\ChangeTemporaryPassword;
use App\Filament\Auth\Login;
use App\Filament\Support\InitialsAvatarProvider;
use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\EnforceAdminIdleTimeout;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureSessionIsCurrent;
use App\Http\Middleware\SetCspNonce;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Assets\Css;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentAsset;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Livewire\Livewire;
use Livewire\Mechanisms\FrontendAssets\FrontendAssets;
use RuntimeException;

class AdminPanelProvider extends PanelProvider
{
    public function boot(): void
    {
        // Layout for the lead board (Filament's CSS ships no utilities).
        // Published to public/css/app by filament:assets (composer scripts).
        FilamentAsset::register([
            Css::make('lead-board', resource_path('css/filament/lead-board.css')),
        ]);

        // A plain route action, not a discovered page, so it needs its own
        // Livewire name for the form's update requests.
        Livewire::component('filament.admin.auth.change-temporary-password', ChangeTemporaryPassword::class);
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path($this->adminPath())
            ->login(Login::class)
            ->profile(isSimple: false)
            // Every panel user is staff or admin, so MFA is always required.
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
            ], isRequired: true)
            ->navigationGroups([
                'Bookings',
                'Leads',
                'Sales & promotions',
                'Schedule',
                'Customers',
                'Content',
                'System',
            ])
            ->colors([
                'primary' => Color::Amber,
            ])
            // Nothing from third parties (PLAN §7): self-hosted brand font
            // instead of Bunny Fonts, local initials instead of ui-avatars.com.
            ->font('Instrument Sans', url: fn (): string => Vite::asset('resources/css/filament/fonts.css'), provider: LocalFontProvider::class)
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            // Livewire's automatic asset injection is off for the public
            // site (config/livewire.php: the public bundle ships its own
            // CSP build), so the panel adds Livewire's standard styles and
            // script itself, with the request's nonce.
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): HtmlString => new HtmlString(FrontendAssets::styles()))
            ->renderHook(PanelsRenderHook::BODY_END, fn (): HtmlString => new HtmlString(FrontendAssets::scripts()))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            // "Today" (App\Filament\Pages\Dashboard) is discovered with the pages.
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                // CSP nonce and security headers, as on the public site
                // (admin context: AddSecurityHeaders::isAdmin()).
                SetCspNonce::class,
                AddSecurityHeaders::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Temporary password from admin:create: change it first.
            ->authenticatedRoutes(function (): void {
                Route::get('change-password', ChangeTemporaryPassword::class)->name('auth.change-password');
            })
            ->authMiddleware([
                Authenticate::class,
                EnsureSessionIsCurrent::class,
            ])
            // Persistent (Livewire requests too); before the route-level
            // required-MFA check, so a new password comes first.
            ->authMiddleware([
                EnsurePasswordIsChanged::class,
            ], isPersistent: true)
            // Also on Livewire update requests from the panel, so an idle
            // tab can't keep acting (polling doesn't count as activity).
            ->authMiddleware([
                EnforceAdminIdleTimeout::class,
            ], isPersistent: true);
    }

    /**
     * The panel path comes from ADMIN_PATH. An empty value would mount the
     * panel at "/", so production refuses to boot without one.
     */
    private function adminPath(): string
    {
        $path = trim((string) config('app.admin_path'), '/');

        if ($path !== '') {
            return $path;
        }

        if ($this->app->isProduction()) {
            throw new RuntimeException('ADMIN_PATH must be set in production.');
        }

        return 'admin';
    }
}
