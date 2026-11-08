<?php

declare(strict_types=1);

use App\Actions\Vouchers\GenerateVoucherCode;
use App\Actions\Vouchers\GenerateVoucherReference;
use App\Enums\DiscountType;
use App\Enums\VoucherOrderStatus;
use App\Enums\VoucherStatus;
use App\Models\PromoCode;
use App\Models\Referral;
use App\Models\Voucher;
use App\Models\VoucherOrder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

it('creates every new model from its factory', function (string $model): void {
    $record = $model::factory()->create();

    expect($record->exists)->toBeTrue();
})->with([VoucherOrder::class, Voucher::class, PromoCode::class, Referral::class]);

it('encrypts the gift message at rest', function (): void {
    $order = VoucherOrder::factory()->create(['message' => 'Happy birthday Sara']);

    $raw = (string) DB::table('voucher_orders')->where('id', $order->id)->value('message');

    expect($raw)->not->toContain('Sara')
        ->and($order->fresh()?->message)->toBe('Happy birthday Sara');
});

it('never mass-assigns voucher, purchase or referral status', function (): void {
    $order = (new VoucherOrder)->fill(['status' => 'paid', 'paid_at' => now(), 'needs_review' => true]);
    $voucher = (new Voucher)->fill(['status' => 'void']);
    $referral = (new Referral)->fill(['status' => 'rewarded', 'rewarded_at' => now()]);

    expect($order->status)->toBe(VoucherOrderStatus::PendingPayment)
        ->and($order->paid_at)->toBeNull()
        ->and($order->needs_review)->toBeFalse()
        ->and($voucher->status)->toBe(VoucherStatus::Active)
        ->and($referral->status->value)->toBe('pending')
        ->and($referral->rewarded_at)->toBeNull();
});

it('keeps voucher codes and purchase references unique', function (): void {
    $voucher = Voucher::factory()->create();

    expect(fn () => Voucher::factory()->create(['code' => $voucher->code]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows one referral per referred customer', function (): void {
    $referral = Referral::factory()->create();

    expect(fn () => Referral::factory()->create(['referred_id' => $referral->referred_id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('formats and normalises voucher codes', function (): void {
    $voucher = Voucher::factory()->make(['code' => 'ABCDEFGHJKMN']);

    expect($voucher->formattedCode())->toBe('ABCD-EFGH-JKMN')
        ->and(Voucher::normaliseCode(' abcd-efgh jkmn '))->toBe('ABCDEFGHJKMN')
        ->and(GenerateVoucherCode::isValid(GenerateVoucherCode::random()))->toBeTrue()
        ->and(GenerateVoucherCode::isValid('ABCDEFGHJKM0'))->toBeFalse()
        ->and(GenerateVoucherReference::isValid(GenerateVoucherReference::random()))->toBeTrue();
});

it('retries a taken voucher code', function (): void {
    Voucher::factory()->create(['code' => 'AAAAAAAAAAAA']);
    $candidates = ['AAAAAAAAAAAA', 'BBBBBBBBBBBB'];

    $code = app(GenerateVoucherCode::class)->handle(function () use (&$candidates): string {
        return (string) array_shift($candidates);
    });

    expect($code)->toBe('BBBBBBBBBBBB');
});

it('knows when a voucher can still be used', function (): void {
    $voucher = Voucher::factory()->make(['balance_minor' => 100, 'expires_at' => now()->addDay()]);
    $empty = Voucher::factory()->make(['balance_minor' => 0]);
    $old = Voucher::factory()->make(['expires_at' => now()->subMinute()]);

    expect($voucher->isUsable())->toBeTrue()
        ->and($empty->isUsable())->toBeFalse()
        ->and($old->isUsable())->toBeFalse();
});

it('summarises promo codes', function (): void {
    expect(PromoCode::factory()->make(['percent' => 15])->summary())->toBe('15% off')
        ->and(PromoCode::factory()->fixed(200_000)->make()->summary())->toContain('2,000')
        ->and(PromoCode::factory()->make()->discount_type)->toBe(DiscountType::Percent);
});
