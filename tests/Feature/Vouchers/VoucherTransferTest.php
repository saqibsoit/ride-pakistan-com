<?php

declare(strict_types=1);

use App\Actions\Payments\PruneTransferProofs;
use App\Actions\Vouchers\ChooseVoucherBankTransfer;
use App\Actions\Vouchers\CreateVoucherCheckout;
use App\Actions\Vouchers\CreateVoucherOrder;
use App\Actions\Vouchers\ReleaseExpiredVoucherOrders;
use App\Actions\Vouchers\ReviewVoucherTransfer;
use App\Actions\Vouchers\SubmitVoucherTransfer;
use App\Enums\PaymentMethod;
use App\Enums\VoucherOrderStatus;
use App\Exceptions\VoucherRejected;
use App\Filament\Pages\VerifyVoucherTransfers;
use App\Http\Controllers\VoucherTransferProofController;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherOrder;
use App\Notifications\VoucherTransferExpired;
use App\Notifications\VoucherTransferReceived;
use App\Notifications\VoucherTransferRejected;
use App\Notifications\VoucherTransferToVerify;
use App\Support\TransferProofs;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function gvTransferOrder(): VoucherOrder
{
    $order = app(CreateVoucherOrder::class)->handle(User::factory()->create(), null, 1_000_000, 'Omar', 'omar@example.com', null, null);

    return app(ChooseVoucherBankTransfer::class)->handle($order);
}

function gvProof(): UploadedFile
{
    $image = imagecreatetruecolor(60, 40);
    ob_start();
    imagejpeg($image);
    $path = (string) tempnam(sys_get_temp_dir(), 'proof');
    file_put_contents($path, (string) ob_get_clean());

    return new UploadedFile($path, 'proof.jpg', null, null, true);
}

function gvUploaded(): VoucherOrder
{
    return app(SubmitVoucherTransfer::class)->handle(gvTransferOrder(), gvProof(), 'TXN-9');
}

function gvAdmin(): User
{
    return User::factory()->admin()->withMfa()->create();
}

beforeEach(function (): void {
    Storage::fake(TransferProofs::DISK);
    Notification::fake();
});

it('switches to bank transfer, closing any open card session', function (): void {
    $order = app(CreateVoucherOrder::class)->handle(User::factory()->create(), null, 500_000, 'Omar', 'omar@example.com', null, null);
    app(CreateVoucherCheckout::class)->handle($order);
    $session = (string) $order->refresh()->stripe_checkout_session_id;

    $this->actingAs($order->user)->post(route('gift-vouchers.transfer.choose', $order->reference))
        ->assertRedirect(route('gift-vouchers.transfer', $order->reference));

    $order->refresh();
    expect($order->status)->toBe(VoucherOrderStatus::AwaitingTransfer)
        ->and($order->payment_method)->toBe(PaymentMethod::BankTransfer)
        ->and($order->expires_at?->greaterThan(now()->addHours(23)))->toBeTrue()
        ->and($this->stripe()->expired)->toBe([$session]);

    $this->actingAs($order->user)->get(route('gift-vouchers.transfer', $order->reference))->assertOk()->assertSee($order->reference);
});

it('refuses the switch when the card payment already went through', function (): void {
    $order = app(CreateVoucherOrder::class)->handle(User::factory()->create(), null, 500_000, 'Omar', 'omar@example.com', null, null);
    app(CreateVoucherCheckout::class)->handle($order);
    $this->stripe()->complete((string) $order->refresh()->stripe_checkout_session_id);

    expect(fn () => app(ChooseVoucherBankTransfer::class)->handle($order))->toThrow(VoucherRejected::class)
        ->and($order->refresh()->status)->toBe(VoucherOrderStatus::PendingPayment);
});

it('stores the proof privately and emails the customer and every admin once', function (): void {
    $admin = gvAdmin();
    User::factory()->staff()->withMfa()->create();
    $order = gvTransferOrder();

    $this->actingAs($order->user)
        ->post(route('gift-vouchers.transfer.upload', $order->reference), ['proof' => gvProof(), 'transaction_ref' => 'TXN-1'])
        ->assertRedirect(route('gift-vouchers.transfer', $order->reference));

    $order->refresh();
    expect($order->status)->toBe(VoucherOrderStatus::AwaitingVerification)
        ->and($order->transaction_ref)->toBe('TXN-1')
        ->and(Storage::disk(TransferProofs::DISK)->exists((string) $order->screenshot_path))->toBeTrue()
        ->and(str_contains((string) $order->screenshot_path, 'proof'))->toBeFalse();

    // Replacing before review keeps one file and sends nothing new.
    $old = (string) $order->screenshot_path;
    app(SubmitVoucherTransfer::class)->handle($order, gvProof(), 'TXN-2');

    expect(Storage::disk(TransferProofs::DISK)->exists($old))->toBeFalse();
    Notification::assertSentTimes(VoucherTransferReceived::class, 1);
    Notification::assertSentTo($admin, VoucherTransferToVerify::class);
    Notification::assertSentTimes(VoucherTransferToVerify::class, 1);
});

it('never expires an uploaded transfer, but closes one without proof at the deadline', function (): void {
    $uploaded = gvUploaded();
    $waiting = gvTransferOrder();

    $this->travel(30)->hours();

    expect(app(ReleaseExpiredVoucherOrders::class)->handle())->toBe(1)
        ->and($uploaded->refresh()->status)->toBe(VoucherOrderStatus::AwaitingVerification)
        ->and($waiting->refresh()->status)->toBe(VoucherOrderStatus::Expired);

    Notification::assertSentTo($waiting->user, VoucherTransferExpired::class);
});

it('approves once, records the verifier and issues the voucher', function (): void {
    $order = gvUploaded();
    $admin = gvAdmin();

    $voucher = app(ReviewVoucherTransfer::class)->approve($order, $admin);
    $again = app(ReviewVoucherTransfer::class)->approve($order->refresh(), $admin);

    $order->refresh();
    expect($again->id)->toBe($voucher->id)
        ->and(Voucher::query()->count())->toBe(1)
        ->and($order->status)->toBe(VoucherOrderStatus::Paid)
        ->and($order->verified_by)->toBe($admin->id)
        ->and($order->meta['payments'][0]['provider'])->toBe('bank_transfer')
        ->and($voucher->balance_minor)->toBe(1_000_000);
});

it('rejects with a reason and emails the customer', function (): void {
    $order = gvUploaded();

    expect(fn () => app(ReviewVoucherTransfer::class)->reject($order, gvAdmin(), ' '))->toThrow(VoucherRejected::class);

    app(ReviewVoucherTransfer::class)->reject($order, gvAdmin(), 'Nothing arrived');

    expect($order->refresh()->status)->toBe(VoucherOrderStatus::Rejected)
        ->and($order->rejection_reason)->toBe('Nothing arrived')
        ->and(Voucher::query()->exists())->toBeFalse();
    Notification::assertSentTo($order->user, VoucherTransferRejected::class, fn (VoucherTransferRejected $n): bool => $n->reason === 'Nothing arrived');
});

it('lets only admins review', function (): void {
    $order = gvUploaded();
    $staff = User::factory()->staff()->withMfa()->create();

    expect(fn () => app(ReviewVoucherTransfer::class)->approve($order, $staff))->toThrow(VoucherRejected::class);

    $this->actingAs($staff)->get(VerifyVoucherTransfers::getUrl())->assertForbidden();
    $this->actingAs($staff)->get(VoucherTransferProofController::signedUrl($order))->assertForbidden();
});

it('approves and rejects from the admin queue', function (): void {
    $first = gvUploaded();
    $second = gvUploaded();
    $this->actingAs(gvAdmin());

    Livewire::test(VerifyVoucherTransfers::class)
        ->assertCanSeeTableRecords([$first, $second])
        ->callAction(TestAction::make('approve')->table($first))
        ->assertNotified('Approved: voucher issued for '.$first->reference)
        ->callAction(TestAction::make('reject')->table($second), data: ['reason' => ''])
        ->assertHasFormErrors(['reason' => 'required']);

    expect($first->refresh()->status)->toBe(VoucherOrderStatus::Paid)
        ->and($second->refresh()->status)->toBe(VoucherOrderStatus::AwaitingVerification);
});

it('serves the proof to admins only through a signed, sandboxed URL', function (): void {
    $order = gvUploaded();
    $admin = gvAdmin();

    $this->actingAs($admin)->get(VoucherTransferProofController::signedUrl($order))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; img-src 'self'; sandbox");

    $unsigned = route('admin.voucher-transfer-proof', ['voucherOrder' => $order->reference]);
    $this->actingAs($admin)->get($unsigned)->assertForbidden();
    $this->actingAs($order->user)->get(VoucherTransferProofController::signedUrl($order))->assertForbidden();
});

it('deletes a reviewed voucher proof after the retention period and keeps the record', function (): void {
    $order = gvUploaded();
    $path = (string) $order->screenshot_path;
    app(ReviewVoucherTransfer::class)->approve($order, gvAdmin());
    $waiting = gvUploaded();

    $this->travel(91)->days();
    app(PruneTransferProofs::class)->handle();

    expect(Storage::disk(TransferProofs::DISK)->exists($path))->toBeFalse()
        ->and($order->refresh()->screenshot_path)->toBeNull()
        ->and($order->proof_deleted_at)->not->toBeNull()
        ->and($order->transaction_ref)->toBe('TXN-9')
        ->and($waiting->refresh()->screenshot_path)->not->toBeNull();
});

it('labels the optional transaction ID field "(optional)" only once', function (): void {
    $order = gvTransferOrder();

    $html = $this->actingAs($order->user)->get(route('gift-vouchers.transfer', $order->reference))->assertOk()->getContent();

    expect(substr_count(strip_tags((string) $html), '(optional)'))->toBe(1)
        ->and(strip_tags((string) $html))->toMatch('/Transaction ID\s+\(optional\)/');
});
