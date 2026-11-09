<?php

declare(strict_types=1);

use App\Actions\Booking\CreateOrder;
use App\Actions\Discounts\ChangeOrderDiscounts;
use App\Enums\SlotStatus;
use App\Models\Booking;
use App\Models\Post;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Review;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use App\Models\Voucher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/*
 * Query budgets for the key pages (milestone 11), rendered on a cache miss
 * with realistic data: every seeded slot with riders in it, a dozen
 * approved reviews per product. A page that starts loading per row (N+1)
 * repeats one query shape and fails here, whatever the page size.
 */

beforeEach(function (): void {
    Notification::fake();
    $this->seed();
    // A Wednesday, so "this weekend" and the schedule have sessions.
    $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00', 'Asia/Karachi'));

    foreach (Slot::query()->where('status', SlotStatus::Open)->where('starts_at', '>', now())->limit(12)->get() as $slot) {
        Booking::factory()->count(2)->create(['slot_id' => $slot->id]);
    }

    foreach (Product::query()->get() as $product) {
        Review::factory()->count(12)->approved()->forProduct($product)->create();
    }
});

it('renders each public page within its query budget, with no N+1', function (string $url, int $budget): void {
    assertQueryBudget(fn () => $this->get($url)->assertOk(), $budget);
})->with([
    'home' => ['/', 10],
    'experiences' => ['/experiences', 7],
    'session page' => ['/sessions/single-session', 12],
    'package page' => ['/sessions/8-class-package', 12],
    'schedule' => ['/schedule', 8],
    'schedule, private' => ['/schedule?type=private', 8],
    'prices' => ['/prices', 8],
    'faq' => ['/faq', 7],
    'guides' => ['/guides', 6],
    'gift vouchers' => ['/gift-vouchers', 5],
    'booking flow' => ['/book?product=single-session', 8],
]);

it('renders a guide within its budget', function (): void {
    $post = Post::query()->firstOrFail();
    $post->forceFill(['published_at' => now()->subDay()])->save();
    $slug = $post->slug;

    assertQueryBudget(fn () => $this->get('/guides/'.$slug)->assertOk(), 7);
});

it('renders the checkout page with discounts within its budget', function (): void {
    $user = User::factory()->create();
    $riders = Rider::factory()->count(3)->for($user)->create();
    $slot = Slot::query()->where('status', SlotStatus::Open)->where('starts_at', '>', now()->addDays(2))->orderBy('starts_at')->firstOrFail();
    $order = app(CreateOrder::class)->handle($user, Product::query()->where('slug', 'single-session')->firstOrFail(), $slot->id, array_map('intval', $riders->modelKeys()));
    app(ChangeOrderDiscounts::class)->applyCode($order, $user, PromoCode::factory()->create()->code);
    app(ChangeOrderDiscounts::class)->applyCode($order, $user, Voucher::factory()->create(['initial_minor' => 100_000, 'balance_minor' => 100_000])->code);

    $this->actingAs($user);

    assertQueryBudget(fn () => $this->get(route('checkout.show', $order->reference))->assertOk()->assertSee('Gift voucher'), 14);
});
