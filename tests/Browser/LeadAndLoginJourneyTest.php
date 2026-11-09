<?php

declare(strict_types=1);

use App\Enums\LeadInterest;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Mail\LoginCodeMail;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/*
 * End-to-end journeys for a visitor who isn't signed in (milestone 13), on a
 * phone: sending the contact form, and signing in with an emailed one-time
 * code (a wrong code first). Every step waits for its own result.
 */

it('sends the contact form and creates a new lead', function (): void {
    Notification::fake();

    $page = visit(route('contact'))->on()->mobile()
        ->assertSee('Your name');
    $browser = $page->page();
    $page->assertNoJavaScriptErrors()->assertNoConsoleLogs();

    $browser->locator('[name="name"]')->fill('Mehreen Aslam', ['timeout' => E2E_WAIT_MS]);
    $browser->locator('[name="email"]')->fill('mehreen@example.com', ['timeout' => E2E_WAIT_MS]);
    $browser->locator('[name="interest"]')->selectOption(LeadInterest::Package->value);
    $browser->locator('[name="message"]')->fill('Can my daughter and I share an 8-class package?', ['timeout' => E2E_WAIT_MS]);
    pressOnce($browser, 'Send message');

    waitForText($browser, 'Thanks, your message is on its way.');
    $page->assertNoJavaScriptErrors()->assertNoConsoleLogs();

    $lead = Lead::query()->sole();
    expect($lead->name)->toBe('Mehreen Aslam')
        ->and($lead->email)->toBe('mehreen@example.com')
        ->and($lead->source)->toBe(LeadSource::ContactForm)
        ->and($lead->interest)->toBe(LeadInterest::Package)
        ->and($lead->status)->toBe(LeadStatus::New);
});

it('signs a customer in with an emailed code, after a wrong one', function (): void {
    Mail::fake();
    $user = User::factory()->create(['email' => 'rider@example.com', 'name' => 'Kamran Iqbal']);

    $page = visit(route('login'))->on()->mobile()
        ->assertSee('Email me a code');
    $browser = $page->page();
    $page->assertNoJavaScriptErrors()->assertNoConsoleLogs();

    $browser->locator('[name="email"]')->fill('rider@example.com', ['timeout' => E2E_WAIT_MS]);
    pressOnce($browser, 'Email me a code');
    waitForText($browser, 'Your code');

    $mail = Mail::sent(LoginCodeMail::class)->last();
    expect($mail)->toBeInstanceOf(LoginCodeMail::class);
    $code = $mail->code;

    // A wrong code first: a clear error and still signed out.
    $browser->locator('[name="code"]')->fill($code === '000000' ? '111111' : '000000', ['timeout' => E2E_WAIT_MS]);
    pressOnce($browser, 'Sign in');
    waitForText($browser, 'That code isn’t right. You have 4 tries left.');
    expect(Auth::check())->toBeFalse();

    $browser->locator('[name="code"]')->fill($code, ['timeout' => E2E_WAIT_MS]);
    pressOnce($browser, 'Sign in');
    waitForText($browser, 'Hello, Kamran.');
    $page->assertNoJavaScriptErrors()->assertNoConsoleLogs();

    expect(Auth::id())->toBe($user->id);
});
