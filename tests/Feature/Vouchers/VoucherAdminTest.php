<?php

declare(strict_types=1);

use App\Actions\Vouchers\IssueManualVoucher;
use App\Enums\VoucherStatus;
use App\Exceptions\VoucherRejected;
use App\Filament\Pages\VerifyVoucherTransfers;
use App\Filament\Resources\VoucherOrders\Pages\ListVoucherOrders;
use App\Filament\Resources\VoucherOrders\VoucherOrderResource;
use App\Filament\Resources\Vouchers\Pages\ListVouchers;
use App\Filament\Resources\Vouchers\VoucherResource;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherOrder;
use App\Notifications\GiftVoucherReceived;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function (): void {
    Notification::fake();
});

it('shows voucher screens to admins only', function (string $role, int $status): void {
    $user = User::factory()->{$role}()->withMfa()->create();

    foreach ([VoucherResource::getUrl(), VoucherOrderResource::getUrl(), VerifyVoucherTransfers::getUrl()] as $url) {
        $this->actingAs($user)->get($url)->assertStatus($status);
    }
})->with([
    'admin' => ['admin', 200],
    'staff' => ['staff', 403],
    'trainer' => ['trainer', 403],
]);

it('issues a voucher by hand with a reason and emails it', function (): void {
    $admin = User::factory()->admin()->withMfa()->create();
    $this->actingAs($admin);

    Livewire::test(ListVouchers::class)
        ->callAction('issue', data: ['amount_pkr' => 2500, 'name' => 'Bilal', 'email' => 'bilal@example.com', 'reason' => ''])
        ->assertHasFormErrors(['reason' => 'required']);

    Livewire::test(ListVouchers::class)
        ->callAction('issue', data: ['amount_pkr' => 2500, 'name' => 'Bilal', 'email' => 'bilal@example.com', 'reason' => 'Rain-cancelled session'])
        ->assertHasNoFormErrors();

    $voucher = Voucher::query()->sole();
    expect($voucher->initial_minor)->toBe(250_000)
        ->and($voucher->issued_by)->toBe($admin->id)
        ->and($voucher->issue_reason)->toBe('Rain-cancelled session')
        ->and($voucher->voucher_order_id)->toBeNull()
        ->and($voucher->delivered_at)->not->toBeNull();

    Notification::assertSentOnDemand(GiftVoucherReceived::class);
});

it('refuses manual vouchers from non-admins', function (): void {
    app(IssueManualVoucher::class)->handle(User::factory()->staff()->create(), 100_000, 'A', 'a@example.com', 'Because');
})->throws(VoucherRejected::class);

it('voids and extends from the list with a reason', function (): void {
    $this->actingAs(User::factory()->admin()->withMfa()->create());
    $voucher = Voucher::factory()->create(['balance_minor' => 400_000]);
    $other = Voucher::factory()->create();

    Livewire::test(ListVouchers::class)
        ->assertCanSeeTableRecords([$voucher, $other])
        ->callAction(TestAction::make('void')->table($voucher), data: ['reason' => ''])
        ->assertHasFormErrors(['reason' => 'required']);

    Livewire::test(ListVouchers::class)
        ->callAction(TestAction::make('void')->table($voucher), data: ['reason' => 'Fraudulent purchase'])
        ->callAction(TestAction::make('extend')->table($other), data: ['until' => now()->addYears(2)->format('Y-m-d'), 'reason' => 'Goodwill']);

    expect($voucher->refresh()->status)->toBe(VoucherStatus::Void)
        ->and($voucher->balance_minor)->toBe(0)
        ->and($voucher->void_reason)->toBe('Fraudulent purchase')
        ->and($other->refresh()->expires_at->greaterThan(now()->addYears(2)->subDay()))->toBeTrue();
});

it('changes the recipient of an unsent voucher and resends from the list', function (): void {
    $this->actingAs(User::factory()->admin()->withMfa()->create());
    $voucher = Voucher::factory()->create(['recipient_email' => 'old@example.com']);

    Livewire::test(ListVouchers::class)
        ->callAction(TestAction::make('recipient')->table($voucher), data: ['name' => 'New', 'email' => 'new@example.com'])
        ->callAction(TestAction::make('resend')->table($voucher->refresh()));

    expect($voucher->refresh()->recipient_email)->toBe('new@example.com')
        ->and($voucher->delivered_at)->not->toBeNull();
    Notification::assertSentOnDemand(GiftVoucherReceived::class, fn ($n, $channels, $notifiable): bool => $notifiable->routes['mail'] === 'new@example.com');
});

it('lists purchases and clears a review flag', function (): void {
    $this->actingAs(User::factory()->admin()->withMfa()->create());
    $order = VoucherOrder::factory()->create();
    $order->forceFill(['needs_review' => true, 'meta' => ['review_reason' => 'Amount mismatch']])->save();

    Livewire::test(ListVoucherOrders::class)
        ->assertCanSeeTableRecords([$order])
        ->callAction(TestAction::make('reviewed')->table($order));

    expect($order->refresh()->needs_review)->toBeFalse();
});
