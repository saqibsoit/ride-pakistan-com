<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('has a resend mailer and keeps log as the default outside the servers', function (): void {
    expect(config('mail.mailers.resend.transport'))->toBe('resend')
        // Tests use the in-memory array mailer (phpunit.xml); nothing is sent.
        ->and(config('mail.default'))->toBe('array');

    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)->toMatch('/^MAIL_MAILER=log$/m')
        ->toMatch('/^RESEND_API_KEY=$/m')
        ->toMatch('/^MAIL_FROM_ADDRESS="bookings@mail\.ride-pakistan\.com"$/m')
        ->toMatch('/^MAIL_REPLY_TO_ADDRESS="hello@ride-pakistan\.com"$/m');
});

it('builds the Resend transport from RESEND_API_KEY', function (): void {
    config(['resend.api_key' => 're_test_not_a_real_key']);

    $transport = app('mail.manager')->mailer('resend')->getSymfonyTransport();

    expect((string) $transport)->toBe('resend');
});

it('sends from the verified subdomain with replies to the team inbox', function (): void {
    expect(config('mail.from'))->toBe(['address' => 'bookings@mail.ride-pakistan.com', 'name' => 'Ride Pakistan'])
        ->and(config('mail.reply_to'))->toBe(['address' => 'hello@ride-pakistan.com', 'name' => 'Ride Pakistan']);
});

it('keeps the Resend webhook route off', function (): void {
    expect(config('resend.routes'))->toBeFalse()
        ->and(Route::has('resend.webhook'))->toBeFalse();
});

it('never puts a Resend key in a committed file', function (): void {
    foreach (['.env.example', 'config/mail.php', 'config/resend.php', 'config/services.php', 'docs/DEPLOY.md'] as $file) {
        expect((string) file_get_contents(base_path($file)))->not->toMatch('/re_[A-Za-z0-9]{8,}_[A-Za-z0-9]{8,}/');
    }
});
