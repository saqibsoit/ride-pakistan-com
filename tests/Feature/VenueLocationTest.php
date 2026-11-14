<?php

declare(strict_types=1);

use App\Actions\Settings\UpdateSettings;
use App\Enums\SettingKey;
use App\Support\BusinessFacts;
use Illuminate\Support\Facades\DB;

// The venue is public since 2026-11-14 (PLAN §2): name, directions link and a click-to-load map.

const MAP_LINK = 'https://maps.app.goo.gl/test-venue';
const MAP_EMBED = 'https://www.google.com/maps/embed?pb=!1m18!test-venue';

beforeEach(function (): void {
    $this->seed();
    app(UpdateSettings::class)->handle([
        SettingKey::VenueName->value => 'Yashwala Ranch',
        SettingKey::MapPinUrl->value => MAP_LINK,
        SettingKey::MapEmbedUrl->value => MAP_EMBED,
    ]);
});

it('puts the venue and a directions link in the footer of public pages', function (string $url): void {
    $this->get($url)->assertOk()
        ->assertSee('Yashwala Ranch, Islamabad')
        ->assertSee('href="'.MAP_LINK.'"', false)
        ->assertSee('Get directions');
})->with(['/', '/faq', '/prices', '/schedule', '/experiences']);

it('shows the map card on the contact, home and session pages', function (string $url): void {
    $this->get($url)->assertOk()
        ->assertSee('Where we ride.')
        ->assertSee('x-data="mapEmbed"', false)
        ->assertSee('Show map');
})->with(['/', '/contact', '/sessions/single-session']);

it('keeps the Google iframe inside an x-if template so nothing loads before the click', function (): void {
    $html = (string) $this->get('/contact')->getContent();

    expect($html)->toContain('src="'.e(MAP_EMBED).'"')
        ->and(preg_match('#<template x-if="open">\s*<iframe[^>]+src="'.preg_quote(e(MAP_EMBED), '#').'"#', $html))->toBe(1)
        ->and(substr_count($html, '<iframe'))->toBe(1);
});

it('allows only the Google frame origin in the public CSP', function (): void {
    $policy = (string) $this->get('/contact')->headers->get('Content-Security-Policy');

    expect($policy)->toMatch('#frame-src [^;]*https://www\.google\.com#');
});

it('ignores an embed URL that is not a Google Maps embed and shows only the directions link', function (string $embed): void {
    app(UpdateSettings::class)->handle([SettingKey::MapEmbedUrl->value => $embed]);

    expect(app(BusinessFacts::class)->mapEmbedUrl())->toBeNull();

    $this->get('/contact')->assertOk()
        ->assertSee('Where we ride.')
        ->assertDontSee('<iframe', false)
        ->assertDontSee('Show map')
        ->assertSee('href="'.MAP_LINK.'"', false);
})->with([
    'other host' => 'https://evil.example/maps/embed?pb=1',
    'google, not an embed' => 'https://www.google.com/maps/place/x',
    'empty' => '',
]);

it('hides the map card and the footer directions without a map link', function (): void {
    app(UpdateSettings::class)->handle([SettingKey::MapPinUrl->value => '']);

    $this->get('/contact')->assertOk()
        ->assertDontSee('Where we ride.')
        ->assertDontSee('Get directions')
        ->assertDontSee('<iframe', false);
});

it('puts the venue and the map link in llms.txt and the JSON-LD', function (): void {
    $this->get('/llms.txt')->assertOk()
        ->assertSee('Location: Yashwala Ranch, Islamabad. Map and directions: '.MAP_LINK, false)
        ->assertDontSee('pin is shared after');

    $this->get('/')->assertOk()
        ->assertSee('"hasMap":"'.MAP_LINK.'"', false)
        ->assertSee('"alternateName":"Yashwala Ranch"', false);
});

it('falls back to the default map links when .env has empty lines for them', function (): void {
    // Production's .env had `BOOKING_MAP_PIN_URL=`, which blanked the link (2026-11-14).
    $keys = ['BOOKING_MAP_PIN_URL', 'SITE_MAP_EMBED_URL'];

    foreach ($keys as $key) {
        putenv($key.'=');
        $_ENV[$key] = $_SERVER[$key] = '';
    }

    try {
        $defaults = (require config_path('settings.php'))['defaults'];
    } finally {
        foreach ($keys as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    expect($defaults['booking.map_pin_url'])->toStartWith('https://maps.app.goo.gl/')
        ->and($defaults['business.map_embed_url'])->toStartWith('https://www.google.com/maps/embed?');
});

it('fills the {venue} and {map_link} tokens', function (): void {
    expect(app(BusinessFacts::class)->interpolate('{venue}, {location}: {map_link}'))
        ->toBe('Yashwala Ranch, Islamabad: '.MAP_LINK);
});

it('rewrites only the shipped private-pin wording when the venue goes public', function (): void {
    $migration = require database_path('migrations/2026_11_25_100000_publish_venue_location.php');
    $migration->down();

    $faq = DB::table('faqs')->where('question', 'Where exactly are you?')->first();
    expect($faq?->answer)->toContain('We share the exact location pin');

    $edited = DB::table('faqs')->insertGetId(['question' => 'Custom', 'answer' => 'We share the exact location pin when we confirm your booking, call us.', 'topic' => 'On the day', 'is_published' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('settings')->updateOrInsert(['key' => 'booking.map_pin_url'], ['value' => '""', 'created_at' => now(), 'updated_at' => now()]);

    $migration->up();

    expect(DB::table('faqs')->where('id', $faq->id)->value('answer'))->toBe('We ride at {venue}, {location}. Directions are on our contact page and in your confirmation email.')
        ->and(DB::table('faqs')->where('id', $edited)->value('answer'))->toContain('call us')
        ->and(DB::table('settings')->where('key', 'booking.map_pin_url')->exists())->toBeFalse()
        ->and(DB::table('quick_replies')->where('body', 'like', '%exact location pin%')->exists())->toBeFalse();
});
