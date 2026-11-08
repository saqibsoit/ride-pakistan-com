<?php

declare(strict_types=1);

use App\Actions\Booking\CreateOrder;
use App\Actions\Payments\ApproveBankTransfer;
use App\Actions\Payments\ChooseBankTransfer;
use App\Actions\Payments\ExtendTransferHold;
use App\Actions\Payments\PruneTransferProofs;
use App\Actions\Payments\RejectBankTransfer;
use App\Actions\Payments\SubmitBankTransfer;
use App\Actions\Settings\UpdateSettings;
use App\Enums\BankTransferStatus;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\SettingKey;
use App\Exceptions\BookingRejected;
use App\Filament\Pages\VerifyTransfers;
use App\Filament\Widgets\TransfersWaiting;
use App\Http\Controllers\TransferProofController;
use App\Models\BankTransfer;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use App\Notifications\OrderConfirmed;
use App\Notifications\TransferRejected;
use App\Support\TransferProofs;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * Minutes added to the next transfer's slot time (4 days ahead).
 * Counts up from 1 in call order and is reset before every test, so no
 * two slots in a test share a start time (slots.starts_at is unique).
 * Replaces a random value that could repeat.
 */
function atSlotMinute(bool $reset = false): int
{
    static $n = 0;

    if ($reset) {
        $n = 0;

        return $n;
    }

    return ++$n;
}

/** An order with a transfer proof uploaded, waiting for review. */
function uploadedTransfer(int $riders = 2): BankTransfer
{
    $user = User::factory()->create();
    $riderIds = array_values(array_map('intval', Rider::factory()->count($riders)->for($user)->create()->modelKeys()));
    $slot = Slot::factory()->at(CarbonImmutable::now()->addDays(4)->addMinutes(atSlotMinute()))->create(['capacity' => 6]);
    $order = app(CreateOrder::class)->handle($user, Product::factory()->create(['price_minor' => 1_000_000]), $slot->id, $riderIds);
    app(ChooseBankTransfer::class)->handle($order);

    $image = imagecreatetruecolor(60, 40);
    ob_start();
    imagejpeg($image);
    $path = (string) tempnam(sys_get_temp_dir(), 'proof');
    file_put_contents($path, (string) ob_get_clean());

    return app(SubmitBankTransfer::class)->handle($order->refresh(), new UploadedFile($path, 'proof.jpg', null, null, true), 'TXN-42');
}

function transferAdmin(): User
{
    return User::factory()->admin()->withMfa()->create();
}

beforeEach(function (): void {
    atSlotMinute(reset: true);
    Storage::fake(TransferProofs::DISK);
    Notification::fake();
});

it('approves through FulfilOrder exactly once and records the verifier', function (): void {
    $transfer = uploadedTransfer();
    $admin = transferAdmin();
    $action = app(ApproveBankTransfer::class);

    expect($action->handle($transfer, $admin))->toBeTrue()
        ->and($action->handle($transfer->refresh(), $admin))->toBeFalse();

    $order = $transfer->order->refresh();
    $transfer->refresh();

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and(Booking::query()->where('order_id', $order->id)->where('status', BookingStatus::Confirmed)->count())->toBe(2)
        ->and(BookingHold::query()->where('order_id', $order->id)->exists())->toBeFalse()
        ->and(Payment::query()->where('order_id', $order->id)->sole()->provider)->toBe(PaymentProvider::BankTransfer)
        ->and(Payment::query()->where('order_id', $order->id)->sole()->provider_id)->toBe('TXN-42')
        ->and($transfer->status)->toBe(BankTransferStatus::Approved)
        ->and($transfer->verified_by)->toBe($admin->id)
        ->and($transfer->verified_at)->not->toBeNull()
        ->and(Activity::query()->where('event', 'fulfilled')->where('subject_id', $order->id)->sole()->properties['bank_transfer_id'])->toBe($transfer->id);

    Notification::assertSentTimes(OrderConfirmed::class, 1);
});

it('approves from the Verify transfers page', function (): void {
    $transfer = uploadedTransfer();
    $this->actingAs(transferAdmin());

    Livewire::test(VerifyTransfers::class)
        ->assertCanSeeTableRecords([$transfer->order])
        ->callAction(TestAction::make('approve')->table($transfer->order))
        ->assertNotified('Approved: '.$transfer->order->reference.' is confirmed');

    expect($transfer->order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('shows the admin an error and changes nothing when the seats are gone', function (): void {
    $transfer = uploadedTransfer();
    $slot = Slot::query()->findOrFail((int) $transfer->order->items()->value('slot_id'));
    $slot->update(['starts_at' => now()->subMinute(), 'ends_at' => now()->addHour()]); // holds ran out at the start
    BookingHold::query()->where('order_id', $transfer->order_id)->update(['expires_at' => now()->subMinute()]);
    Booking::factory()->count($slot->capacity)->create(['slot_id' => $slot->id]);

    expect(fn () => app(ApproveBankTransfer::class)->handle($transfer, transferAdmin()))->toThrow(BookingRejected::class);

    expect($transfer->refresh()->status)->toBe(BankTransferStatus::Submitted)
        ->and($transfer->order->refresh()->status)->toBe(OrderStatus::AwaitingVerification)
        ->and(Payment::query()->exists())->toBeFalse();
});

it('rejects with a reason, releases the seats and emails the customer', function (): void {
    $transfer = uploadedTransfer();
    $slot = Slot::query()->findOrFail((int) $transfer->order->items()->value('slot_id'));
    expect($slot->seatsFree())->toBe(4);

    $this->actingAs(transferAdmin());

    Livewire::test(VerifyTransfers::class)
        ->callAction(TestAction::make('reject')->table($transfer->order), data: ['reason' => ''])
        ->assertHasFormErrors(['reason' => 'required']);

    Livewire::test(VerifyTransfers::class)
        ->callAction(TestAction::make('reject')->table($transfer->order), data: ['reason' => 'No payment with this reference arrived'])
        ->assertNotified();

    expect($transfer->refresh()->status)->toBe(BankTransferStatus::Rejected)
        ->and($transfer->rejection_reason)->toBe('No payment with this reference arrived')
        ->and($transfer->order->refresh()->status)->toBe(OrderStatus::Rejected)
        ->and($slot->refresh()->seatsFree())->toBe(6);

    Notification::assertSentTo($transfer->order->user, TransferRejected::class, fn (TransferRejected $n): bool => $n->reason === 'No payment with this reference arrived');
    expect(fn () => app(ApproveBankTransfer::class)->handle($transfer, transferAdmin()))->toThrow(BookingRejected::class);
});

it('extends the deadline with a reason and re-checks a lapsed hold', function (): void {
    $order = app(CreateOrder::class)->handle(
        $user = User::factory()->create(),
        Product::factory()->create(),
        ($slot = Slot::factory()->at(CarbonImmutable::now()->addDays(4))->create(['capacity' => 1]))->id,
        [Rider::factory()->for($user)->create()->id],
    );
    $order = app(ChooseBankTransfer::class)->handle($order);
    $admin = transferAdmin();

    expect(fn () => app(ExtendTransferHold::class)->handle($order, $admin, now()->addDays(2)->toImmutable(), ' '))->toThrow(BookingRejected::class, 'reason');
    expect(fn () => app(ExtendTransferHold::class)->handle($order, $admin, now()->addDays(10)->toImmutable(), 'Too late'))->toThrow(BookingRejected::class, 'after the session starts');

    app(ExtendTransferHold::class)->handle($order, $admin, $until = now()->addDays(2)->startOfSecond()->toImmutable(), 'Customer at the bank tomorrow');
    expect($order->refresh()->expires_at?->equalTo($until))->toBeTrue()
        ->and(BookingHold::query()->where('order_id', $order->id)->sole()->expires_at->equalTo($until))->toBeTrue();

    // The hold lapses and someone else takes the only seat: no extension.
    BookingHold::query()->where('order_id', $order->id)->update(['expires_at' => now()->subMinute()]);
    $order->forceFill(['expires_at' => now()->subMinute()])->save();
    Booking::factory()->create(['slot_id' => $slot->id]);

    expect(fn () => app(ExtendTransferHold::class)->handle($order->refresh(), $admin, now()->addDay()->toImmutable(), 'Again'))->toThrow(BookingRejected::class);
});

it('does not let staff approve, reject, extend or open proofs', function (): void {
    $transfer = uploadedTransfer();
    $staff = User::factory()->staff()->withMfa()->create();

    expect(fn () => app(ApproveBankTransfer::class)->handle($transfer, $staff))->toThrow(BookingRejected::class, 'Only admins')
        ->and(fn () => app(RejectBankTransfer::class)->handle($transfer, $staff, 'x'))->toThrow(BookingRejected::class, 'Only admins')
        ->and(fn () => app(ExtendTransferHold::class)->handle($transfer->order, $staff, now()->addDay()->toImmutable(), 'x'))->toThrow(BookingRejected::class, 'Only admins');

    $this->actingAs($staff);
    expect(VerifyTransfers::canAccess())->toBeFalse()
        ->and(TransfersWaiting::canView())->toBeFalse();
    $this->get(VerifyTransfers::getUrl())->assertForbidden();
    $this->get(TransferProofController::signedUrl($transfer))->assertForbidden();

    expect($transfer->refresh()->status)->toBe(BankTransferStatus::Submitted);
});

it('serves the proof only through a valid signed URL to an admin', function (): void {
    $transfer = uploadedTransfer();
    $url = TransferProofController::signedUrl($transfer);
    $unsigned = route('admin.transfer-proof', ['transfer' => $transfer->id]);

    // Guest: sent to sign in. Customer (even the owner): forbidden.
    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs($transfer->order->user)->get($url)->assertForbidden();

    $admin = transferAdmin();
    $this->actingAs($admin);

    $this->get($unsigned)->assertForbidden();
    $this->get($url.'x')->assertForbidden();

    $response = $this->get($url)->assertOk();
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Content-Type'))->toStartWith('image/');

    $this->travel(TransferProofController::URL_MINUTES + 1)->minutes();
    $this->get($url)->assertForbidden();
});

it('lists transfers soonest deadline first and shows the proof modal with a signed URL', function (): void {
    $later = uploadedTransfer();
    $sooner = uploadedTransfer();
    $sooner->order->forceFill(['expires_at' => now()->addHour()])->save();
    $later->order->forceFill(['expires_at' => now()->addHours(10)])->save();

    $this->actingAs(transferAdmin());

    Livewire::test(VerifyTransfers::class)
        ->assertCanSeeTableRecords([$sooner->order, $later->order], inOrder: true)
        ->mountAction(TestAction::make('view_proof')->table($sooner->order))
        ->assertActionMounted(TestAction::make('view_proof')->table($sooner->order));

    // The modal body: signed, expiring URL and the transfer details.
    $html = view('filament.transfers.proof', [
        'order' => $sooner->order,
        'transfer' => $sooner,
        'url' => TransferProofController::signedUrl($sooner),
        'pdf' => false,
    ])->render();

    expect($html)->toContain('signature=')->toContain('expires=')->toContain('TXN-42')->toContain('PKR 20,000');
});

it('counts waiting transfers and the oldest wait on the dashboard', function (): void {
    expect(TransfersWaiting::summary())->toBe(['count' => 0, 'oldest' => null]);

    $first = uploadedTransfer();
    $this->travel(3)->hours();
    uploadedTransfer();

    $summary = TransfersWaiting::summary();

    expect($summary['count'])->toBe(2)
        ->and($summary['oldest']?->equalTo($first->submitted_at))->toBeTrue();

    $this->actingAs(transferAdmin());
    Livewire::test(TransfersWaiting::class)->assertSee('Transfers to verify')->assertSee('3 hours');
});

it('links the dashboard and queue from the admin panel for admins', function (): void {
    uploadedTransfer();
    $this->actingAs(transferAdmin());

    $this->get(VerifyTransfers::getUrl())->assertOk()->assertSee('Verify transfers');
    // Widgets load lazily: the dashboard carries the component, which renders the stats.
    $this->get('/test-admin')->assertOk()->assertSee('wire:name="'.TransfersWaiting::class.'"', false);
});

it('never puts the proof path into the account data export', function (): void {
    $transfer = uploadedTransfer();

    $this->actingAs($transfer->order->user)
        ->post(route('account.privacy.export'))
        ->assertOk()
        ->assertDontSee($transfer->screenshot_path);
});

it('deletes only the screenshot 90 days after review and keeps the records', function (): void {
    $approved = uploadedTransfer();
    $rejected = uploadedTransfer();
    $waiting = uploadedTransfer();
    $admin = transferAdmin();

    app(ApproveBankTransfer::class)->handle($approved, $admin);
    app(RejectBankTransfer::class)->handle($rejected, $admin, 'No money arrived');

    $files = [$approved->refresh()->screenshot_path, $rejected->refresh()->screenshot_path, $waiting->screenshot_path];
    $disk = Storage::disk(TransferProofs::DISK);

    $this->travel(89)->days();
    expect(app(PruneTransferProofs::class)->handle())->toBe(0);

    $this->travel(2)->days();
    $this->artisan('transfers:prune-proofs')->expectsOutputToContain('Deleted 2 transfer proofs')->assertSuccessful();

    $approved->refresh();
    $rejected->refresh();

    expect($disk->exists((string) $files[0]))->toBeFalse()
        ->and($disk->exists((string) $files[1]))->toBeFalse()
        ->and($disk->exists((string) $files[2]))->toBeTrue() // still waiting for review
        ->and($approved->screenshot_path)->toBeNull()
        ->and($approved->proof_deleted_at)->not->toBeNull()
        // The record stays: who verified it and when, the transaction ID, the payment.
        ->and($approved->status)->toBe(BankTransferStatus::Approved)
        ->and($approved->verified_by)->toBe($admin->id)
        ->and($approved->verified_at)->not->toBeNull()
        ->and($approved->transaction_ref)->toBe('TXN-42')
        ->and($rejected->rejection_reason)->toBe('No money arrived')
        ->and(Payment::query()->where('order_id', $approved->order_id)->sole()->amount_minor)->toBe(2_000_000)
        ->and($approved->order->reference)->toStartWith('RP-')
        ->and(Activity::query()->where('event', 'transfer_proof_deleted')->count())->toBe(2);

    // Running again changes nothing; the proof link now answers 404.
    expect(app(PruneTransferProofs::class)->handle())->toBe(0);
    $this->actingAs($admin)->get(TransferProofController::signedUrl($approved))->assertNotFound();
});

it('uses the retention setting', function (): void {
    $transfer = uploadedTransfer();
    app(ApproveBankTransfer::class)->handle($transfer, transferAdmin());
    app(UpdateSettings::class)->handle([SettingKey::TransferProofRetentionDays->value => 30]);

    $this->travel(31)->days();

    expect(app(PruneTransferProofs::class)->handle())->toBe(1);
});

it('schedules the proof clean-up daily', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e): bool => str_contains((string) $e->command, 'transfers:prune-proofs'));

    expect($events)->toHaveCount(1);
});
