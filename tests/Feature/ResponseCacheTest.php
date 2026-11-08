<?php

declare(strict_types=1);

use App\Actions\Settings\UpdateSettings;
use App\Models\Faq;
use App\Models\Product;
use App\Models\ScheduleRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\ResponseCache\Facades\ResponseCache;

beforeEach(function (): void {
    $this->seed();
    $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00', 'Asia/Karachi'));
});

it('serves public pages from the full-page cache', function (): void {
    $this->get('/prices')->assertSee('PKR 10,000');

    // A write that bypasses model events does not clear the cache...
    DB::table('products')->where('slug', 'single-session')->update(['price_minor' => 1_100_000]);

    $this->get('/prices')->assertSee('PKR 10,000')->assertDontSee('PKR 11,000');
});

it('clears the cache when a product changes', function (): void {
    $this->get('/prices')->assertSee('PKR 10,000');

    Product::query()->where('slug', 'single-session')->firstOrFail()->update(['price_minor' => 1_100_000]);

    $this->get('/prices')->assertSee('PKR 11,000');
});

it('clears the cache when content, schedule or settings change', function (): void {
    $this->get('/faq')->assertDontSee('Can I bring my dog?');
    Faq::query()->create(['question' => 'Can I bring my dog?', 'answer' => 'Sadly not.', 'is_published' => true]);
    $this->get('/faq')->assertSee('Can I bring my dog?');

    $this->get('/schedule')->assertDontSee('6:00 pm');
    ScheduleRule::query()->where('start_time', '15:00:00')->get()->each->update(['end_time' => '18:00:00']);
    $this->get('/schedule')->assertSee('6:00 pm');

    $this->get('/')->assertSee('hello@ridepakistan.co');
    app(UpdateSettings::class)->handle(['contact.email' => 'ride@example.com']);
    $this->get('/')->assertSee('ride@example.com');
});

it('keeps the page cache in its own store, so clearing it never resets rate limits', function (): void {
    expect(config('responsecache.cache_store'))->not->toBe(config('cache.default'));

    RateLimiter::hit('login-code-request:someone@example.com', 600);
    Cache::put('unrelated', 'kept');

    ResponseCache::clear();

    expect(RateLimiter::attempts('login-code-request:someone@example.com'))->toBe(1)
        ->and(Cache::get('unrelated'))->toBe('kept');
});
