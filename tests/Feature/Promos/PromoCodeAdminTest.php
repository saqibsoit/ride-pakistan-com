<?php

declare(strict_types=1);

use App\Actions\Booking\CreateOrder;
use App\Actions\Promos\ArchivePromoCode;
use App\Actions\Promos\SavePromoCode;
use App\Enums\DiscountType;
use App\Filament\Resources\PromoCodes\Pages\CreatePromoCode;
use App\Filament\Resources\PromoCodes\Pages\EditPromoCode;
use App\Filament\Resources\PromoCodes\Pages\ListPromoCodes;
use App\Filament\Resources\PromoCodes\PromoCodeResource;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

function promoAdmin(): User
{
    return User::factory()->admin()->withMfa()->create();
}

/** A code with one recorded use (Part B writes these at checkout). */
function usedPromo(array $attributes = []): PromoCode
{
    $promo = PromoCode::factory()->create($attributes);
    $order = Order::factory()->create();
    PromoRedemption::query()->create(['promo_code_id' => $promo->id, 'order_id' => $order->id, 'user_id' => $order->user_id, 'amount_minor' => 100_000]);

    return $promo;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function promoData(array $overrides = []): array
{
    return [
        'code' => 'eid25',
        'discount_type' => 'percent',
        'percent' => 25,
        'product_ids' => [],
        'is_active' => true,
        'first_booking_only' => false,
        ...$overrides,
    ];
}

it('saves a percentage code in capitals with its rules', function (): void {
    $product = Product::factory()->create();

    $promo = app(SavePromoCode::class)->handle(null, promoData([
        'starts_at' => '2026-12-01 00:00:00',
        'ends_at' => '2026-12-31 23:59:00',
        'max_uses' => 50,
        'max_uses_per_customer' => 1,
        'min_order_pkr' => 20000,
        'first_booking_only' => true,
        'product_ids' => [$product->id],
    ]), promoAdmin());

    expect($promo->code)->toBe('EID25')
        ->and($promo->discount_type)->toBe(DiscountType::Percent)
        ->and($promo->percent)->toBe(25)
        ->and($promo->amount_minor)->toBeNull()
        ->and($promo->min_order_minor)->toBe(2_000_000)
        ->and($promo->first_booking_only)->toBeTrue()
        ->and($promo->products()->pluck('products.id')->all())->toBe([$product->id])
        ->and(Activity::query()->where('log_name', 'promos')->where('event', 'created')->exists())->toBeTrue();
});

it('saves a fixed code in paisa', function (): void {
    $promo = app(SavePromoCode::class)->handle(null, promoData(['code' => 'WELCOME', 'discount_type' => 'fixed', 'percent' => 50, 'amount_pkr' => 1500]), promoAdmin());

    expect($promo->amount_minor)->toBe(150_000)->and($promo->percent)->toBeNull();
});

it('rejects invalid codes', function (array $overrides, string $field): void {
    PromoCode::factory()->create(['code' => 'TAKEN']);

    expect(fn () => app(SavePromoCode::class)->handle(null, promoData($overrides), promoAdmin()))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey($field));
})->with([
    'too short' => [['code' => 'ab'], 'code'],
    'bad characters' => [['code' => 'EID 25!'], 'code'],
    'duplicate (any case)' => [['code' => 'taken'], 'code'],
    'percent over 100' => [['percent' => 101], 'percent'],
    'percent zero' => [['percent' => 0], 'percent'],
    'fixed without amount' => [['discount_type' => 'fixed'], 'amount_pkr'],
    'end before start' => [['starts_at' => '2026-12-10 00:00', 'ends_at' => '2026-12-01 00:00'], 'ends_at'],
    'zero uses' => [['max_uses' => 0], 'max_uses'],
    'unknown product' => [['product_ids' => [999_999]], 'product_ids.0'],
]);

it('locks the terms of a used code but lets dates and limits change', function (): void {
    $promo = usedPromo(['code' => 'USED10', 'percent' => 10]);
    $admin = promoAdmin();

    expect(fn () => app(SavePromoCode::class)->handle($promo, promoData(['code' => 'USED10', 'percent' => 20]), $admin))
        ->toThrow(ValidationException::class);
    expect(fn () => app(SavePromoCode::class)->handle($promo, promoData(['code' => 'USED10', 'percent' => 10, 'max_uses' => 0]), $admin))
        ->toThrow(ValidationException::class);

    app(SavePromoCode::class)->handle($promo, promoData(['code' => 'USED10', 'percent' => 10, 'max_uses' => 5, 'is_active' => false]), $admin);

    expect($promo->refresh()->max_uses)->toBe(5)->and($promo->is_active)->toBeFalse();
});

it('deletes an unused code but archives a used one', function (): void {
    $unused = PromoCode::factory()->create();
    $used = usedPromo();
    $admin = promoAdmin();

    expect(app(ArchivePromoCode::class)->handle($unused, $admin))->toBeTrue()
        ->and(PromoCode::query()->whereKey($unused->id)->exists())->toBeFalse()
        ->and(app(ArchivePromoCode::class)->handle($used, $admin))->toBeFalse();

    $used->refresh();
    expect($used->isArchived())->toBeTrue()
        ->and($used->is_active)->toBeFalse()
        ->and($used->statusLabel())->toBe('Archived');

    app(ArchivePromoCode::class)->restore($used, $admin);
    expect($used->refresh()->isArchived())->toBeFalse()->and($used->statusLabel())->toBe('Inactive');
});

it('labels the status from the window and usage', function (): void {
    $now = CarbonImmutable::now();

    expect(PromoCode::factory()->create()->statusLabel())->toBe('Active')
        ->and(PromoCode::factory()->create(['starts_at' => $now->addDay()])->statusLabel())->toBe('Scheduled')
        ->and(PromoCode::factory()->create(['ends_at' => $now->subMinute()])->statusLabel())->toBe('Ended')
        ->and(usedPromo(['max_uses' => 1])->statusLabel())->toBe('Used up');
});

it('creates, edits and archives codes in the admin panel', function (): void {
    $this->actingAs(promoAdmin());

    Livewire::test(CreatePromoCode::class)
        ->fillForm(['code' => 'bad code', 'discount_type' => 'percent', 'percent' => 10])
        ->call('create')
        ->assertHasFormErrors(['code']);

    Livewire::test(CreatePromoCode::class)
        ->fillForm(['code' => 'spring', 'discount_type' => 'fixed', 'amount_pkr' => 2000, 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    $promo = PromoCode::query()->where('code', 'SPRING')->sole();
    expect($promo->amount_minor)->toBe(200_000);

    Livewire::test(EditPromoCode::class, ['record' => $promo->id])
        ->assertFormSet(['amount_pkr' => 2000])
        ->fillForm(['max_uses' => 10])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($promo->refresh()->max_uses)->toBe(10);

    $used = usedPromo(['code' => 'LOCKED']);
    Livewire::test(EditPromoCode::class, ['record' => $used->id])
        ->assertFormFieldIsDisabled('code')
        ->fillForm(['max_uses_per_customer' => 2])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($used->refresh()->max_uses_per_customer)->toBe(2)->and($used->code)->toBe('LOCKED');

    Livewire::test(ListPromoCodes::class)
        ->callAction(TestAction::make('archive')->table($used));

    expect($used->refresh()->isArchived())->toBeTrue();
});

it('keeps promo codes from staff and trainers', function (string $role): void {
    $this->actingAs(User::factory()->{$role}()->withMfa()->create())
        ->get(PromoCodeResource::getUrl())
        ->assertForbidden();
})->with(['staff', 'trainer']);

it('does not touch how orders are priced', function (): void {
    // Part A only manages codes: an order still costs its product price.
    PromoCode::factory()->create(['code' => 'ALL50', 'percent' => 50]);
    $user = User::factory()->create();
    $rider = Rider::factory()->for($user)->create();
    $product = Product::factory()->create(['price_minor' => 1_000_000]);
    $slot = Slot::factory()->create();

    $order = app(CreateOrder::class)->handle($user, $product, $slot->id, [$rider->id]);

    expect($order->amount_minor)->toBe(1_000_000);
});
