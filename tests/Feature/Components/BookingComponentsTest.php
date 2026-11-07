<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('site', ['url' => '/images']);
});

it('shows seats left with selling-fast and fully-booked states', function (int $seats, string $text, bool $sellingFast): void {
    $view = $this->blade('<x-seats-left :seats="$seats" />', ['seats' => $seats])->assertSee($text);

    $sellingFast ? $view->assertSee('Selling fast') : $view->assertDontSee('Selling fast');
})->with([
    'plenty' => [6, '6 spots left', false],
    'three' => [3, '3 spots left', false],
    'at threshold' => [2, '2 spots left', true],
    'one' => [1, '1 spot left', true],
    'none' => [0, 'Fully booked', false],
    'negative is clamped' => [-1, 'Fully booked', false],
]);

it('uses the configured selling-fast threshold', function (): void {
    config(['booking.selling_fast_threshold' => 3]);

    $this->blade('<x-seats-left :seats="3" />')->assertSee('Selling fast');
});

it('can announce seat changes politely', function (): void {
    $this->blade('<x-seats-left :seats="3" live />')->assertSee('aria-live="polite"', false);
});

it('shows slot times in Asia/Karachi and links bookable slots', function (): void {
    $this->blade('<x-slot-card :starts-at="$s" :ends-at="$e" product="Single session" trainer="Omar Saleem" :seats="3" href="/book?slot=1" />', [
        's' => Carbon::parse('2026-11-14 03:00:00', 'UTC'),
        'e' => Carbon::parse('2026-11-14 05:00:00', 'UTC'),
    ])
        ->assertSee('8:00 am – 10:00 am')
        ->assertSee('datetime="2026-11-14T08:00:00+05:00"', false)
        ->assertSee('href="/book?slot=1"', false)
        ->assertSee('Saturday 14 November')
        ->assertSee('with Omar Saleem')
        ->assertSee('3 spots left');
});

it('does not link a fully booked slot', function (): void {
    $this->blade('<x-slot-card :starts-at="$s" :ends-at="$e" product="Single session" :seats="0" href="/book?slot=1" />', [
        's' => Carbon::parse('2026-11-14 10:00:00', 'UTC'),
        'e' => Carbon::parse('2026-11-14 12:00:00', 'UTC'),
    ])
        ->assertSee('3:00 pm – 5:00 pm')
        ->assertDontSee('href=', false)
        ->assertSee('Fully booked');
});

it('renders the experience card with price badge, meta and actions', function (): void {
    putTestImage('site', 'ride.jpg', 800, 600);

    $this->blade(<<<'BLADE'
        <x-experience-card title="Single session" href="/sessions/single" book-href="/book" image="ride.jpg"
            image-alt="A rider on a trail" category="Lessons" :price-minor="1000000" description="Two hours."
            duration="2 hours" group-size="Up to 6 riders" location="Islamabad" featured video-href="https://example.com/v" />
        BLADE)
        ->assertSee('Featured')
        ->assertSee('PKR 10,000')
        ->assertSee('per rider')
        ->assertSee('Lessons')
        ->assertSee('<h3 class="text-display-sm">Single session</h3>', false)
        ->assertSee('Duration')
        ->assertSee('Up to 6 riders')
        ->assertSee('href="/sessions/single"', false)
        ->assertSee('href="/book"', false)
        ->assertSee('Video')
        ->assertSee('alt="A rider on a trail"', false);
});

it('renders the experience card without optional parts', function (): void {
    putTestImage('site', 'ride.jpg', 800, 600);

    $this->blade('<x-experience-card title="Private" href="/p" book-href="/b" image="ride.jpg" />')
        ->assertDontSee('Featured')
        ->assertDontSee('PKR')
        ->assertDontSee('Video')
        ->assertDontSee('<dl', false);
});

it('renders the sticky price box and the mobile bottom bar', function (): void {
    $this->blade('<x-booking.price-box :from-minor="1500000" book-href="#book" note="Pin after booking."><x-seats-left :seats="2" /></x-booking.price-box>')
        ->assertSee('PKR 15,000')
        ->assertSee('sticky', false)
        ->assertSee('lg:flex', false)
        ->assertSee('Selling fast')
        ->assertSee('Pin after booking.')
        ->assertSee('href="#book"', false);

    $this->blade('<x-booking.bottom-bar :from-minor="1500000" book-href="#book" />')
        ->assertSee('bottom-bar', false)
        ->assertSee('lg:hidden', false)
        ->assertSee('PKR 15,000')
        ->assertSee('Book now');
});
