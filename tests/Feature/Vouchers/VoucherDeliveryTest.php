<?php

declare(strict_types=1);

use App\Actions\Account\AnonymiseAccount;
use App\Actions\Account\ExportAccountData;
use App\Actions\Vouchers\CreateVoucherOrder;
use App\Actions\Vouchers\DeliverVoucher;
use App\Actions\Vouchers\ExpireVouchers;
use App\Actions\Vouchers\IssueVoucher;
use App\Actions\Vouchers\UpdateVoucherRecipient;
use App\Actions\Vouchers\VoucherBalance;
use App\Enums\VoucherLedgerReason;
use App\Enums\VoucherStatus;
use App\Exceptions\VoucherRejected;
use App\Http\Controllers\PrintableVoucherController;
use App\Models\NotificationLog;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherOrder;
use App\Notifications\GiftVoucherReceived;
use App\Notifications\VoucherPurchaseConfirmed;
use App\Notifications\VoucherSent;
use App\Notifications\VoucherTransferExpired;
use App\Notifications\VoucherTransferReceived;
use App\Notifications\VoucherTransferRejected;
use App\Notifications\VoucherTransferToVerify;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

function gvIssued(?CarbonImmutable $sendOn = null, string $email = 'sara@example.com'): Voucher
{
    $buyer = User::factory()->create(['name' => 'Ayesha']);
    $order = app(CreateVoucherOrder::class)->handle($buyer, null, 800_000, 'Sara', $email, 'For your birthday', $sendOn);

    return app(IssueVoucher::class)->handle($order);
}

beforeEach(function (): void {
    Notification::fake();
});

it('emails a send-now voucher to the recipient and a receipt to the buyer, once', function (): void {
    $voucher = gvIssued();

    app(DeliverVoucher::class)->handle($voucher);
    app(DeliverVoucher::class)->due();

    Notification::assertSentOnDemand(GiftVoucherReceived::class, fn (GiftVoucherReceived $n, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'sara@example.com');
    Notification::assertSentOnDemandTimes(GiftVoucherReceived::class, 1);
    Notification::assertSentTo($voucher->voucherOrder?->user, VoucherPurchaseConfirmed::class);
    Notification::assertNotSentTo($voucher->voucherOrder?->user, VoucherSent::class);

    expect($voucher->refresh()->delivered_at)->not->toBeNull()
        ->and(NotificationLog::query()->where('type', 'gift_voucher_received')->sole()->user_id)->toBeNull();
});

it('holds a scheduled voucher until 09:00 Pakistan time on its day', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-01 10:00', 'Asia/Karachi'));
    $voucher = gvIssued(CarbonImmutable::parse('2026-12-05', 'Asia/Karachi'));

    Notification::assertSentOnDemandTimes(GiftVoucherReceived::class, 0);
    expect($voucher->refresh()->delivered_at)->toBeNull();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-05 08:45', 'Asia/Karachi'));
    expect(app(DeliverVoucher::class)->due())->toBe(0);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-05 09:00', 'Asia/Karachi'));
    expect(app(DeliverVoucher::class)->due())->toBe(1)
        ->and(app(DeliverVoucher::class)->due())->toBe(0);

    Notification::assertSentOnDemandTimes(GiftVoucherReceived::class, 1);
    Notification::assertSentTo($voucher->voucherOrder?->user, VoucherSent::class);
    CarbonImmutable::setTestNow();
});

it('never sends a voided voucher', function (): void {
    $voucher = gvIssued(CarbonImmutable::now('Asia/Karachi')->addDays(3));
    app(VoucherBalance::class)->void($voucher, null, 'Ordered by mistake');

    $this->travel(4)->days();

    expect(app(DeliverVoucher::class)->due())->toBe(0);
    Notification::assertSentOnDemandTimes(GiftVoucherReceived::class, 0);
});

it('opens the printable voucher only through the signed link', function (): void {
    $voucher = gvIssued();

    $this->get(PrintableVoucherController::url($voucher))
        ->assertOk()
        ->assertSee($voucher->formattedCode())
        ->assertSee('For your birthday')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    $this->get('/vouchers/'.$voucher->code)->assertForbidden();
});

it('lets the buyer change the recipient until the voucher is sent', function (): void {
    $voucher = gvIssued(CarbonImmutable::now('Asia/Karachi')->addDays(10));
    $order = $voucher->voucherOrder;
    assert($order instanceof VoucherOrder);

    $this->actingAs($order->user)
        ->put(route('account.vouchers.recipient', $order->reference), ['form' => $order->reference, 'recipient_name' => 'Sara K', 'recipient_email' => 'NEW@example.com'])
        ->assertRedirect(route('account.vouchers'));

    expect($voucher->refresh()->recipient_email)->toBe('new@example.com')
        ->and($order->refresh()->recipient_name)->toBe('Sara K');

    $voucher->forceFill(['delivered_at' => now()])->save();

    expect(fn () => app(UpdateVoucherRecipient::class)->handle($order, $order->user, 'X', 'x@example.com'))->toThrow(VoucherRejected::class);

    $this->actingAs(User::factory()->create())
        ->put(route('account.vouchers.recipient', $order->reference), ['recipient_name' => 'X', 'recipient_email' => 'x@example.com'])
        ->assertNotFound();
});

it('expires vouchers at their expiry and writes off the balance', function (): void {
    $voucher = gvIssued();
    $this->travel(13)->months();

    expect(app(ExpireVouchers::class)->handle())->toBe(1);

    $voucher->refresh();
    expect($voucher->status)->toBe(VoucherStatus::Expired)
        ->and($voucher->balance_minor)->toBe(0)
        ->and($voucher->ledger()->where('reason', VoucherLedgerReason::Expired)->sole()->delta_minor)->toBe(-800_000);
});

it('brings an expired voucher back with its balance when an admin extends it', function (): void {
    $voucher = gvIssued();
    $admin = User::factory()->admin()->create();
    $this->travel(13)->months();
    app(ExpireVouchers::class)->handle();

    expect(fn () => app(VoucherBalance::class)->extend($voucher->refresh(), $admin, CarbonImmutable::now()->addMonth(), ''))->toThrow(VoucherRejected::class);

    app(VoucherBalance::class)->extend($voucher->refresh(), $admin, CarbonImmutable::now()->addMonth(), 'Customer was abroad');

    $voucher->refresh();
    expect($voucher->status)->toBe(VoucherStatus::Active)
        ->and($voucher->balance_minor)->toBe(800_000)
        ->and((int) $voucher->ledger()->sum('delta_minor'))->toBe(800_000);
});

it('lets an admin resend, logged', function (): void {
    $voucher = gvIssued();
    $admin = User::factory()->admin()->create();

    app(DeliverVoucher::class)->resend($voucher->refresh(), $admin);

    Notification::assertSentOnDemandTimes(GiftVoucherReceived::class, 2);
    expect(NotificationLog::query()->where('type', 'gift_voucher_received')->whereNotNull('resent_by')->count())->toBe(1);
});

it('renders every gift voucher email', function (): void {
    $voucher = gvIssued(CarbonImmutable::now('Asia/Karachi')->addDays(2));
    $order = $voucher->voucherOrder;
    assert($order instanceof VoucherOrder);
    $admin = User::factory()->admin()->create();

    $mails = [
        new GiftVoucherReceived($voucher),
        new VoucherPurchaseConfirmed($order, $voucher),
        new VoucherSent($voucher),
        new VoucherTransferReceived($order),
        new VoucherTransferToVerify($order),
        new VoucherTransferRejected($order, 'Nothing arrived'),
        new VoucherTransferExpired($order),
    ];

    foreach ($mails as $mail) {
        $html = (string) $mail->toMail($admin)->render();
        expect($html)->toContain('Ride Pakistan');
    }

    expect((string) (new GiftVoucherReceived($voucher))->toMail($admin)->render())
        ->toContain($voucher->formattedCode())
        ->toContain('Ayesha');
});

it('exports gift vouchers with the account data and clears the recipient on anonymisation', function (): void {
    $voucher = gvIssued();
    $order = $voucher->voucherOrder;
    assert($order instanceof VoucherOrder);

    $export = app(ExportAccountData::class)->handle($order->user);
    expect($export['gift_vouchers'][0]['reference'])->toBe($order->reference)
        ->and($export['gift_vouchers'][0]['message'])->toBe('For your birthday');

    app(AnonymiseAccount::class)->handle($order->user);

    $order->refresh();
    expect($order->recipient_email)->toBeNull()
        ->and($order->message)->toBeNull()
        ->and($order->amount_minor)->toBe(800_000)
        ->and($voucher->refresh()->status)->toBe(VoucherStatus::Active);
});
