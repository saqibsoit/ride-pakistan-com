<?php

declare(strict_types=1);

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
    Route::get('/book', BookController::class)->name('book');

    Route::get('/trainer/omar-saleem', ContentPageController::class)->defaults('slug', 'trainer')->name('trainer');
    Route::get('/expeditions', ContentPageController::class)->defaults('slug', 'expeditions')->name('expeditions');
    Route::get('/privacy', ContentPageController::class)->defaults('slug', 'privacy')->name('legal.privacy');
    Route::get('/terms', ContentPageController::class)->defaults('slug', 'terms')->name('legal.terms');
    Route::get('/refund-policy', ContentPageController::class)->defaults('slug', 'refund-policy')->name('legal.refund-policy');

    Route::get('/robots.txt', [SeoFileController::class, 'robots'])->name('robots');
    Route::get('/llms.txt', [SeoFileController::class, 'llms'])->name('llms');
});

// Served from the generated file, which a queued job rewrites after the
// page cache has been cleared, so it is never response-cached.
Route::get('/sitemap.xml', [SeoFileController::class, 'sitemap'])->name('sitemap');

// Contact form: CSRF token, flash status and per-visit attribution.
Route::get('/contact', [ContactController::class, 'show'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:leads')->name('contact.store');

// Component styleguide: local development only.
if (app()->environment('local')) {
    Route::view('/styleguide', 'styleguide')->name('styleguide');
}
