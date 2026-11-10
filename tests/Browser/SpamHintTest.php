<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;

/*
 * Prompt 14: the login code screen tells people to check spam and
 * promotions, visibly, in a real browser.
 */

it('shows the spam and promotions hint on the login code screen', function (): void {
    Mail::fake();

    $page = visit(route('login'))->on()->mobile()->assertSee('Email me a code');
    $browser = $page->page();

    $browser->locator('[name="email"]')->fill('rider@example.com', ['timeout' => E2E_WAIT_MS]);
    pressOnce($browser, 'Email me a code');
    waitForText($browser, 'Your code');

    $hint = $browser->locator('[data-spam-hint]');
    expect($hint->isVisible())->toBeTrue()
        ->and($hint->textContent())->toContain('Check your spam or promotions folder.');

    $page->assertNoJavaScriptErrors();
});
