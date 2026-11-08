<?php

declare(strict_types=1);

use App\Http\Controllers\Account\BookingChangeController;
use App\Http\Controllers\Account\BookingController;
use App\Http\Controllers\Account\CreditController;
use App\Http\Controllers\Account\DashboardController;
use App\Http\Controllers\Account\OrderController;
use App\Http\Controllers\Account\PrivacyController;
use App\Http\Controllers\Account\ProfileController;
use App\Http\Controllers\Account\RiderController;
use App\Http\Controllers\Account\SignOutEverywhereController;
use App\Http\Controllers\Account\TicketController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RestoreAccountController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\Public\BookController;
use App\Http\Controllers\Public\ContactController;
use App\Http\Controllers\Public\ContentPageController;
use App\Http\Controllers\Public\ExperienceController;
use App\Http\Controllers\Public\FaqController;
use App\Http\Controllers\Public\GuideController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\PriceController;
use App\Http\Controllers\Public\ScheduleController;
use App\Http\Controllers\Public\SeoFileController;
use App\Http\Controllers\Public\SessionController;
use Illuminate\Support\Facades\Route;
use Spatie\ResponseCache\Middlewares\CacheResponse;

/*
|--------------------------------------------------------------------------
| Public pages (full-page cached; cleared on any content change)
|--------------------------------------------------------------------------
*/

Route::middleware(CacheResponse::class)->group(function (): void {
    Route::get('/', HomeController::class)->name('home');
    Route::get('/experiences', ExperienceController::class)->name('experiences');
    Route::get('/sessions/{product:slug}', SessionController::class)->name('sessions.show');
    Route::get('/schedule', ScheduleController::class)->name('schedule');
    Route::get('/prices', PriceController::class)->name('prices');
    Route::get('/faq', FaqController::class)->name('faq');
    Route::get('/guides', [GuideController::class, 'index'])->name('guides.index');
    Route::get('/guides/{post:slug}', [GuideController::class, 'show'])->name('guides.show');

    Route::get('/trainer/omar-saleem', ContentPageController::class)->defaults('slug', 'trainer')->name('trainer');
    Route::get('/expeditions', ContentPageController::class)->defaults('slug', 'expeditions')->name('expeditions');
    Route::get('/privacy', ContentPageController::class)->defaults('slug', 'privacy')->name('legal.privacy');
    Route::get('/terms', ContentPageController::class)->defaults('slug', 'terms')->name('legal.terms');
    Route::get('/refund-policy', ContentPageController::class)->defaults('slug', 'refund-policy')->name('legal.refund-policy');

    Route::get('/robots.txt', [SeoFileController::class, 'robots'])->name('robots');
    Route::get('/llms.txt', [SeoFileController::class, 'llms'])->name('llms');
});

// Booking flow (Livewire, per-visitor state): never response-cached.
Route::get('/book', BookController::class)->middleware('cache.headers:private;no_store')->name('book');

Route::get('/checkout/{reference}', [CheckoutController::class, 'show'])
    ->middleware(['auth', 'cache.headers:private;no_store'])
    ->name('checkout.show');

// Served from the generated file, which a queued job rewrites after the
// page cache has been cleared, so it is never response-cached.
Route::get('/sitemap.xml', [SeoFileController::class, 'sitemap'])->name('sitemap');

// Contact form: CSRF token, flash status and per-visit attribution.
Route::get('/contact', [ContactController::class, 'show'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:leads')->name('contact.store');

/*
|--------------------------------------------------------------------------
| Customer sign-in (email one-time code) and account area (PLAN §6)
|--------------------------------------------------------------------------
*/

Route::middleware(['guest', 'cache.headers:private;no_store'])->group(function (): void {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'request'])->middleware('throttle:login-code-request')->name('login.request');
    Route::get('/login/code', [LoginController::class, 'showVerify'])->name('login.verify');
    Route::post('/login/code', [LoginController::class, 'verify'])->middleware('throttle:login-code-verify')->name('login.verify.store');

    Route::get('/login/restore', [RestoreAccountController::class, 'show'])->name('account.restore');
    Route::post('/login/restore', [RestoreAccountController::class, 'store'])->name('account.restore.store');
    Route::delete('/login/restore', [RestoreAccountController::class, 'destroy'])->name('account.restore.cancel');
});

Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'cache.headers:private;no_store'])->prefix('account')->name('account.')->group(function (): void {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/bookings', [BookingController::class, 'index'])->name('bookings');
    Route::get('/bookings/{ticket}/reschedule', [BookingChangeController::class, 'editReschedule'])->name('bookings.reschedule.edit');
    Route::post('/bookings/{ticket}/reschedule', [BookingChangeController::class, 'reschedule'])->name('bookings.reschedule');
    Route::get('/bookings/{ticket}/cancel', [BookingChangeController::class, 'confirmCancel'])->name('bookings.cancel');
    Route::post('/bookings/{ticket}/cancel', [BookingChangeController::class, 'cancel'])->name('bookings.cancel.store');
    Route::get('/tickets', [TicketController::class, 'index'])->name('tickets');
    Route::get('/tickets/{code}', [TicketController::class, 'show'])->name('tickets.show');
    Route::get('/credits', CreditController::class)->name('credits');
    Route::get('/orders', [OrderController::class, 'index'])->name('orders');
    Route::get('/orders/{reference}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('/riders', RiderController::class)->name('riders');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('/privacy', [PrivacyController::class, 'show'])->name('privacy');
    Route::post('/privacy/export', [PrivacyController::class, 'export'])->middleware('throttle:account-export')->name('privacy.export');
    Route::delete('/', [PrivacyController::class, 'destroy'])->name('destroy');

    Route::post('/sign-out-everywhere', SignOutEverywhereController::class)->name('sign-out-everywhere');
});

// Component styleguide: local development only.
if (app()->environment('local')) {
    Route::view('/styleguide', 'styleguide')->name('styleguide');
}
