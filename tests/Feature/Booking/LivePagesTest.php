<?php

declare(strict_types=1);

use App\Actions\Slots\ChangeSlotStatus;
use App\Enums\SlotStatus;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Spatie\ResponseCache\Facades\ResponseCache;

beforeEach(function (): void {
    $this->seed();
    // Wednesday 11 Nov 2026, 09:00 Karachi: the coming weekend is 14–15 Nov.
    $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00', 'Asia/Karachi'));
});

function slotOn(string $localStart, int $capacity = 6): Slot
{
    $start = CarbonImmutable::parse($localStart, 'Asia/Karachi');

    return Slot::query()->where('starts_at', $start->utc())->first()
        ?? Slot::factory()->at($start)->create(['capacity' => $capacity]);
}

it('shows live seats and Selling fast on the home weekend strip', function (): void {
    $slot = slotOn('2026-11-14 08:00');
    Booking::factory()->count(4)->create(['slot_id' => $slot->id]);

    $this->get('/')
        ->assertOk()
        ->assertSee('2 spots left')
        ->assertSee('Selling fast')
        ->assertSee(route('book', ['slot' => $slot->id]), false);
});

it('shows manual slots and closed slots on the schedule', function (): void {
    $manual = Slot::factory()->manual()->at(CarbonImmutable::parse('2026-11-12 16:00', 'Asia/Karachi'))->create(['capacity' => 3]);
    $closed = slotOn('2026-11-15 15:00');
    app(ChangeSlotStatus::class)->handle($closed, SlotStatus::Closed, User::factory()->admin()->create());

    $html = $this->get('/schedule')->assertOk()->getContent();

    expect($html)->toContain('4:00 pm – 6:00 pm')
        ->and($html)->toContain('3 spots left')
        ->and($html)->toContain(route('book', ['slot' => $manual->id]))
        ->and($html)->toContain('Closed for booking')
        ->and($html)->not->toContain(route('book', ['slot' => $closed->id]));
});

it('filters the schedule for private sessions and group sessions', function (): void {
    $busy = slotOn('2026-11-14 08:00');
    Booking::factory()->create(['slot_id' => $busy->id]);
    $private = slotOn('2026-11-15 08:00');
    Booking::factory()->create(['slot_id' => $private->id, 'is_private' => true]);
    $empty = slotOn('2026-11-14 15:00');

    $privateHtml = $this->get('/schedule?type=private')->assertOk()->getContent();
    expect($privateHtml)->toContain(e(route('book', ['slot' => $empty->id, 'product' => 'private-session'])))
        ->and($privateHtml)->not->toContain('slot='.$busy->id)
        ->and($privateHtml)->not->toContain('slot='.$private->id);

    $groupHtml = $this->get('/schedule?type=group')->assertOk()->getContent();
    expect($groupHtml)->toContain('slot='.$busy->id)
        ->and($groupHtml)->not->toContain('slot='.$private->id);

    $this->get('/schedule?type=everything')->assertRedirect('/schedule');
    $this->get('/schedule?type=private&week=2026-11-16')->assertOk()->assertSee('type=private', false);
});

it('shows the booking widget with next slots and seats on product pages', function (): void {
    $slot = slotOn('2026-11-14 08:00');
    BookingHold::factory()->create(['slot_id' => $slot->id, 'seats' => 5]);

    $this->get('/sessions/single-session')
        ->assertOk()
        ->assertSee('Book this session.')
        ->assertSee('name="slot" value="'.$slot->id.'"', false)
        ->assertSee('1 spot left')
        ->assertSee('x-data="stepper"', false)
        ->assertSee('action="'.route('book').'"', false)
        ->assertDontSee('Online booking with live seats is opening soon');

    $this->get('/sessions/8-class-package')->assertOk()->assertSee('I’ll choose my dates later');
});

it('refreshes cached pages after a seat change', function (): void {
    config()->set('responsecache.enabled', true);
    $slot = slotOn('2026-11-14 08:00');

    $this->get('/')->assertSee('6 spots left');
    Booking::factory()->count(5)->create(['slot_id' => $slot->id]);

    $this->get('/')->assertSee('1 spot left');
})->skip(fn (): bool => ! class_exists(ResponseCache::class));

it('never response-caches /book or checkout', function (): void {
    $cache = (string) $this->get('/book')->assertOk()->headers->get('Cache-Control');

    expect($cache)->toContain('no-store')->and($cache)->toContain('private');
});

it('loads Alpine exactly once on plain and Livewire pages', function (string $url, string $entry, string $other, bool $auth): void {
    if ($auth) {
        $this->actingAs(User::factory()->create());
    }

    $html = $this->get($url)->assertOk()->getContent();

    // Built assets: /build/assets/app-HASH.js or livewire-HASH.js.
    expect(preg_match_all('#<script[^>]+assets/'.$entry.'-[\w-]+\.js#', $html))->toBe(1)
        ->and(preg_match('#assets/'.$other.'-[\w-]+\.js#', $html))->toBe(0)
        ->and($html)->not->toContain('/livewire/livewire.js')
        ->and($html)->not->toContain('livewire.min.js');
})->with([
    'home' => ['/', 'app', 'livewire', false],
    'product page' => ['/sessions/single-session', 'app', 'livewire', false],
    'book' => ['/book', 'livewire', 'app', false],
    'riders' => ['/account/riders', 'livewire', 'app', true],
]);
