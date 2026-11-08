<?php

declare(strict_types=1);

use App\Actions\Booking\CreateOrder;
use App\Actions\Booking\FulfilOrder;
use App\Actions\Payments\CreateStripeCheckout;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rider;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
| Price and validity changes never affect orders already placed or credit
| packs already granted (milestone 10a).
*/

function snapshotCustomer(): array
{
    $user = User::factory()->create();

    return [$user, [Rider::factory()->for($user)->create()->id]];
}

it('freezes name, type, sessions and validity on the order when it is placed', function (): void {
    [$user, $riders] = snapshotCustomer();
    $product = Product::factory()->package(sessions: 8, priceMinor: 6_000_000, validityDays: 42)->create(['name' => '8-class package']);

    $order = app(CreateOrder::class)->handle($user, $product, null, []);

    expect($order->product_name)->toBe('8-class package')
        ->and($order->product_type)->toBe(ProductType::Package)
        ->and($order->sessions_count)->toBe(8)
        ->and($order->validity_days)->toBe(42)
        ->and($order->amount_minor)->toBe(6_000_000);
});

it('grants the credits and validity bought, even if the product changed before payment', function (): void {
    [$user] = snapshotCustomer();
    $product = Product::factory()->package(sessions: 8, priceMinor: 6_000_000, validityDays: 42)->create(['name' => '8-class package']);
    $order = app(CreateOrder::class)->handle($user, $product, null, []);

    // An admin edits the package while the bank transfer waits for review.
    $product->update(['name' => '10-class package', 'price_minor' => 7_000_000, 'sessions_count' => 10, 'validity_days' => 30]);

    $this->travel(1)->days();
    app(FulfilOrder::class)->handle($order->refresh());

    $pack = CreditPack::query()->where('order_id', $order->id)->sole();
    $order->refresh();

    expect($pack->credits_total)->toBe(8)
        ->and($pack->expires_at->diffInDays($pack->valid_from))->toEqual(-42.0)
        ->and($order->amount_minor)->toBe(6_000_000)
        ->and($order->productName())->toBe('8-class package');
});

it('never changes a credit pack that already exists', function (): void {
    [$user] = snapshotCustomer();
    $product = Product::factory()->package(sessions: 8, validityDays: 42)->create();
    $order = app(CreateOrder::class)->handle($user, $product, null, []);
    app(FulfilOrder::class)->handle($order);
    $pack = CreditPack::query()->where('order_id', $order->id)->sole();
    $expires = $pack->expires_at;

    $product->update(['sessions_count' => 16, 'validity_days' => 70, 'price_minor' => 10_000_000]);

    $pack->refresh();
    expect($pack->credits_total)->toBe(8)
        ->and($pack->expires_at->equalTo($expires))->toBeTrue();
});

it('keeps the price on an order placed before a price change', function (): void {
    [$user, $riders] = snapshotCustomer();
    $product = Product::factory()->create(['price_minor' => 1_000_000]);
    $order = app(CreateOrder::class)->handle($user, $product, nearSlot()->id, $riders);

    $product->update(['price_minor' => 1_200_000]);
    app(FulfilOrder::class)->handle($order->refresh());

    expect($order->refresh()->amount_minor)->toBe(1_000_000)
        ->and($order->items()->sum('unit_price_minor'))->toEqual(1_000_000)
        ->and($order->status)->toBe(OrderStatus::Paid);
});

it('sends Stripe the name and terms as bought', function (): void {
    [$user] = snapshotCustomer();
    $product = Product::factory()->package(sessions: 8, priceMinor: 6_000_000, validityDays: 42)->create(['name' => '8-class package']);
    $order = app(CreateOrder::class)->handle($user, $product, null, []);

    $product->update(['name' => 'Renamed', 'sessions_count' => 10, 'validity_days' => 30]);

    app(CreateStripeCheckout::class)->handle($order->refresh());

    $line = $this->stripe()->created[0]['params']['line_items'][0]['price_data'];

    expect($line['product_data']['name'])->toBe('8-class package')
        ->and($line['product_data']['description'])->toBe('8 sessions, valid 42 days from payment')
        ->and($line['unit_amount'])->toBe(6_000_000);
});

it('shows the name as bought on the receipt', function (): void {
    [$user, $riders] = snapshotCustomer();
    $product = Product::factory()->create(['name' => 'Weekend session']);
    $order = app(CreateOrder::class)->handle($user, $product, nearSlot()->id, $riders);
    app(FulfilOrder::class)->handle($order);

    $product->update(['name' => 'Saturday ride']);

    $this->actingAs($user)->get(route('account.orders.show', $order->reference))
        ->assertOk()
        ->assertSee('Weekend session')
        ->assertDontSee('Saturday ride');
});

it('backfills the snapshot for orders created before it existed', function (): void {
    $order = Order::factory()->create();

    // Simulate an old row: no snapshot columns.
    Order::query()->whereKey($order->id)->update(['product_name' => null, 'product_type' => null, 'sessions_count' => null, 'validity_days' => null]);
    $old = Order::query()->findOrFail($order->id);

    expect($old->productName())->toBe($old->product->name)
        ->and($old->productType())->toBe($old->product->type);
});

it('fixes the product type once the product has orders', function (): void {
    $product = Product::factory()->create();
    Order::factory()->for($product)->create();

    expect(fn () => $product->update(['type' => ProductType::Private]))
        ->toThrow(LogicException::class);

    $fresh = Product::factory()->create();
    $fresh->update(['type' => ProductType::Private]);
    expect($fresh->refresh()->type)->toBe(ProductType::Private);
});

it('disables the type field in the form for a sold product', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->admin()->withMfa()->create());

    $product = Product::factory()->create();
    Order::factory()->for($product)->create();

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertFormFieldIsDisabled('type')
        ->assertSee('Changes apply to new orders only');
});
