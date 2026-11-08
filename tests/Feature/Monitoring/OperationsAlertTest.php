<?php

declare(strict_types=1);

use App\Actions\Monitoring\CheckOperationalHealth;
use App\Enums\BankTransferStatus;
use App\Enums\OrderStatus;
use App\Enums\VoucherOrderStatus;
use App\Models\BankTransfer;
use App\Models\User;
use App\Models\VoucherOrder;
use App\Notifications\OperationsAlert;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00:00', 'UTC'));
});

/** A job row as the database queue writes it, due $minutesAgo minutes ago. */
function queuedJob(int $minutesAgo, ?int $reservedMinutesAgo = null): void
{
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => $reservedMinutesAgo === null ? null : now()->subMinutes($reservedMinutesAgo)->getTimestamp(),
        'available_at' => now()->subMinutes($minutesAgo)->getTimestamp(),
        'created_at' => now()->subMinutes($minutesAgo)->getTimestamp(),
    ]);
}

function voucherTransferSubmitted(CarbonImmutable $at): VoucherOrder
{
    return VoucherOrder::factory()->status(VoucherOrderStatus::AwaitingVerification)->create(['submitted_at' => $at]);
}

it('is scheduled every five minutes without overlapping', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'monitor:operations'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('counts only jobs that have been waiting over five minutes', function (): void {
    $check = app(CheckOperationalHealth::class);

    queuedJob(2);                           // fresh
    queuedJob(-30);                         // delayed: not due for 30 minutes
    queuedJob(20, reservedMinutesAgo: 1);   // being worked on
    queuedJob(5);                           // exactly 5 minutes: counts

    $problem = $check->queueProblem(CarbonImmutable::now());

    expect($problem)->not->toBeNull()
        ->and($problem['count'])->toBe(1)
        ->and($problem['oldest']->equalTo(now()->subMinutes(5)))->toBeTrue();

    DB::table('jobs')->where('available_at', now()->subMinutes(5)->getTimestamp())->delete();

    expect($check->queueProblem(CarbonImmutable::now()))->toBeNull();
});

it('counts order and voucher transfers waiting for review over 12 hours', function (): void {
    $check = app(CheckOperationalHealth::class);

    BankTransfer::factory()->create(['submitted_at' => now()->subHours(12)]);              // exactly 12 h: not yet
    voucherTransferSubmitted(CarbonImmutable::now()->subHours(11));                          // too recent
    BankTransfer::factory()->create(['submitted_at' => now()->subHours(30), 'status' => BankTransferStatus::Approved]); // reviewed

    expect($check->transferProblem(CarbonImmutable::now()))->toBeNull();

    $order = BankTransfer::factory()->create(['submitted_at' => now()->subHours(12)->subMinute()]);
    $voucher = voucherTransferSubmitted(CarbonImmutable::now()->subHours(20));

    $problem = $check->transferProblem(CarbonImmutable::now());

    expect($problem['count'])->toBe(2)
        ->and($problem['oldest']->equalTo($voucher->submitted_at))->toBeTrue();

    // An order that left awaiting_verification (e.g. cancelled) no longer counts.
    $order->order->forceFill(['status' => OrderStatus::Cancelled])->saveQuietly();

    expect($check->transferProblem(CarbonImmutable::now())['count'])->toBe(1);
});

it('emails every admin when the queue stalls, every 6 hours while it lasts, and once when it clears', function (): void {
    Notification::fake();
    $admins = User::factory()->admin()->count(2)->create();
    $staff = User::factory()->staff()->create();
    $anonymised = User::factory()->admin()->create(['anonymised_at' => now(), 'email' => 'deleted-9@anonymised.invalid']);

    queuedJob(10);

    $this->artisan('monitor:operations')->assertSuccessful();

    Notification::assertSentTo($admins, OperationsAlert::class, fn (OperationsAlert $alert): bool => $alert->problem === 'queue' && $alert->state === OperationsAlert::STARTED && $alert->count === 1);
    Notification::assertNotSentTo($staff, OperationsAlert::class);
    Notification::assertNotSentTo($anonymised, OperationsAlert::class);
    Notification::assertCount(2);

    // Still stuck 5 h 59 m later: nothing new.
    $this->travel(5 * 60 + 59)->minutes();
    $this->artisan('monitor:operations')->assertSuccessful();
    Notification::assertCount(2);

    // 6 hours after the first email: a reminder.
    $this->travel(1)->minutes();
    $this->artisan('monitor:operations')->assertSuccessful();
    Notification::assertCount(4);
    Notification::assertSentTo($admins[0], OperationsAlert::class, fn (OperationsAlert $alert): bool => $alert->state === OperationsAlert::REPEATED);

    // The queue drains: one all-clear, then silence.
    DB::table('jobs')->delete();
    $this->artisan('monitor:operations')->assertSuccessful();
    Notification::assertCount(6);
    Notification::assertSentTo($admins[1], OperationsAlert::class, fn (OperationsAlert $alert): bool => $alert->state === OperationsAlert::RESOLVED);

    $this->travel(1)->hours();
    $this->artisan('monitor:operations')->assertSuccessful();
    Notification::assertCount(6);
});

it('tracks the queue and transfers separately', function (): void {
    Notification::fake();
    User::factory()->admin()->create();
    BankTransfer::factory()->create(['submitted_at' => now()->subHours(13)]);

    expect(app(CheckOperationalHealth::class)->handle())->toBe(['queue' => 'none', 'transfers' => 'started']);

    queuedJob(6);

    expect(app(CheckOperationalHealth::class)->handle())->toBe(['queue' => 'started', 'transfers' => 'none']);
    Notification::assertCount(2);
});

it('sends the email straight away, even when the queue is the problem', function (): void {
    Queue::fake();
    User::factory()->admin()->create(['email' => 'owner@example.test']);
    queuedJob(10);

    expect(new OperationsAlert('queue', OperationsAlert::STARTED, 1, CarbonImmutable::now(), CarbonImmutable::now()))
        ->not->toBeInstanceOf(ShouldQueue::class);

    $this->artisan('monitor:operations')->assertSuccessful();

    Queue::assertNothingPushed();

    $messages = app('mailer')->getSymfonyTransport()->messages();

    expect($messages)->toHaveCount(1)
        ->and($messages->first()->getOriginalMessage()->getSubject())->toBe('Background jobs are stuck');
});

it('renders the alert emails', function (string $problem, string $state, string $subject, string $text): void {
    $admin = User::factory()->admin()->make();
    $alert = new OperationsAlert($problem, $state, 3, CarbonImmutable::parse('2026-11-14 04:00', 'UTC'), CarbonImmutable::parse('2026-11-14 03:00', 'UTC'));

    $mail = $alert->toMail($admin);
    $html = (string) $mail->render();

    expect($mail->subject)->toBe($subject)
        ->and($html)->toContain($text);
})->with([
    'queue started' => ['queue', OperationsAlert::STARTED, 'Background jobs are stuck', '3 background jobs have been waiting more than 5 minutes'],
    'queue repeated' => ['queue', OperationsAlert::REPEATED, 'Still: Background jobs are stuck', 'Sat 14 Nov 2026, 09:00'],
    'queue resolved' => ['queue', OperationsAlert::RESOLVED, 'All clear: the queue is running again', 'started at Sat 14 Nov 2026, 08:00'],
    'transfers started' => ['transfers', OperationsAlert::STARTED, 'Bank transfers waiting over 12 hours', 'Verify booking transfers'],
    'transfers resolved' => ['transfers', OperationsAlert::RESOLVED, 'All clear: no transfers waiting over 12 hours', 'No bank transfer'],
]);
