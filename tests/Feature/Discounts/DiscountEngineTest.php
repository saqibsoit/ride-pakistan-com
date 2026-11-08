<?php

declare(strict_types=1);

use App\Actions\Booking\CreateOrder;
use App\Actions\Booking\FulfilOrder;
use App\Actions\Booking\ReleaseExpiredHolds;
use App\Actions\Credit\AccountCredit;
use App\Actions\Discounts\ChangeOrderDiscounts;
use App\Actions\Discounts\PromoEligibility;
use App\Actions\Discounts\RepriceOrder;
use App\Actions\Settings\UpdateSettings;
use App\Enums\AccountCreditReason;
use App\Enums\DiscountKind;
use App\Enums\OrderStatus;
use App\Enums\SettingKey;
use App\Enums\VoucherStatus;
use App\Exceptions\DiscountRejected;
use App\Models\AccountCreditEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\Referral;
use App\Models\Rider;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

/**
 * Days ahead for the next order's slot.
 * Counts up from 3 in call order and is reset before every test, so no
 * two slots in a test share a start time (slots.starts_at is unique).
 * Replaces a random value that could repeat.
 */
function dcSlotDay(bool $reset = false): int
{
    static $n = 2;

    if ($reset) {
        $n = 2;

        return $n;
    }

    return ++$n;
}

/** A pending single-session order for $riders riders at PKR $pricePkr each. */
function dcOrder(int $pricePkr = 10_000, int $riders = 1, ?User $user = null, ?Product $product = null): Order
{
    $user ??= User::factory()->create();
    $product ??= Product::factory()->create(['price_minor' => $pricePkr * 100]);
    $riderIds = array_map('intval', Rider::factory()->count($riders)->for($user)->create()->modelKeys());

    return app(CreateOrder::class)->handle($user, $product, nearSlot(dcSlotDay(), 8, ['capacity' => 6])->id, $riderIds);
}

function dcCredit(User $user, int $pkr): void
{
    AccountCreditEntry::query()->create(['user_id' => $user->id, 'delta_minor' => $pkr * 100, 'reason' => AccountCreditReason::Adjusted, 'note' => 'Test']);
}

/** @return array<string, int> amount by kind */
function dcLines(Order $order): array
{
    return $order->discounts()->get()->mapWithKeys(fn ($l): array => [$l->kind->value => $l->amount_minor])->all();
}

beforeEach(function (): void {
    dcSlotDay(reset: true);
    Notification::fake();
});

it('takes a percentage off, rounded down to whole rupees', function (): void {
    $order = dcOrder(9_999);
    $promo = PromoCode::factory()->create(['percent' => 15]);

    app(ChangeOrderDiscounts::class)->applyCode($order, $order->user, strtolower($promo->code));

    // 15% of 9,999 = 1,499.85 → 1,499.
    expect(dcLines($order->refresh()))->toBe(['promo' => 149_900])
        ->and($order->subtotal_minor)->toBe(999_900)
        ->and($order->amount_minor)->toBe(850_000)
        ->and(PromoRedemption::query()->where('order_id', $order->id)->value('amount_minor'))->toBe(149_900)
        ->and(Activity::query()->where('log_name', 'discounts')->exists())->toBeTrue();
});

it('never takes a fixed promo below zero', function (): void {
    $order = dcOrder(5_000);
    $promo = PromoCode::factory()->fixed(800_000)->create();

    app(ChangeOrderDiscounts::class)->applyCode($order, $order->user, $promo->code);

    expect($order->refresh()->amount_minor)->toBe(0)->and(dcLines($order))->toBe(['promo' => 500_000]);
});

it('explains why a promo code does not apply', function (array $promo, string $message, int $pricePkr = 10_000): void {
    $order = dcOrder($pricePkr);
    $code = PromoCode::factory()->create($promo);

    expect(fn () => app(ChangeOrderDiscounts::class)->applyCode($order, $order->user, $code->code))
        ->toThrow(DiscountRejected::class, $message);
})->with([
    'inactive' => [['is_active' => false], 'isn’t active'],
    'not started' => [['starts_at' => now()->addDays(3)], 'starts on'],
    'ended' => [['ends_at' => now()->subDay()], 'ended on'],
    'minimum order' => [['min_order_minor' => 2_000_000], 'at least PKR 20,000'],
]);

it('refuses unknown codes and other products', function (): void {
    $order = dcOrder();
    $promo = PromoCode::factory()->create();
    $promo->products()->attach(Product::factory()->create()->id);

    expect(fn () => app(ChangeOrderDiscounts::class)->applyCode($order, $order->user, 'NOPE-123'))->toThrow(DiscountRejected::class, 'recognise')
        ->and(fn () => app(ChangeOrderDiscounts::class)->applyCode($order, $order->user, $promo->code))->toThrow(DiscountRejected::class, 'isn’t valid for');
});

it('counts total and per-customer uses from open and paid orders only', function (): void {
    $promo = PromoCode::factory()->create(['max_uses' => 2, 'max_uses_per_customer' => 1]);
    $first = dcOrder();
    app(ChangeOrderDiscounts::class)->applyCode($first, $first->user, $promo->code);

    // Same customer again: per-customer limit.
    $again = dcOrder(user: $first->user);
    expect(fn () => app(ChangeOrderDiscounts::class)->applyCode($again, $again->user, $promo->code))->toThrow(DiscountRejected::class, 'already used');

    $second = dcOrder();
    app(ChangeOrderDiscounts::class)->applyCode($second, $second->user, $promo->code);

    $third = dcOrder();
    expect(fn () => app(ChangeOrderDiscounts::class)->applyCode($third, $third->user, $promo->code))->toThrow(DiscountRejected::class, 'used up');

    // The first order expires unpaid: its use comes back.
    $this->travel(31)->minutes();
    app(ReleaseExpiredHolds::class)->handle();
    expect($first->refresh()->status)->toBe(OrderStatus::Expired)
        ->and(PromoRedemption::query()->where('order_id', $first->id)->exists())->toBeFalse();

    $fourth = dcOrder();
    app(ChangeOrderDiscounts::class)->applyCode($fourth, $fourth->user, $promo->code);
    expect(dcLines($fourth->refresh()))->toHaveKey('promo');
});

it('keeps first-booking codes for customers who never paid', function (): void {
    $promo = PromoCode::factory()->create(['first_booking_only' => true]);
    $paid = app(FulfilOrder::class)->handle(dcOrder());
    $order = dcOrder(user: $paid->user);

    expect(fn () => app(ChangeOrderDiscounts::class)->applyCode($order, $order->user, $promo->code))->toThrow(DiscountRejected::class, 'first booking');
});

it('allows one promo per order: a new code replaces the old one', function (): void {
    $order = dcOrder();
    $a = PromoCode::factory()->create(['percent' => 10]);
    $b = PromoCode::factory()->fixed(300_000)->create();

    app(ChangeOrderDiscounts::class)->applyCode($order, $order->user, $a->code);
    app(ChangeOrderDiscounts::class)->applyCode($order, $order->user, $b->code);

    expect(dcLines($order->refresh()))->toBe(['promo' => 300_000])
        ->and(PromoRedemption::query()->where('order_id', $order->id)->sole()->promo_code_id)->toBe($b->id);
});

it('uses part of a gift voucher and keeps the rest on it', function (): void {
    $order = dcOrder(4_000);
    $voucher = Voucher::factory()->create(['initial_minor' => 1_000_000, 'balance_minor' => 1_000_000]);

    app(ChangeOrderDiscounts::class)->applyCode($order, $order->user, $voucher->formattedCode());

    expect(dcLines($order->refresh()))->toBe(['voucher' => 400_000])
        ->and($order->amount_minor)->toBe(0)
        ->and($voucher->refresh()->balance_minor)->toBe(600_000)
        ->and($voucher->status)->toBe(VoucherStatus::Active);

    // Removing it gives it all back.
    app(ChangeOrderDiscounts::class)->remove($order, $order->user, DiscountKind::Voucher);
    expect($voucher->refresh()->balance_minor)->toBe(1_000_000)->and($order->refresh()->amount_minor)->toBe(400_000);
});

it('uses a whole voucher and marks it fully used', function (): void {
    $order = dcOrder(10_000);
    $voucher = Voucher::factory()->create(['initial_minor' => 300_000, 'balance_minor' => 300_000]);

    app(ChangeOrderDiscounts::class)->applyCode($order, $order->user, $voucher->code);

    expect($order->refresh()->amount_minor)->toBe(700_000)
        ->and($voucher->refresh()->balance_minor)->toBe(0)
        ->and($voucher->status)->toBe(VoucherStatus::Redeemed);
});

it('refuses expired, cancelled and empty vouchers', function (): void {
    $order = dcOrder();
    $expired = Voucher::factory()->create(['expires_at' => now()->subDay()]);
    $empty = Voucher::factory()->create(['balance_minor' => 0, 'status' => VoucherStatus::Redeemed]);

    expect(fn () => app(ChangeOrderDiscounts::class)->applyCode($order, $order->user, $expired->code))->toThrow(DiscountRejected::class)
        ->and(fn () => app(ChangeOrderDiscounts::class)->applyCode($order, $order->user, $empty->code))->toThrow(DiscountRejected::class, 'no balance');
});

it('stacks promo, then gift voucher, then account credit', function (): void {
    $order = dcOrder(10_000, riders: 2); // PKR 20,000
    $promo = PromoCode::factory()->create(['percent' => 10]);
    $voucher = Voucher::factory()->create(['initial_minor' => 500_000, 'balance_minor' => 500_000]);
    dcCredit($order->user, 3_000);
    $change = app(ChangeOrderDiscounts::class);

    $change->applyCode($order, $order->user, $voucher->code);
    $change->useCredit($order, $order->user, true);
    $change->applyCode($order, $order->user, $promo->code);

    // 20,000 − 2,000 promo − 5,000 voucher − 3,000 credit = 10,000.
    expect(dcLines($order->refresh()))->toBe(['promo' => 200_000, 'voucher' => 500_000, 'account_credit' => 300_000])
        ->and($order->amount_minor)->toBe(1_000_000)
        ->and(app(AccountCredit::class)->balance($order->user))->toBe(0);

    $change->useCredit($order, $order->user, false);
    expect(app(AccountCredit::class)->balance($order->user))->toBe(300_000)->and($order->refresh()->amount_minor)->toBe(1_300_000);
});

it('gives back the voucher, credit and promo use when an unpaid order ends', function (): void {
    $order = dcOrder(10_000);
    $voucher = Voucher::factory()->create(['initial_minor' => 200_000, 'balance_minor' => 200_000]);
    dcCredit($order->user, 1_000);
    $change = app(ChangeOrderDiscounts::class);
    $change->applyCode($order, $order->user, PromoCode::factory()->create()->code);
    $change->applyCode($order, $order->user, $voucher->code);
    $change->useCredit($order, $order->user, true);

    $this->travel(31)->minutes();
    app(ReleaseExpiredHolds::class)->handle();

    expect($order->refresh()->status)->toBe(OrderStatus::Expired)
        ->and($voucher->refresh()->balance_minor)->toBe(200_000)
        ->and(app(AccountCredit::class)->balance($order->user))->toBe(100_000)
        ->and(PromoRedemption::query()->count())->toBe(0)
        ->and(dcLines($order))->toHaveCount(3); // lines stay for the record
});

it('returns the voucher share as credit when the voucher expired meanwhile', function (): void {
    $order = dcOrder(10_000);
    $voucher = Voucher::factory()->create(['initial_minor' => 200_000, 'balance_minor' => 200_000, 'expires_at' => now()->addMinutes(10)]);
    app(ChangeOrderDiscounts::class)->applyCode($order, $order->user, $voucher->code);

    $this->travel(31)->minutes();
    app(ReleaseExpiredHolds::class)->handle();

    expect($voucher->refresh()->balance_minor)->toBe(0)
        ->and(AccountCreditEntry::query()->where('user_id', $order->user_id)->where('reason', AccountCreditReason::VoucherReturned)->sum('delta_minor'))->toEqual(200_000);
});

it('gives the referral welcome discount on the first paid booking only, and a promo replaces it', function (): void {
    app(UpdateSettings::class)->handle([SettingKey::ReferralFriendDiscountPkr->value => 1_500]);
    $referrer = User::factory()->create(['email' => 'amna@example.com', 'phone' => '+923001111111']);
    $friend = User::factory()->create(['email' => 'sara@example.com', 'phone' => '+923002222222']);
    Referral::factory()->create(['referrer_id' => $referrer->id, 'referred_id' => $friend->id]);

    $order = dcOrder(10_000, user: $friend);
    expect(dcLines($order->refresh()))->toBe(['referral' => 150_000])->and($order->amount_minor)->toBe(850_000);

    $promo = PromoCode::factory()->create(['percent' => 20]);
    app(ChangeOrderDiscounts::class)->applyCode($order, $friend, $promo->code);
    expect(dcLines($order->refresh()))->toBe(['promo' => 200_000]);

    app(ChangeOrderDiscounts::class)->remove($order, $friend, DiscountKind::Promo);
    expect(dcLines($order->refresh()))->toBe(['referral' => 150_000]);

    app(FulfilOrder::class)->handle($order);
    expect(dcLines(dcOrder(10_000, user: $friend)))->toBe([]);
});

it('gives no welcome discount to a self or same-person referral', function (): void {
    $referrer = User::factory()->create(['email' => 'amna.k@gmail.com', 'phone' => '+923001111111']);
    $twin = User::factory()->create(['email' => 'amnak+2@gmail.com', 'phone' => '+923009999999']);
    Referral::factory()->create(['referrer_id' => $referrer->id, 'referred_id' => $twin->id]);

    expect(dcLines(dcOrder(user: $twin)))->toBe([]);
});

it('lets only the customer change discounts, and only before paying', function (): void {
    $order = dcOrder();
    $promo = PromoCode::factory()->create();

    expect(fn () => app(ChangeOrderDiscounts::class)->applyCode($order, User::factory()->create(), $promo->code))->toThrow(DiscountRejected::class);

    app(FulfilOrder::class)->handle($order);
    expect(fn () => app(ChangeOrderDiscounts::class)->applyCode($order, $order->user, $promo->code))->toThrow(DiscountRejected::class)
        ->and(fn () => app(RepriceOrder::class)->handle($order))->toThrow(DiscountRejected::class);
});

it('rounds percentages down to whole rupees', function (int $subtotal, int $percent, int $expected): void {
    $promo = PromoCode::factory()->make(['percent' => $percent]);

    expect(PromoEligibility::amountFor($promo, $subtotal))->toBe($expected);
})->with([
    [1_000_000, 10, 100_000],
    [999_900, 15, 149_900],
    [33_300, 33, 10_900],
    [500_000, 100, 500_000],
]);
