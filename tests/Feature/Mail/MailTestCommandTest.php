<?php

declare(strict_types=1);

use App\Mail\TestMail;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/MailTestHelpers.php';

it('sends the branded test email straight away', function (): void {
    Mail::fake();

    $this->artisan('mail:test', ['email' => 'hello@ride-pakistan.com'])
        ->expectsOutputToContain('Sending a test email to hello@ride-pakistan.com')
        ->expectsOutputToContain('Sent.')
        ->assertSuccessful();

    Mail::assertSent(TestMail::class, fn (TestMail $mail): bool => $mail->hasTo('hello@ride-pakistan.com'));
    Mail::assertNothingQueued();
});

it('renders the test email in the brand theme', function (): void {
    $html = (new TestMail(now()->toImmutable()))->render();

    expect($html)->toContain('Email is working')
        ->toContain('Pakistan time')
        ->toContain('Ride Pakistan');
});

it('refuses an invalid address', function (): void {
    Mail::fake();

    $this->artisan('mail:test', ['email' => 'not-an-email'])->assertExitCode(2);

    Mail::assertNothingSent();
});

it('reports the provider error without the addresses in it', function (): void {
    failingMailer();

    $this->artisan('mail:test', ['email' => 'hello@ride-pakistan.com'])
        ->expectsOutputToContain('Sending failed')
        ->expectsOutputToContain('Request to the Resend API failed. Reason: rejected sender for [address]')
        ->doesntExpectOutputToContain('customer@example.test')
        ->assertFailed();
});
