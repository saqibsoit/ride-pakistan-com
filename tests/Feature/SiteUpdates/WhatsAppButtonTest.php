<?php

declare(strict_types=1);

use App\Actions\Settings\UpdateSettings;
use App\Enums\SettingKey;
use App\Models\Slot;
use App\Models\User;
use App\Support\WhatsAppLink;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->seed();
    $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00', 'Asia/Karachi'));
});

const WHATSAPP_HREF = 'https://wa.me/923344554500?text=Hi%20Ride%20Pakistan%2C%20I%E2%80%99d%20like%20to%20ask%20about%20riding%20sessions.';

it('shows the WhatsApp button on public pages, linked from the settings', function (string $url): void {
    $html = $this->get($url)->assertOk()->getContent();

    expect($html)->toContain('data-whatsapp-button')
        ->and($html)->toContain('href="'.e(WHATSAPP_HREF).'"')
        ->and($html)->toContain('aria-label="Chat with us on WhatsApp (opens in a new tab)"')
        ->and($html)->toContain('data-track="contact"')
        ->and($html)->toContain('rel="noopener"');
})->with(['/', '/sessions/single-session', '/faq', '/prices', '/schedule', '/contact', '/login']);

it('also shows it on customer account pages', function (): void {
    $this->actingAs(User::factory()->create())->get('/account')->assertOk()->assertSee('data-whatsapp-button', false);
});

it('leaves it off team work screens', function (): void {
    $staff = User::factory()->staff()->withMfa()->create();
    $slot = Slot::query()->orderBy('starts_at')->firstOrFail();

    foreach ([route('team.sessions.index'), route('team.sessions.show', $slot)] as $url) {
        $this->actingAs($staff)->get($url)->assertOk()->assertDontSee('data-whatsapp-button', false);
    }
});

it('uses the number and message saved in the settings, digits only', function (): void {
    app(UpdateSettings::class)->handle([
        SettingKey::WhatsAppNumber->value => '+44 7700 900-123',
        SettingKey::WhatsAppMessage->value => 'Hello & welcome?',
    ]);

    expect(app(WhatsAppLink::class)->contact())->toBe('https://wa.me/447700900123?text=Hello%20%26%20welcome%3F');
    $this->get('/faq')->assertSee('href="https://wa.me/447700900123?text=Hello%20%26%20welcome%3F"', false);
});

it('hides the button while no number is set', function (): void {
    app(UpdateSettings::class)->handle([SettingKey::WhatsAppNumber->value => '']);

    $this->get('/faq')->assertOk()->assertDontSee('data-whatsapp-button', false);
});

it('adds nothing to the content security policy', function (): void {
    $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

    expect($csp)->not->toContain('wa.me')->not->toContain('whatsapp');
});

it('builds wa.me links for customer numbers', function (): void {
    expect(WhatsAppLink::to('+923001234567'))->toBe('https://wa.me/923001234567')
        ->and(WhatsAppLink::to(null))->toBeNull()
        ->and(WhatsAppLink::to('12'))->toBeNull();
});
