<?php

declare(strict_types=1);

use App\Http\Controllers\Account\BookingCalendarController;
use App\Http\Controllers\Account\BookingChangeController;
use App\Http\Controllers\Account\BookingController;
use App\Http\Controllers\Account\CreditController;
use App\Http\Controllers\Account\DashboardController;
use App\Http\Controllers\Account\OrderController;
use App\Http\Controllers\Account\PrivacyController;
use App\Http\Controllers\Account\ProfileController;
use App\Http\Controllers\Account\ProgressController;
use App\Http\Controllers\Account\ReferralController as AccountReferralController;
use App\Http\Controllers\Account\RiderController;
use App\Http\Controllers\Account\SignOutEverywhereController;
use App\Http\Controllers\Account\TicketController;
use App\Http\Controllers\Account\VoucherController as AccountVoucherController;
use App\Http\Controllers\Account\WaitlistController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RestoreAccountController;
use App\Http\Controllers\BankTransferController;
use App\Http\Controllers\CheckInController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\GiftVoucherController;
use App\Http\Controllers\PrintableVoucherController;
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
use App\Http\Controllers\ReferralLinkController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SlotChangeController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\Team\RiderHistoryController;
use App\Http\Controllers\Team\RiderProgressController;
use App\Http\Controllers\Team\RunSheetController;
use App\Http\Controllers\TransferProofController;
use App\Http\Controllers\VoucherCheckoutController;
use App\Http\Controllers\VoucherTransferProofController;
use App\Http\Middleware\AddStatelessSecurityHeaders;
use App\Http\Middleware\EnsureCheckInAccess;
use App\Http\Middleware\EnsureTeamAreaAccess;
use App\Support\PageCache;
use Illuminate\Support\Facades\Route;
use Spatie\ResponseCache\Middlewares\CacheResponse;

/*
|--------------------------------------------------------------------------
| Public pages (full-page cached; cleared on any content change)
|--------------------------------------------------------------------------
*/

// Pages that show seats left: their cache key carries the seats version, so
// a booking or hold renders them fresh (PageCache, milestone 11); shorter
// lifetime so superseded copies age out.
Route::middleware(['cache.headers:private;no_cache', CacheResponse::using(PageCache::SEAT_PAGES_LIFETIME)])->group(function (): void {
    Route::get('/', HomeController::class)->name('home');
    Route::get('/sessions/{product:slug}', SessionController::class)->name('sessions.show');
    Route::get('/schedule', ScheduleController::class)->name('schedule');
});

Route::middleware(['cache.headers:private;no_cache', CacheResponse::class])->group(function (): void {
    Route::get('/experiences', ExperienceController::class)->name('experiences');
    Route::get('/prices', PriceController::class)->name('prices');
    Route::get('/gift-vouchers', [GiftVoucherController::class, 'index'])->name('gift-vouchers');
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

Route::middleware(['auth', 'cache.headers:private;no_store'])->prefix('checkout/{reference}')->name('checkout.')->group(function (): void {
    Route::get('/', [CheckoutController::class, 'show'])->name('show');
    Route::post('/card', [CheckoutController::class, 'card'])->middleware('throttle:checkout')->name('card');
    Route::get('/status', [CheckoutController::class, 'status'])->name('status');

    // Gift vouchers, promo codes and account credit (milestone 10e Part B).
    Route::post('/discount', [CheckoutController::class, 'discount'])->middleware('throttle:checkout-discounts')->name('discount');
    Route::delete('/discount/{kind}', [CheckoutController::class, 'removeDiscount'])->middleware('throttle:checkout-discounts')->name('discount.remove');
    Route::post('/credit', [CheckoutController::class, 'credit'])->middleware('throttle:checkout-discounts')->name('credit');
    Route::post('/confirm', [CheckoutController::class, 'confirm'])->middleware('throttle:checkout')->name('confirm');

    // Bank transfer (PLAN §3.2): same order and reference as card payment.
    Route::post('/transfer', [BankTransferController::class, 'choose'])->middleware('throttle:checkout')->name('transfer.choose');
    Route::get('/transfer', [BankTransferController::class, 'show'])->name('transfer');
    Route::post('/transfer/proof', [BankTransferController::class, 'upload'])->middleware('throttle:transfer-upload')->name('transfer.upload');
});

// Gift vouchers (milestone 10e): bought by a signed-in customer, paid by
// card or bank transfer like an order, issued only once payment is confirmed.
Route::middleware(['auth', 'cache.headers:private;no_store'])->prefix('gift-vouchers')->name('gift-vouchers.')->group(function (): void {
    Route::get('/buy', [GiftVoucherController::class, 'create'])->name('create');
    Route::post('/buy', [GiftVoucherController::class, 'store'])->middleware('throttle:checkout')->name('store');

    Route::prefix('{reference}')->where(['reference' => 'GV-[A-Z0-9]{6}'])->group(function (): void {
        Route::get('/', [VoucherCheckoutController::class, 'show'])->name('show');
        Route::post('/card', [VoucherCheckoutController::class, 'card'])->middleware('throttle:checkout')->name('card');
        Route::get('/status', [VoucherCheckoutController::class, 'status'])->name('status');
        Route::post('/transfer', [VoucherCheckoutController::class, 'chooseTransfer'])->middleware('throttle:checkout')->name('transfer.choose');
        Route::get('/transfer', [VoucherCheckoutController::class, 'transfer'])->name('transfer');
        Route::post('/transfer/proof', [VoucherCheckoutController::class, 'upload'])->middleware('throttle:transfer-upload')->name('transfer.upload');
    });
});

// Referral links (milestone 10e): remembered for this visit only, never cached.
Route::get('/r/{code}', ReferralLinkController::class)
    ->where('code', '[A-Za-z0-9]{8}')
    ->middleware(['cache.headers:private;no_store', 'throttle:referral-link'])
    ->name('referral');

// Printable gift voucher from the recipient's email (milestone 10e): signed
// link, no sign-in (the recipient may have no account), never cached.
Route::get('/vouchers/{voucher}', PrintableVoucherController::class)
    ->where('voucher', '[A-Z0-9]{12}')
    ->middleware(['signed', 'cache.headers:private;no_store'])
    ->name('vouchers.show');

// Gate check-in from the ticket QR (PLAN §6): staff and admins only
// (EnsureCheckInAccess: guests go to the admin login with MFA).
Route::middleware([EnsureCheckInAccess::class, 'cache.headers:private;no_store'])->prefix('checkin/{code}')->name('checkin.')->group(function (): void {
    Route::get('/', [CheckInController::class, 'show'])->name('show');
    Route::post('/', [CheckInController::class, 'store'])->middleware('throttle:checkin')->name('store');
});

// Run sheets and rider history (milestone 10b): staff, admins and trainers
// (policies decide per session and rider). Mobile-first, never cached.
Route::middleware([EnsureTeamAreaAccess::class, 'cache.headers:private;no_store'])->prefix('team')->name('team.')->group(function (): void {
    Route::get('sessions', [RunSheetController::class, 'index'])->name('sessions.index');
    Route::get('sessions/{slot}', [RunSheetController::class, 'show'])->name('sessions.show');
    Route::scopeBindings()->middleware('throttle:checkin')->prefix('sessions/{slot}/bookings/{booking}')->name('sessions.')->group(function (): void {
        Route::post('check-in', [RunSheetController::class, 'checkIn'])->name('check-in');
        Route::post('horse', [RunSheetController::class, 'horse'])->name('horse');
        Route::post('note', [RunSheetController::class, 'note'])->name('note');
        Route::post('progress', RiderProgressController::class)->name('progress');
    });
    Route::get('riders/{rider}', [RiderHistoryController::class, 'show'])->name('riders.show');
});

// Transfer proofs for admins: short-lived signed URL, signed-in admin only,
// under the (unguessable) admin path. Never public (PLAN §7).
Route::get('/'.trim((string) config('app.admin_path', 'admin'), '/').'/transfers/{transfer}/proof', TransferProofController::class)
    ->middleware(['auth', 'signed', 'cache.headers:private;no_store'])
    ->name('admin.transfer-proof');

// Gift voucher transfer proofs (milestone 10e): same rules as order proofs.
Route::get('/'.trim((string) config('app.admin_path', 'admin'), '/').'/voucher-transfers/{voucherOrder}/proof', VoucherTransferProofController::class)
    ->middleware(['auth', 'signed', 'cache.headers:private;no_store'])
    ->name('admin.voucher-transfer-proof');

// Stripe webhooks (PLAN §3.1): signature-verified, CSRF-exempt (bootstrap/app.php),
// no session or cookies, rate limited.
Route::post('/webhooks/stripe', StripeWebhookController::class)
    ->withoutMiddleware(['web'])
    ->middleware([AddStatelessSecurityHeaders::class, 'throttle:stripe-webhook'])
    ->name('webhooks.stripe');

// CSP violation reports (PLAN §7): no session, cookies or CSRF; rate limited.
Route::post('/csp-report', CspReportController::class)
    ->withoutMiddleware(['web'])
    ->middleware([AddStatelessSecurityHeaders::class, 'throttle:csp-report'])
    ->name('csp.report');

// Served from the generated file, which a queued job rewrites after the
// page cache has been cleared, so it is never response-cached.
Route::get('/sitemap.xml', [SeoFileController::class, 'sitemap'])->name('sitemap');

// Reviews of an attended session (milestone 10d): the signed link from the
// thank-you email is the permission, so no sign-in. Never cached or indexed.
Route::middleware(['signed', 'cache.headers:private;no_store'])->prefix('reviews/{ticket}')->name('reviews.')->group(function (): void {
    Route::get('/', [ReviewController::class, 'create'])->name('create');
    Route::post('/', [ReviewController::class, 'store'])->middleware('throttle:account-actions')->name('store');
});

// After the bulk schedule editor moved a booked session (prompt 15A): keep,
// move or cancel from the signed email link (no sign-in; the controller
// checks the signature so an expired link gets a clear page). Never cached.
Route::middleware(['cache.headers:private;no_store'])->prefix('schedule-change/{notice}')->name('slot-change.')->group(function (): void {
    Route::get('/', [SlotChangeController::class, 'show'])->name('show');
    Route::post('keep', [SlotChangeController::class, 'accept'])->middleware('throttle:account-actions')->name('accept');
    Route::get('move', [SlotChangeController::class, 'moveForm'])->name('move');
    Route::post('move', [SlotChangeController::class, 'move'])->middleware('throttle:account-actions')->name('move.store');
    Route::post('cancel', [SlotChangeController::class, 'cancel'])->middleware('throttle:account-actions')->name('cancel');
});

// Contact form: CSRF token, flash status and per-visit attribution.
Route::get('/contact', [ContactController::class, 'show'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:leads')->name('contact.store');
// "Ask a question" on /prices (cached page): same rules, own source.
Route::post('/prices/question', [ContactController::class, 'storeQuestion'])->middleware('throttle:leads')->name('prices.question');

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
    Route::post('/login/restore', [RestoreAccountController::class, 'store'])->middleware('throttle:account-actions')->name('account.restore.store');
    Route::delete('/login/restore', [RestoreAccountController::class, 'destroy'])->middleware('throttle:account-actions')->name('account.restore.cancel');
});

Route::post('/logout', [LoginController::class, 'logout'])->middleware(['auth', 'throttle:account-actions'])->name('logout');

Route::middleware(['auth', 'cache.headers:private;no_store'])->prefix('account')->name('account.')->group(function (): void {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/bookings', [BookingController::class, 'index'])->name('bookings');
    Route::get('/bookings/{ticket}/calendar.ics', BookingCalendarController::class)->name('bookings.calendar');
    Route::get('/bookings/{ticket}/reschedule', [BookingChangeController::class, 'editReschedule'])->name('bookings.reschedule.edit');
    Route::post('/bookings/{ticket}/reschedule', [BookingChangeController::class, 'reschedule'])->middleware('throttle:account-actions')->name('bookings.reschedule');
    Route::get('/bookings/{ticket}/cancel', [BookingChangeController::class, 'confirmCancel'])->name('bookings.cancel');
    Route::post('/bookings/{ticket}/cancel', [BookingChangeController::class, 'cancel'])->middleware('throttle:account-actions')->name('bookings.cancel.store');
    // Waitlists for full slots (milestone 10d).
    Route::get('/waitlist', [WaitlistController::class, 'index'])->name('waitlist');
    Route::get('/waitlist/join/{slot}', [WaitlistController::class, 'create'])->name('waitlist.create');
    Route::post('/waitlist/join/{slot}', [WaitlistController::class, 'store'])->middleware('throttle:account-actions')->name('waitlist.store');
    Route::delete('/waitlist/{entry}', [WaitlistController::class, 'destroy'])->whereNumber('entry')->middleware('throttle:account-actions')->name('waitlist.destroy');
    Route::get('/tickets', [TicketController::class, 'index'])->name('tickets');
    Route::get('/tickets/{code}', [TicketController::class, 'show'])->name('tickets.show');
    Route::get('/credits', CreditController::class)->name('credits');
    Route::get('/vouchers', [AccountVoucherController::class, 'index'])->name('vouchers');
    Route::get('/referrals', AccountReferralController::class)->name('referrals');
    Route::put('/vouchers/{reference}/recipient', [AccountVoucherController::class, 'recipient'])->middleware('throttle:account-actions')->name('vouchers.recipient');
    Route::get('/orders', [OrderController::class, 'index'])->name('orders');
    Route::get('/orders/{reference}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('/riders', RiderController::class)->name('riders');
    Route::get('/progress', ProgressController::class)->name('progress');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile');
    Route::put('/profile', [ProfileController::class, 'update'])->middleware('throttle:account-actions')->name('profile.update');

    Route::get('/privacy', [PrivacyController::class, 'show'])->name('privacy');
    Route::post('/privacy/export', [PrivacyController::class, 'export'])->middleware('throttle:account-export')->name('privacy.export');
    Route::delete('/', [PrivacyController::class, 'destroy'])->middleware('throttle:account-actions')->name('destroy');

    Route::post('/sign-out-everywhere', SignOutEverywhereController::class)->middleware('throttle:account-actions')->name('sign-out-everywhere');
});

// Component styleguide: local development only.
if (app()->environment('local')) {
    Route::view('/styleguide', 'styleguide')->name('styleguide');
}
