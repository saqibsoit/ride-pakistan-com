<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

/*
 * Full-page cache rules (milestone 11). A response served from the cache
 * carries the package's cache-time header; a fresh render doesn't.
 */

beforeEach(function (): void {
    $this->seed();
    config(['responsecache.enabled' => true, 'responsecache.add_cache_time_header' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00', 'Asia/Karachi'));
});

function fromCache(TestResponse $response): bool
{
    return $response->headers->has((string) config('responsecache.cache_time_header_name'));
}

it('serves one cached copy whatever campaign or click tags the link carries', function (): void {
    expect(fromCache($this->get('/prices?utm_source=instagram&utm_campaign=spring')->assertOk()))->toBeFalse()
        ->and(fromCache($this->get('/prices?fbclid=abc123&utm_source=facebook')))->toBeTrue()
        ->and(fromCache($this->get('/prices?gclid=xyz')))->toBeTrue()
        ->and(fromCache($this->get('/prices')))->toBeTrue();

    // Other parameters still get their own copy; their order doesn't matter.
    expect(fromCache($this->get('/schedule?type=group&week=2026-11-09')->assertOk()))->toBeFalse()
        ->and(fromCache($this->get('/schedule?week=2026-11-09&type=group')))->toBeTrue()
        ->and(fromCache($this->get('/schedule?type=private')))->toBeFalse();
});

it('renders seat pages fresh after a booking but keeps other pages cached', function (): void {
    foreach (['/', '/schedule', '/sessions/single-session', '/prices', '/faq'] as $url) {
        $this->get($url)->assertOk();
    }

    // Seats change in one slot.
    Booking::factory()->create(['slot_id' => Slot::query()->where('starts_at', '>', now())->orderBy('starts_at')->value('id')]);

    expect(fromCache($this->get('/')))->toBeFalse()
        ->and(fromCache($this->get('/schedule')))->toBeFalse()
        ->and(fromCache($this->get('/sessions/single-session')))->toBeFalse()
        ->and(fromCache($this->get('/prices')))->toBeTrue()
        ->and(fromCache($this->get('/faq')))->toBeTrue()
        // ...and the fresh seat pages are cached again for the next visitor.
        ->and(fromCache($this->get('/')))->toBeTrue();
});

it('never serves or stores cached pages for signed-in visitors', function (): void {
    $this->get('/prices')->assertOk();

    $user = User::factory()->create();
    expect(fromCache($this->actingAs($user)->get('/prices')))->toBeFalse()
        ->and(fromCache($this->actingAs($user)->get('/faq')))->toBeFalse();

    auth()->logout();
    // Nothing was stored for the account: /faq is still uncached for guests.
    expect(fromCache($this->get('/faq')))->toBeFalse()
        ->and(fromCache($this->get('/prices')))->toBeTrue();
});

it('neither serves nor stores a page while the visitor has flash data', function (): void {
    $this->get('/prices')->assertOk();

    $flash = ['status' => 'lead-sent', '_flash' => ['old' => ['status'], 'new' => []]];

    expect(fromCache($this->withSession($flash)->get('/prices')))->toBeFalse()
        ->and(fromCache($this->withSession($flash)->get('/faq')))->toBeFalse();

    $this->flushSession();
    expect(fromCache($this->get('/faq')))->toBeFalse();
});

it('gives every cached hit a fresh nonce, in the header and the page', function (): void {
    $first = $this->get('/prices');
    $second = $this->get('/prices');

    preg_match("/'nonce-([^']+)'/", (string) $first->headers->get('Content-Security-Policy'), $a);
    preg_match("/'nonce-([^']+)'/", (string) $second->headers->get('Content-Security-Policy'), $b);

    expect(fromCache($second))->toBeTrue()
        ->and($a[1] ?? null)->not->toBeNull()
        ->and($a[1])->not->toBe($b[1] ?? null)
        ->and((string) $second->getContent())->toContain('nonce="'.$b[1].'"')
        ->and((string) $second->getContent())->not->toContain($a[1]);
});

it('tells browsers and Cloudflare not to store public HTML', function (string $url): void {
    $this->get($url);
    $control = (string) $this->get($url)->headers->get('Cache-Control');

    expect($control)->toContain('no-cache')->toContain('private')->not->toContain('public');
})->with(['/', '/prices', '/schedule', '/faq', '/gift-vouchers', '/robots.txt']);

it('keeps account pages out of every cache', function (): void {
    $control = (string) $this->actingAs(User::factory()->create())->get('/account')->headers->get('Cache-Control');

    expect($control)->toContain('no-store')->toContain('private');
});
