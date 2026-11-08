<?php

declare(strict_types=1);

use App\Mail\LoginCodeMail;
use App\Mail\TestMail;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\MailDeliveryFailing;
use App\Notifications\NewLeadReceived;
use App\Notifications\OperationsAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Mime\Address;

/** @return list<string> Reply-To addresses of every message the array mailer holds */
function sentReplyTos(): array
{
    return app('mailer')->getSymfonyTransport()->messages()
        ->map(fn ($sent): array => array_map(fn (Address $address): string => $address->toString(), $sent->getOriginalMessage()->getReplyTo()))
        ->all();
}

it('adds the reply-to to mailables, queued notifications and team alerts', function (): void {
    $admin = User::factory()->admin()->create();

    Mail::to('customer@example.test')->send(new LoginCodeMail('123456', CarbonImmutable::now()->addMinutes(10)));
    Mail::to('customer@example.test')->send(new TestMail(CarbonImmutable::now()));
    $admin->notify(new NewLeadReceived(Lead::factory()->create()));   // a queued CustomerNotification (sync queue in tests)
    $admin->notifyNow(new OperationsAlert('queue', OperationsAlert::STARTED, 1, CarbonImmutable::now(), CarbonImmutable::now()));
    $admin->notifyNow(new MailDeliveryFailing('OrderConfirmed', 'boom', CarbonImmutable::now()));

    $replyTos = sentReplyTos();

    expect($replyTos)->toHaveCount(5)
        ->each->toBe(['"Ride Pakistan" <hello@ride-pakistan.com>']);
});

it('sends every message from the verified sender', function (): void {
    Mail::to('customer@example.test')->send(new TestMail(CarbonImmutable::now()));

    $from = app('mailer')->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getFrom();

    expect($from[0]->getAddress())->toBe('bookings@mail.ride-pakistan.com')
        ->and($from[0]->getName())->toBe('Ride Pakistan');
});

it('has no email that sets its own reply-to or sender', function (): void {
    // The mailer adds them to every message; a per-message value would
    // replace them for that email only.
    $offenders = collect(Finder::create()->files()->in([app_path(), resource_path('views')])->name(['*.php']))
        // Only files that build email: query builders also have from().
        ->filter(fn ($file): bool => (bool) preg_match('/MailMessage|Mailable|Envelope|Mail::|x-mail::/', $file->getContents()))
        ->filter(fn ($file): bool => (bool) preg_match('/->(replyTo|from)\(|replyTo:\s|from:\s/', $file->getContents()))
        ->map(fn ($file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
