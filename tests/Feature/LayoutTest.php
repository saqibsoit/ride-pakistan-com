<?php

declare(strict_types=1);

use App\Enums\SettingKey;
use App\Models\Page;
use App\Support\Settings;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('site', ['url' => '/images']);
    putTestImage('site', 'logo.png', 600, 355, 'png');
    putTestImage('site', 'hero.jpg', 1200, 1800);
});

it('renders the layout with skip link, landmarks, head slot and font preload', function (): void {
    $this->blade(<<<'BLADE'
        <x-layouts.app title="Prices" description="Prices for riding lessons." robots="noindex">
            <x-slot:head><meta name="test-head" content="from-slot"></x-slot:head>
            <h1>Prices</h1>
        </x-layouts.app>
        BLADE)
        ->assertSee('<title>Prices | Ride Pakistan</title>', false)
        ->assertSee('<meta name="description" content="Prices for riding lessons.">', false)
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
        ->assertSee('<link rel="canonical"', false)
        ->assertSee('<meta name="test-head" content="from-slot">', false)
        ->assertSee('rel="preload"', false)
        ->assertSee('as="font" type="font/woff2" crossorigin', false)
        ->assertSee('big-shoulders-display', false)
        ->assertSee('rel="apple-touch-icon"', false)
        ->assertSee('rel="manifest"', false)
        ->assertSeeInOrder(['href="#main"', 'Skip to content', '<header', '<main id="main"', '<footer'], false);
});

it('uses the default title and description from settings', function (): void {
    $settings = app(Settings::class);

    $this->blade('<x-layouts.app><p>Hi</p></x-layouts.app>')
        ->assertSee('<title>'.e($settings->string(SettingKey::DefaultTitle)).'</title>', false)
        ->assertSee(e($settings->string(SettingKey::DefaultDescription)), false);
});

it('is noindex outside production and indexable in production', function (): void {
    $this->blade('<x-layouts.app><p>Hi</p></x-layouts.app>')
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false);

    config(['site.indexable' => true]);

    $this->blade('<x-layouts.app><p>Hi</p></x-layouts.app>')
        ->assertSee('<meta name="robots" content="index, follow, max-image-preview:large">', false);
});

it('renders the header with nav, book button and an accessible mobile menu toggle', function (): void {
    $this->blade('<x-site-header />')
        ->assertSee('x-data="siteHeader"', false)
        ->assertSee('aria-label="Main"', false)
        ->assertSee('Book a session')
        ->assertSee('aria-controls="mobile-menu"', false)
        ->assertSee('aria-expanded="false"', false)
        ->assertSee('Open menu')
        ->assertSee('id="mobile-menu"', false)
        ->assertSee('sticky', false);
});

it('makes the overlay header fixed and transparent until scrolled', function (): void {
    $this->blade('<x-site-header overlay />')
        ->assertSee('x-bind:class="overlayClass"', false)
        ->assertSee('fixed inset-x-0', false)
        ->assertDontSee('sticky', false);
});

it('renders the footer with contact links and only published legal pages', function (): void {
    $settings = app(Settings::class);

    $this->blade('<x-site-footer />')
        ->assertSee($settings->string(SettingKey::InstagramUrl), false)
        ->assertDontSee('aria-label="Legal"', false);

    foreach (['privacy' => 'Privacy policy', 'terms' => 'Terms', 'refund-policy' => 'Refund policy'] as $slug => $title) {
        Page::factory()->create(['slug' => $slug, 'title' => $title]);
    }

    $this->blade('<x-site-footer />')
        ->assertSee($settings->string(SettingKey::InstagramUrl), false)
        ->assertSee('ridepakistan.co')
        ->assertSee('mailto:'.$settings->string(SettingKey::Email), false)
        ->assertSee('Privacy')
        ->assertSee('Terms')
        ->assertSee('Refund policy')
        ->assertSee('A Destination Pakistan company');
});

it('renders a hero headline from lines with a priority image', function (): void {
    $this->blade('<x-hero image="hero.jpg" image-alt="A rider and a horse" :lines="$lines" lede="Weekends in Islamabad."><x-button href="/prices">Prices</x-button></x-hero>', [
        'lines' => ['Never touched', 'a horse?', 'Perfect.'],
    ])
        ->assertSeeInOrder(['<h1', 'Never touched', 'a horse?', 'Perfect.', '</h1>'], false)
        ->assertSee('text-hero', false)
        ->assertSee('fetchpriority="high"', false)
        ->assertSee('Weekends in Islamabad.')
        ->assertSee('href="/prices"', false);
});
