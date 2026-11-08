<?php

declare(strict_types=1);

use App\Actions\Mail\ReportMailFailure;
use App\Auth\LoginCodes\EmailLoginCodeChannel;
use App\Mail\LoginCodeMail;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\CustomerNotification;
use App\Notifications\MailDeliveryFailing;
use App\Notifications\NewLeadReceived;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportException;

require_once __DIR__.'/MailTestHelpers.php';

/** A queue job as the worker sees it, for a queued email. */
function mailJob(string $command = SendQueuedNotifications::class, string $name = NewLeadReceived::class): Job
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn(['data' => ['commandName' => $command]]);
    $job->shouldReceive('resolveName')->andReturn($name);
    $job->shouldReceive('uuid')->andReturn('3b1e2c9a-uuid');
    $job->shouldReceive('attempts')->andReturn(5);

    return $job;
}

function failMailJob(?Job $job = null): void
{
    event(new JobFailed('database', $job ?? mailJob(), new TransportException('Resend said no to someone@example.test')));
}

it('retries queued emails with backoff', function (): void {
    $notification = new NewLeadReceived(Lead::factory()->make());

    expect($notification)->toBeInstanceOf(CustomerNotification::class)
        ->and($notification->tries)->toBe(5)
        ->and($notification->backoff())->toBe([60, 300, 900, 3600]);

    $job = new SendQueuedNotifications(collect([User::factory()->make()]), $notification, ['mail']);

    expect($job->tries)->toBe(5)
        ->and($job->backoff())->toBe([60, 300, 900, 3600]);
});

it('emails every admin when an email fails for good, at most once an hour', function (): void {
    Notification::fake();
    $admins = User::factory()->admin()->count(2)->create();
    $staff = User::factory()->staff()->create();

    failMailJob();

    Notification::assertSentTo($admins, MailDeliveryFailing::class, fn (MailDeliveryFailing $alert): bool => $alert->kind === 'NewLeadReceived'
        && $alert->error === 'Resend said no to [address]');
    Notification::assertNotSentTo($staff, MailDeliveryFailing::class);
    Notification::assertCount(2);

    // More failures within the hour: logged, no more emails.
    $this->travel(59)->minutes();
    failMailJob();
    failMailJob(mailJob(SendQueuedMailable::class, LoginCodeMail::class));
    Notification::assertCount(2);

    $this->travel(2)->minutes();
    failMailJob();
    Notification::assertCount(4);
});

it('ignores jobs that are not emails', function (): void {
    Notification::fake();
    User::factory()->admin()->create();

    failMailJob(mailJob('App\\Jobs\\SomethingElse', 'App\\Jobs\\SomethingElse'));

    Notification::assertNothingSent();
});

it('logs a failure without the email or the addresses in it', function (): void {
    Notification::fake();
    Log::spy();

    failMailJob();

    Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
        return $message === 'Email delivery failed'
            && $context === [
                'email' => 'NewLeadReceived',
                'error' => 'Resend said no to [address]',
                'exception' => TransportException::class,
                'job_id' => '3b1e2c9a-uuid',
                'attempts' => 5,
            ];
    });
});

it('reports real queued sends that fail, and logs it when the alert fails too', function (): void {
    $admin = User::factory()->admin()->create(['email' => 'owner@example.test']);
    failingMailer();
    Log::spy();

    // The sync queue in tests runs the job at once and fails it like a worker would.
    expect(fn () => $admin->notify(new NewLeadReceived(Lead::factory()->create(['name' => 'Secret Name']))))
        ->toThrow(TransportException::class);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'Email delivery attempt failed' && $context['email'] === 'NewLeadReceived');
    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $message === 'Email delivery failed'
        && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'Secret Name')
        && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'customer@example.test'));
    // The provider is down for the alert as well: only the log has it.
    Log::shouldHaveReceived('critical')->withArgs(fn (string $message, array $context): bool => $message === 'Email failure alert could not be sent' && $context['admin_id'] === $admin->id);
});

it('delivers the alert when the provider still accepts mail to the admin', function (): void {
    User::factory()->admin()->create(['email' => 'owner@example.test']);
    failingMailer(except: ['owner@example.test']);
    $subjects = [];
    Event::listen(MessageSent::class, function (MessageSent $event) use (&$subjects): void {
        $subjects[] = $event->message->getSubject();
    });

    failMailJob();

    expect($subjects)->toBe(['Emails are failing to send']);
});

it('renders the alert email', function (): void {
    $html = (string) (new MailDeliveryFailing('OrderConfirmed', 'Request to the Resend API failed', CarbonImmutable::parse('2026-11-14 04:00', 'UTC')))
        ->toMail(User::factory()->admin()->make())
        ->render();

    expect($html)->toContain('OrderConfirmed')
        ->toContain('Sat 14 Nov 2026, 09:00')
        ->toContain('100 emails a day')
        ->toContain('at most one of these emails every 60 minutes');
});

describe('sign-in codes', function (): void {
    it('are sent straight away', function (): void {
        Queue::fake();

        app(EmailLoginCodeChannel::class)->send('customer@example.test', '482913', CarbonImmutable::now()->addMinutes(10));

        expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(1);
        Queue::assertNothingPushed();
    });

    it('are queued, encrypted, for a retry when the provider fails, and the code is never logged', function (): void {
        Queue::fake();
        Notification::fake();
        Log::spy();
        failingMailer();

        app(EmailLoginCodeChannel::class)->send('customer@example.test', '482913', CarbonImmutable::now()->addMinutes(10));

        Queue::assertPushed(SendQueuedMailable::class, fn (SendQueuedMailable $job): bool => $job->mailable instanceof LoginCodeMail
            && $job->mailable->code === '482913'
            && $job->shouldBeEncrypted === true
            && $job->tries === 3
            && $job->backoff() === [30, 120]);

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'Email send failed, queued for retry'
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), '482913'));
        // A retry is on its way: no alert yet.
        Notification::assertNothingSent();
    });

    it('alert the admins when not even the retry can be queued', function (): void {
        Notification::fake();
        User::factory()->admin()->create();
        failingMailer();
        Queue::shouldReceive('connection')->andThrow(new RuntimeException('queue database down'));

        expect(fn () => app(EmailLoginCodeChannel::class)->send('customer@example.test', '482913', CarbonImmutable::now()->addMinutes(10)))
            ->toThrow(RuntimeException::class, 'queue database down');

        Notification::assertSentTo(User::query()->where('role', 'admin')->get(), MailDeliveryFailing::class, fn (MailDeliveryFailing $alert): bool => $alert->kind === 'LoginCodeMail');
    });
});

it('throttles with an atomic cache key', function (): void {
    expect(ReportMailFailure::ALERT_CACHE_KEY)->toBe('mail-failure-alert')
        ->and(ReportMailFailure::ALERT_EVERY_MINUTES)->toBe(60);
});
