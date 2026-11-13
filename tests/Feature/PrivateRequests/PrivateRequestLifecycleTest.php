<?php

declare(strict_types=1);

use App\Actions\Booking\BookWithCredit;
use App\Actions\Booking\CreateOrder;
use App\Actions\Booking\FulfilOrder;
use App\Actions\Booking\ReleaseExpiredHolds;
use App\Actions\Orders\OrderStateMachine;
use App\Actions\Payments\ChooseBankTransfer;
use App\Actions\Payments\SubmitBankTransfer;
use App\Actions\PrivateRequests\ApprovePrivateRequest;
use App\Actions\PrivateRequests\DeclinePrivateRequest;
use App\Actions\PrivateRequests\ExpirePrivateRequests;
use App\Actions\PrivateRequests\SubmitPrivateRequest;
use App\Actions\PrivateRequests\WithdrawPrivateRequest;
use App\Actions\Settings\UpdateSettings;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\PrivateRequestStatus;
use App\Enums\SettingKey;
use App\Enums\SlotStatus;
use App\Exceptions\BookingRejected;
use App\Exceptions\PrivateRequestRejected;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\CreditPack;
use App\Models\Horse;
use App\Models\HorseUnavailability;
use App\Models\Order;
use App\Models\PrivateSessionRequest;
use App\Models\Product;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use App\Notifications\PrivateRequestApproved;
use App\Notifications\PrivateRequestDeclined;
use App\Notifications\PrivateRequestExpired;
use App\Notifications\PrivateRequestLapsed;
use App\Notifications\PrivateRequestReceived;
use App\Notifications\PrivateRequestToReview;
use App\Notifications\PrivateSessionAssigned;
use App\Support\ManageLink;
use App\Support\TransferProofs;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/PrivateRequestHelpers.php';

beforeEach(function (): void {
    Notification::fake();
    Storage::fake(TransferProofs::DISK);
});

describe('asking', function (): void {
    it('saves the request, answers within 2 hours, and emails the customer and every admin', function (): void {
        $admin = prAdmin();
        $user = prCustomer(2);
        $request = prRequest($user, prStart(3, '10:00'), note: 'Birthday ride');

        expect($request->status)->toBe(PrivateRequestStatus::Requested)
            ->and($request->reference)->toStartWith('RQ-')
            ->and($request->riders)->toBe(2)
            ->and($request->note)->toBe('Birthday ride')
            ->and((int) $request->ends_at->diffInMinutes($request->starts_at, true))->toBe(120)
            ->and($request->answer_by->equalTo(now()->toImmutable()->addHours(2)->startOfSecond()) || abs($request->answer_by->diffInSeconds(now()->addHours(2))) < 5)->toBeTrue()
            ->and(Slot::query()->count())->toBe(0)
            ->and(Order::query()->count())->toBe(0);

        Notification::assertSentTo($user, PrivateRequestReceived::class);
        Notification::assertSentTo($admin, PrivateRequestToReview::class, function (PrivateRequestToReview $n) use ($admin): bool {
            $html = (string) $n->toMail($admin)->render();

            return str_contains($html, 'action=approve') && str_contains($html, 'action=decline') && str_contains($html, 'Answer by');
        });
    });

    it('refuses times outside 07:00–18:00 Pakistan time, off the quarter hour, or under 48 hours ahead', function (string $time, int $days, string $message): void {
        expect(fn () => prRequest(start: prStart($days, $time)))->toThrow(PrivateRequestRejected::class, $message);
    })->with([
        'starts before 07:00' => ['06:45', 3, 'start between 07:00 and 16:00'],
        'ends after 18:00' => ['16:15', 3, 'start between 07:00 and 16:00'],
        'not a quarter hour' => ['10:10', 3, 'quarter hour'],
        'too soon' => ['10:00', 1, '48 hours ahead'],
    ]);

    it('accepts the first and last allowed starts', function (): void {
        expect(prRequest(start: prStart(3, '07:00'))->status)->toBe(PrivateRequestStatus::Requested)
            ->and(prRequest(start: prStart(4, '16:00'))->status)->toBe(PrivateRequestStatus::Requested);
    });

    it('follows the admin settings', function (): void {
        app(UpdateSettings::class)->handle([
            SettingKey::PrivateLatestEndHour->value => 20,
            SettingKey::PrivateMinNoticeHours->value => 24,
        ]);

        expect(prRequest(start: prStart(2, '18:00'))->status)->toBe(PrivateRequestStatus::Requested);
    });

    it('refuses a time that clashes with a session on the schedule', function (): void {
        Slot::factory()->at(prStart(3, '07:00')->setTimezone((string) config('app.display_timezone')))->create(); // 07:00–09:00

        expect(fn () => prRequest(start: prStart(3, '09:15')))->toThrow(PrivateRequestRejected::class, 'isn’t free');
        expect(prRequest(start: prStart(3, '09:30'))->status)->toBe(PrivateRequestStatus::Requested);
    });

    it('allows one open request per customer', function (): void {
        $user = prCustomer();
        prRequest($user);

        expect(fn () => prRequest($user, prStart(4)))->toThrow(PrivateRequestRejected::class, 'already have');
    });

    it('needs a WhatsApp number, own riders, a customer account and a private product on sale', function (): void {
        $noWhatsApp = User::factory()->withoutWhatsApp()->create();
        Rider::factory()->for($noWhatsApp)->create();
        expect(fn () => prRequest($noWhatsApp))->toThrow(PrivateRequestRejected::class, 'WhatsApp');

        $user = prCustomer();
        $other = Rider::factory()->create();
        expect(fn () => app(SubmitPrivateRequest::class)->handle($user, prProduct(), prStart(), [$other->id]))
            ->toThrow(PrivateRequestRejected::class, 'riders from your account');

        $staff = User::factory()->staff()->create();
        Rider::factory()->for($staff)->create();
        expect(fn () => prRequest($staff))->toThrow(PrivateRequestRejected::class, 'Team accounts');

        prProduct()->forceFill(['is_active' => false])->save();
        expect(fn () => prRequest())->toThrow(PrivateRequestRejected::class, 'can’t be requested');
    });

    it('caps the riders at the riders-per-slot setting', function (): void {
        app(UpdateSettings::class)->handle([SettingKey::RidersPerSlot->value => 2]);

        expect(fn () => prRequest(riders: 3))->toThrow(PrivateRequestRejected::class, 'Choose 1 to 2 riders');
    });
});

describe('answer window', function (): void {
    it('expires unanswered requests at exactly 2 hours and tells the customer once', function (): void {
        $request = prRequest();

        $this->travelTo($request->answer_by->subMinute());
        expect(app(ExpirePrivateRequests::class)->handle())->toBe(0)
            ->and($request->fresh()?->status)->toBe(PrivateRequestStatus::Requested);

        $this->travelTo($request->answer_by);
        expect(app(ExpirePrivateRequests::class)->handle())->toBe(1)
            ->and($request->fresh()?->status)->toBe(PrivateRequestStatus::Expired)
            ->and(app(ExpirePrivateRequests::class)->handle())->toBe(0);

        Notification::assertSentToTimes($request->user, PrivateRequestExpired::class, 1);
    });

    it('runs from the scheduler command', function (): void {
        $request = prRequest();
        $this->travelTo($request->answer_by->addMinute());

        $this->artisan('private-requests:expire')->expectsOutput('Expired 1 request.')->assertSuccessful();
    });

    it('can no longer be approved once the answer time has passed', function (): void {
        $request = prRequest();
        $this->travelTo($request->answer_by);

        expect(fn () => prApprove($request))->toThrow(PrivateRequestRejected::class, 'answer time');
    });
});

describe('approving', function (): void {
    it('creates a private-only slot, a private hold and an order priced from the product', function (): void {
        $trainer = User::factory()->trainer()->create();
        $request = prApprove(prRequest(riders: 2), $trainer);

        $slot = Slot::query()->sole();
        $order = Order::query()->sole();
        $hold = BookingHold::query()->sole();

        expect($request->status)->toBe(PrivateRequestStatus::Approved)
            ->and($request->slot_id)->toBe($slot->id)
            ->and($request->order_id)->toBe($order->id)
            ->and($request->trainer_id)->toBe($trainer->id)
            ->and($slot->private_only)->toBeTrue()
            ->and($slot->isManual())->toBeTrue()
            ->and($slot->starts_at->equalTo($request->starts_at))->toBeTrue()
            ->and($slot->trainer_id)->toBe($trainer->id)
            ->and($order->status)->toBe(OrderStatus::PendingPayment)
            ->and($order->amount_minor)->toBe(2 * prProduct()->price_minor)
            ->and($order->product_name)->toBe(prProduct()->name)
            ->and($order->meta['private_request_id'])->toBe($request->id)
            ->and($hold->is_private)->toBeTrue()
            ->and($hold->expires_at->equalTo($request->pay_by))->toBeTrue()
            ->and($order->expires_at?->equalTo($request->pay_by))->toBeTrue()
            ->and(abs($request->pay_by->diffInSeconds(now()->addHours(2))))->toBeLessThan(5)
            ->and(Activity::query()->where('log_name', 'private_requests')->where('event', 'status_changed')->count())->toBe(1);

        Notification::assertSentTo($request->user, PrivateRequestApproved::class, function (PrivateRequestApproved $n) use ($request, $order): bool {
            $html = html_entity_decode((string) $n->toMail($request->user)->render());

            // Pay now opens the order's manage link (guest checkout), which works signed in or not.
            return str_contains($html, ManageLink::forOrder($order)) && str_contains($html, 'PKR');
        });
    });

    it('blocks every other booking path on the approved slot', function (): void {
        prApprove(prRequest());
        $slot = Slot::query()->sole();

        $other = prCustomer();
        expect(fn () => app(CreateOrder::class)->handle($other, Product::factory()->create(), $slot->id, [$other->riders()->value('id')]))->toThrow(BookingRejected::class);
        expect(fn () => app(CreateOrder::class)->handle($other, prProduct(), $slot->id, [$other->riders()->value('id')]))->toThrow(BookingRejected::class);

        CreditPack::factory()->create(['order_id' => Order::factory()->for($other)->status(OrderStatus::Paid), 'expires_at' => now()->addWeeks(4)]);
        expect(fn () => app(BookWithCredit::class)->handle($other, $slot->id, [$other->riders()->value('id')]))->toThrow(BookingRejected::class);
    });

    it('refuses a time that now overlaps another session (buffer included) and creates nothing', function (string $other): void {
        $request = prRequest(start: prStart(3, '10:00')); // 10:00–12:00
        Slot::factory()->at(prStart(3, $other)->setTimezone((string) config('app.display_timezone')))->create();

        expect(fn () => prApprove($request))->toThrow(PrivateRequestRejected::class, 'too close to another session');
        expect($request->fresh()?->status)->toBe(PrivateRequestStatus::Requested)
            ->and(Order::query()->count())->toBe(0)
            ->and(Slot::query()->count())->toBe(1);
    })->with([
        'overlapping' => ['11:00'],
        'within 30 minutes after' => ['12:15'],
        'within 30 minutes before' => ['07:45'], // 07:45–09:45
    ]);

    it('approves a time exactly 30 minutes clear of another session', function (): void {
        $request = prRequest(start: prStart(3, '10:00'));
        Slot::factory()->at(prStart(3, '12:30')->setTimezone((string) config('app.display_timezone')))->create();

        expect(prApprove($request)->status)->toBe(PrivateRequestStatus::Approved);
    });

    it('refuses when the horses can’t take the riders that day', function (): void {
        $request = prRequest(riders: 2, start: prStart(3, '10:00'));
        Horse::factory()->create(['max_sessions_per_day' => 2]);
        $resting = Horse::factory()->create(['max_sessions_per_day' => 2]);
        HorseUnavailability::factory()->create(['horse_id' => $resting->id, 'date' => $request->starts_at->setTimezone((string) config('app.display_timezone'))->toDateString()]);
        Booking::factory()->create(['slot_id' => Slot::factory()->at(prStart(3, '15:00')->setTimezone((string) config('app.display_timezone')))->create()->id]);

        expect(fn () => prApprove($request))->toThrow(PrivateRequestRejected::class, 'Not enough horses');
    });

    it('is for admins only', function (): void {
        $request = prRequest();

        expect(fn () => app(ApprovePrivateRequest::class)->handle($request, User::factory()->staff()->create()))
            ->toThrow(PrivateRequestRejected::class, 'Only admins');
    });
});

describe('declining and withdrawing', function (): void {
    it('declines with a reason and suggested times and emails the customer', function (): void {
        $request = prRequest();

        expect(fn () => app(DeclinePrivateRequest::class)->handle($request, prAdmin(), '  '))->toThrow(PrivateRequestRejected::class, 'reason');

        app(DeclinePrivateRequest::class)->handle($request, prAdmin(), 'Horses are resting that day', 'Sunday 10:00');

        expect($request->fresh()?->status)->toBe(PrivateRequestStatus::Declined)
            ->and($request->fresh()?->decline_reason)->toBe('Horses are resting that day')
            ->and($request->fresh()?->suggested_times)->toBe('Sunday 10:00');

        Notification::assertSentTo($request->user, PrivateRequestDeclined::class, fn (PrivateRequestDeclined $n): bool => str_contains((string) $n->toMail($request->user)->render(), 'Sunday 10:00'));
        expect(fn () => prApprove($request->fresh()))->toThrow(PrivateRequestRejected::class);
    });

    it('lets the customer withdraw while waiting, and only their own', function (): void {
        $request = prRequest();

        expect(fn () => app(WithdrawPrivateRequest::class)->handle($request, prCustomer()))->toThrow(PrivateRequestRejected::class);

        app(WithdrawPrivateRequest::class)->handle($request, $request->user);

        expect($request->fresh()?->status)->toBe(PrivateRequestStatus::Withdrawn)
            ->and(prRequest($request->user, prStart(4))->status)->toBe(PrivateRequestStatus::Requested);
    });
});

describe('payment window', function (): void {
    it('lapses an unpaid approval at exactly 2 hours, releases the slot and tells the customer once', function (): void {
        $request = prApprove(prRequest());
        $slot = Slot::query()->sole();

        $this->travelTo($request->pay_by->subMinute());
        app(ReleaseExpiredHolds::class)->handle();
        expect($request->fresh()?->status)->toBe(PrivateRequestStatus::Approved);

        $this->travelTo($request->pay_by);
        app(ReleaseExpiredHolds::class)->handle();
        app(ReleaseExpiredHolds::class)->handle();

        expect($request->fresh()?->status)->toBe(PrivateRequestStatus::Lapsed)
            ->and($request->order?->fresh()?->status)->toBe(OrderStatus::Expired)
            ->and($slot->fresh()?->status)->toBe(SlotStatus::Cancelled);

        Notification::assertSentToTimes($request->user, PrivateRequestLapsed::class, 1);

        // The time is free again for a new request (the cancelled slot no longer clashes).
        expect(prRequest(start: prStart(3, '10:30'))->status)->toBe(PrivateRequestStatus::Requested);
    });

    it('caps a bank transfer deadline at the payment window and lapses an unproven transfer', function (): void {
        $request = prApprove(prRequest());
        $order = app(ChooseBankTransfer::class)->handle($request->order);

        expect($order->status)->toBe(OrderStatus::AwaitingTransfer)
            ->and($order->expires_at?->equalTo($request->pay_by))->toBeTrue();

        $this->travelTo($request->pay_by->addMinute());
        app(ReleaseExpiredHolds::class)->handle();

        expect($request->fresh()?->status)->toBe(PrivateRequestStatus::Lapsed);
    });

    it('keeps an uploaded transfer waiting for review past the window', function (): void {
        $request = prApprove(prRequest());
        $order = app(ChooseBankTransfer::class)->handle($request->order);
        app(SubmitBankTransfer::class)->handle($order->refresh(), new UploadedFile(prProofFile(), 'proof.jpg', null, null, true), 'TXN-1');

        $this->travelTo($request->pay_by->addHours(3));
        app(ReleaseExpiredHolds::class)->handle();

        expect($order->fresh()?->status)->toBe(OrderStatus::AwaitingVerification)
            ->and($request->fresh()?->status)->toBe(PrivateRequestStatus::Approved)
            ->and($request->fresh()?->statusLine())->toBe('Approved: transfer received, being checked');
    });

    it('books through FulfilOrder when paid and emails the trainer', function (): void {
        $trainer = User::factory()->trainer()->create();
        $request = prApprove(prRequest(riders: 2), $trainer);

        app(FulfilOrder::class)->handle($request->order);

        $bookings = Booking::query()->where('order_id', $request->order_id)->get();

        expect($request->fresh()?->status)->toBe(PrivateRequestStatus::Paid)
            ->and($bookings)->toHaveCount(2)
            ->and($bookings->every(fn (Booking $b): bool => $b->is_private && $b->status === BookingStatus::Confirmed))->toBeTrue();

        Notification::assertSentTo($trainer, PrivateSessionAssigned::class);
    });

    it('lapses when an admin rejects the transfer', function (): void {
        $request = prApprove(prRequest());
        $order = app(ChooseBankTransfer::class)->handle($request->order);
        app(SubmitBankTransfer::class)->handle($order->refresh(), new UploadedFile(prProofFile(), 'proof.jpg', null, null, true), null);

        app(OrderStateMachine::class)->transition($order->refresh(), OrderStatus::Rejected, prAdmin(), 'Amount missing');

        expect($request->fresh()?->status)->toBe(PrivateRequestStatus::Lapsed);
        Notification::assertSentTo($request->user, PrivateRequestLapsed::class, fn (PrivateRequestLapsed $n): bool => str_contains((string) $n->toMail($request->user)->render(), 'couldn\'t confirm the bank transfer'));
    });
});

it('leaves private sessions on existing admin slots as they are: no request, straight to payment', function (): void {
    $slot = nearSlot(5, 8, ['private_only' => true]);
    $user = prCustomer();

    $order = app(CreateOrder::class)->handle($user, prProduct(), $slot->id, [$user->riders()->value('id')]);

    expect($order->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->meta['private_request_id'] ?? null)->toBeNull()
        ->and(PrivateSessionRequest::query()->count())->toBe(0);
});
