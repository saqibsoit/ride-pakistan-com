<?php

declare(strict_types=1);

use App\Actions\Vouchers\IssueVoucher;
use App\Enums\VoucherOrderStatus;
use App\Exceptions\GuestCheckoutRejected;
use App\Http\Controllers\PrintableVoucherController;
use App\Models\User;
use App\Models\VoucherOrder;
use App\Notifications\GiftVoucherReceived;
use App\Notifications\VoucherPurchaseConfirmed;
use App\Support\VoucherOrderLink;
use Illuminate\Support\Facades\Notification;

/*
| Gift vouchers bought without signing in (guest checkout). Helpers are
| prefixed `gv2` (VoucherPurchaseTest already uses `gv`).
*/

beforeEach(function (): void {
    Notification::fake();
});

/** @return array<string, string|int> */
function gv2Form(string $email = 'buyer@example.com', array $overrides = []): array
{
    return [
        'kind' => 'amount',
        'amount_pkr' => 5000,
        'recipient_name' => 'Sara Khan',
        'recipient_email' => 'sara@example.com',
        'message' => 'Happy birthday',
        'send' => 'now',
        'buyer_name' => 'Bilal Ahmed',
        'buyer_email' => $email,
        'buyer_email_confirmation' => $email,
        ...$overrides,
    ];
}

it('lets a guest buy a voucher and pay for it in the same browser', function (): void {
    $this->post(route('gift-vouchers.store'), gv2Form())->assertRedirect();

    $order = VoucherOrder::query()->sole();
    expect($order->placed_as_guest)->toBeTrue()
        ->and($order->contact_name)->toBe('Bilal Ahmed')
        ->and($order->user->email)->toBe('buyer@example.com')
        ->and($order->amount_minor)->toBe(500_000);

    $this->get(route('gift-vouchers.show', $order->reference))
        ->assertOk()
        ->assertSee($order->reference)
        ->assertDontSee(route('account.vouchers'));

    $this->post(route('gift-vouchers.card', $order->reference))->assertRedirect();
});

it('says the voucher is from the guest, never from the account behind the email', function (): void {
    $existing = User::factory()->create(['email' => 'family@example.com', 'name' => 'Account Holder']);
    $this->post(route('gift-vouchers.store'), gv2Form('family@example.com'))->assertSessionHasNoErrors();

    $order = VoucherOrder::query()->sole();
    expect($order->user_id)->toBe($existing->id)
        ->and($existing->refresh()->name)->toBe('Account Holder')
        ->and($order->buyerName())->toBe('Bilal Ahmed');

    $order->forceFill(['status' => VoucherOrderStatus::Paid, 'paid_at' => now()])->save();
    $voucher = app(IssueVoucher::class)->handle($order);

    $mail = (new GiftVoucherReceived($voucher))->toMail(new stdClass);
    $html = (string) $mail->render();

    expect($mail->subject)->toContain('Bilal Ahmed')
        ->and($html)->toContain('Bilal Ahmed')
        ->and($html)->not->toContain('Account Holder');

    $this->get(PrintableVoucherController::url($voucher))
        ->assertOk()->assertSee('Bilal Ahmed')->assertDontSee('Account Holder');
});

it('refuses mismatched emails and team accounts with the same neutral message', function (): void {
    $this->post(route('gift-vouchers.store'), gv2Form('a@example.com', ['buyer_email_confirmation' => 'b@example.com']))
        ->assertSessionHasErrors(['buyer_email' => 'The two email addresses don’t match.']);

    $admin = User::factory()->admin()->withMfa()->create();
    $this->post(route('gift-vouchers.store'), gv2Form($admin->email))
        ->assertSessionHasErrors(['buyer_email' => GuestCheckoutRejected::EMAIL]);

    expect(VoucherOrder::query()->count())->toBe(0);
});

it('keeps a guest purchase to its browser and its signed link', function (): void {
    $this->post(route('gift-vouchers.store'), gv2Form());
    $order = VoucherOrder::query()->sole();
    $link = VoucherOrderLink::for($order);

    $this->flushSession();
    $this->get(route('gift-vouchers.show', $order->reference))->assertRedirect(route('login'));

    $this->get($link)->assertRedirect(route('gift-vouchers.show', $order->reference));
    $this->get(route('gift-vouchers.show', $order->reference))->assertOk();

    $order->forceFill(['manage_link_version' => 2])->save();
    $this->get(route('gift-vouchers.show', $order->reference))->assertRedirect(route('login'));
    $this->get($link)->assertForbidden();
});

it('puts the purchase link in the buyer’s receipt and closes it 30 days after the voucher went out', function (): void {
    $this->post(route('gift-vouchers.store'), gv2Form());
    $order = VoucherOrder::query()->sole();
    $order->forceFill(['status' => VoucherOrderStatus::Paid, 'paid_at' => now()])->save();
    $voucher = app(IssueVoucher::class)->handle($order);

    Notification::assertSentTo($order->user, VoucherPurchaseConfirmed::class, function (VoucherPurchaseConfirmed $n) use ($order): bool {
        return str_contains(html_entity_decode((string) $n->toMail($order->user)->render()), VoucherOrderLink::for($order));
    });

    $voucher->forceFill(['delivered_at' => now()])->save();
    expect(VoucherOrderLink::isOpen($order->refresh()))->toBeTrue();

    $this->travel(VoucherOrderLink::DAYS_AFTER)->days();
    expect(VoucherOrderLink::isOpen($order->refresh()))->toBeFalse();
});
