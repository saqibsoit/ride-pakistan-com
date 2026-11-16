<?php

declare(strict_types=1);

use App\Actions\Settings\UpdateSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('site', ['url' => '/images']);
});

it('shows availability without the seat count, with selling-fast and fully-booked states', function (int $seats, string $text, bool $sellingFast): void {
    $view = $this->blade('<x-seats-left :seats="$seats" />', ['seats' => $seats])->assertSee($text)->assertDontSee('left');

    $sellingFast ? $view->assertSee('Selling fast') : $view->assertDontSee('Selling fast');
})->with([
    'plenty' => [6, 'Available', false],
    'three' => [3, 'Available', false],
    'at threshold' => [2, 'Available', true],
    'one' => [1, 'Available', true],
    'none' => [0, 'Fully booked', false],
    'negative is clamped' => [-1, 'Fully booked', false],
]);

it('uses the selling-fast threshold setting', function (): void {
    app(UpdateSettings::class)->handle(['booking.selling_fast_threshold' => 3]);

    $this->blade('<x-seats-left :seats="3" />')->assertSee('Selling fast');
});

it('shows a note instead of seats before live availability exists', function (): void {
    $this->blade('<x-slot-card :starts-at="$start" :ends-at="$end" product="Riding session" href="/book" />', [
        'start' => now()->addDay(),
        'end' => now()->addDay()->addHours(2),
    ])
        ->assertSee('Seats shown when online booking opens')
        ->assertSee('href="/book"', false)
        ->assertDontSee('Available');
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
        ->assertSee('Available');
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
