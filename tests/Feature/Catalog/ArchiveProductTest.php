<?php

declare(strict_types=1);

use App\Actions\Booking\CreateOrder;
use App\Actions\Products\ArchiveProduct;
use App\Actions\Products\DeleteProduct;
use App\Actions\Products\RestoreProduct;
use App\Events\PublicContentChanged;
use App\Exceptions\BookingRejected;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Faq;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rider;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = User::factory()->admin()->withMfa()->create();
    $this->actingAs($this->admin);
});

it('never deletes a product with orders, but archives it', function (): void {
    $product = Product::factory()->create();
    Order::factory()->for($product)->create();

    expect($this->admin->can('delete', $product))->toBeFalse()
        ->and($this->admin->can('archive', $product))->toBeTrue()
        ->and(fn () => app(DeleteProduct::class)->handle($product, $this->admin))->toThrow(LogicException::class);

    Livewire::test(ListProducts::class)
        ->assertActionHidden(TestAction::make('deleteProduct')->table($product))
        ->assertActionVisible(TestAction::make('archive')->table($product))
        ->callAction(TestAction::make('archive')->table($product));

    $product->refresh();

    expect($product->isArchived())->toBeTrue()
        ->and($product->is_active)->toBeFalse()
        ->and($product->is_featured)->toBeFalse()
        ->and(Product::query()->whereKey($product->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('event', 'archived')->where('subject_id', $product->id)->where('causer_id', $this->admin->id)->exists())->toBeTrue();
});

it('deletes a product that was never ordered', function (): void {
    $product = Product::factory()->create();
    $faq = Faq::factory()->create(['product_id' => $product->id]);

    Livewire::test(ListProducts::class)
        ->assertActionVisible(TestAction::make('deleteProduct')->table($product))
        ->callAction(TestAction::make('deleteProduct')->table($product));

    expect(Product::query()->whereKey($product->id)->exists())->toBeFalse()
        ->and($faq->refresh()->product_id)->toBeNull()
        ->and(Activity::query()->where('event', 'deleted_product')->exists())->toBeTrue();
});

it('takes archived products off the site and out of the booking flow', function (): void {
    $product = Product::factory()->create(['slug' => 'old-thing', 'name' => 'Old thing']);
    app(ArchiveProduct::class)->handle($product, $this->admin);

    $this->get('/sessions/old-thing')->assertNotFound();
    $this->get('/experiences')->assertDontSee('Old thing');
    $this->get('/prices')->assertDontSee('Old thing');

    $user = User::factory()->create();
    $rider = Rider::factory()->for($user)->create();

    expect(fn () => app(CreateOrder::class)->handle($user, $product, nearSlot()->id, [$rider->id]))
        ->toThrow(BookingRejected::class);
});

it('hides archived products from the list by default and shows them with the filter', function (): void {
    $current = Product::factory()->create();
    $archived = Product::factory()->create();
    app(ArchiveProduct::class)->handle($archived, $this->admin);

    Livewire::test(ListProducts::class)
        ->assertCanSeeTableRecords([$current])
        ->assertCanNotSeeTableRecords([$archived])
        ->filterTable('archived', true)
        ->assertCanSeeTableRecords([$archived])
        ->assertCanNotSeeTableRecords([$current]);
});

it('makes archived products read-only until restored, and restores them off sale', function (): void {
    $product = Product::factory()->create();
    Order::factory()->for($product)->create();
    app(ArchiveProduct::class)->handle($product, $this->admin);

    expect($this->admin->can('update', $product->refresh()))->toBeFalse();
    $this->get('/test-admin/products/'.$product->getRouteKey().'/edit')->assertForbidden();

    app(RestoreProduct::class)->handle($product, $this->admin);
    $product->refresh();

    expect($product->isArchived())->toBeFalse()
        ->and($product->is_active)->toBeFalse()
        ->and($this->admin->can('update', $product))->toBeTrue()
        ->and(Activity::query()->where('event', 'restored')->exists())->toBeTrue();
});

it('clears the page cache on archive, restore and delete', function (): void {
    Event::fake([PublicContentChanged::class]);

    $product = Product::factory()->create();
    app(ArchiveProduct::class)->handle($product, $this->admin);
    Event::assertDispatchedTimes(PublicContentChanged::class, 2); // factory create + archive

    app(RestoreProduct::class)->handle($product, $this->admin);
    Event::assertDispatchedTimes(PublicContentChanged::class, 3);

    app(DeleteProduct::class)->handle($product, $this->admin);
    Event::assertDispatchedTimes(PublicContentChanged::class, 4);
});

it('audits reordering and clears the page cache', function (): void {
    $a = Product::factory()->create(['sort' => 1]);
    $b = Product::factory()->create(['sort' => 2]);

    Event::fake([PublicContentChanged::class]);

    Livewire::test(ListProducts::class)->call('reorderTable', [(string) $b->id, (string) $a->id]);

    expect($b->refresh()->sort)->toBeLessThan($a->refresh()->sort)
        ->and(Activity::query()->where('event', 'reordered')->where('causer_id', $this->admin->id)->exists())->toBeTrue();
    Event::assertDispatched(PublicContentChanged::class);
});

it('keeps staff away from archiving', function (): void {
    $staff = User::factory()->staff()->withMfa()->create();
    $product = Product::factory()->create();

    expect($staff->can('archive', $product))->toBeFalse()
        ->and($staff->can('delete', $product))->toBeFalse();
});
