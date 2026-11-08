<?php

declare(strict_types=1);

use App\Actions\Booking\CreateOrder;
use App\Actions\Booking\FulfilOrder;
use App\Actions\Reviews\ChangeReviewStatus;
use App\Actions\Reviews\ReplyToReview;
use App\Actions\Reviews\SubmitReview;
use App\Actions\Settings\UpdateSettings;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\ReviewStatus;
use App\Enums\SettingKey;
use App\Exceptions\BookingRejected;
use App\Exceptions\InvalidStatusTransition;
use App\Filament\Resources\Reviews\Pages\ListReviews;
use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use App\Notifications\ThankYou;
use App\Support\ReviewLink;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/** An attended booking two days ago, paid as a single session of $product. */
function attendedBooking(?Product $product = null, string $customer = 'Ayesha Khan'): Booking
{
    static $day = 0;
    $day++;
    $user = User::factory()->create(['name' => $customer]);
    $rider = Rider::factory()->for($user)->create();
    $product ??= Product::factory()->create();
    $slot = Slot::factory()->at(CarbonImmutable::now('Asia/Karachi')->addDays(2 + $day)->setTime(8, 0))->create();
    $order = app(FulfilOrder::class)->handle(app(CreateOrder::class)->handle($user, $product, $slot->id, [$rider->id]));
    $booking = Booking::query()->where('order_id', $order->id)->sole();
    $booking->forceFill(['status' => BookingStatus::Attended])->save();

    return $booking->refresh();
}

/** @param  array<string, mixed>  $data */
function postReview(Booking $booking, array $data): TestResponse
{
    return test()->post(ReviewLink::for($booking), $data);
}

it('reviews an attended session from the signed link without signing in', function (): void {
    $product = Product::factory()->create();
    $booking = attendedBooking($product);

    $this->get(ReviewLink::for($booking))->assertOk()->assertSee('How was the ride?')->assertSee('noindex', false);

    postReview($booking, ['rating' => 5, 'body' => 'Calm horses and a patient trainer.'])->assertRedirect();

    $review = Review::query()->sole();
    expect($review->status)->toBe(ReviewStatus::Pending)
        ->and($review->rating)->toBe(5)
        ->and($review->product_id)->toBe($product->id)
        ->and($review->display_name)->toBe('Ayesha K.')
        ->and(Activity::query()->where('log_name', 'reviews')->where('event', 'submitted')->exists())->toBeTrue();
});

it('refuses tampered, expired and unsigned links', function (): void {
    $booking = attendedBooking();
    $url = ReviewLink::for($booking);

    $this->get(route('reviews.create', ['ticket' => $booking->ticket_code]))->assertForbidden();
    $this->get(str_replace($booking->ticket_code, 'ABCDEFGHJKLM', $url))->assertForbidden();
    $this->get($url.'x')->assertForbidden();

    $this->travel(ReviewLink::VALID_DAYS + 1)->days();
    $this->get($url)->assertForbidden();
    $this->post($url, ['rating' => 5, 'body' => 'Late'])->assertForbidden();
    expect(Review::query()->count())->toBe(0);
});

it('only reviews attended sessions, once per customer per session, editable while pending', function (): void {
    $booking = attendedBooking();
    $confirmed = Booking::factory()->create(['status' => BookingStatus::Confirmed]);

    $this->get(ReviewLink::for($confirmed))->assertOk()->assertSee('Reviews open after the session.');
    expect(fn () => app(SubmitReview::class)->handle($confirmed, 5, 'Nice'))->toThrow(BookingRejected::class);

    app(SubmitReview::class)->handle($booking, 4, 'Good');
    app(SubmitReview::class)->handle($booking, 5, 'Great, actually!');

    expect(Review::query()->count())->toBe(1)
        ->and(Review::query()->sole()->body)->toBe('Great, actually!');

    app(ChangeReviewStatus::class)->handle(Review::query()->sole(), ReviewStatus::Approved, User::factory()->admin()->create());

    expect(fn () => app(SubmitReview::class)->handle($booking, 1, 'Changed my mind'))->toThrow(BookingRejected::class);
    $this->get(ReviewLink::for($booking))->assertSee('we already have your review');
});

it('ignores honeypot submissions and validates the form', function (): void {
    $booking = attendedBooking();

    postReview($booking, ['rating' => 5, 'body' => 'Buy now', 'website' => 'http://spam.example'])->assertRedirect();
    postReview($booking, ['rating' => 9, 'body' => ''])->assertSessionHasErrors(['rating', 'body']);
    postReview($booking, ['rating' => 3, 'body' => str_repeat('a', Review::BODY_MAX_LENGTH + 1)])->assertSessionHasErrors('body');

    expect(Review::query()->count())->toBe(0);
});

it('counts a credit session towards the package that paid for it', function (): void {
    $package = Product::factory()->create(['name' => 'Eight pack']);
    $user = User::factory()->create();
    $pack = CreditPack::factory()->create(['order_id' => Order::factory()->for($user)->status(OrderStatus::Paid)->create(['product_id' => $package->id])]);
    $booking = Booking::factory()->create(['user_id' => $user->id, 'rider_id' => Rider::factory()->for($user), 'credit_pack_id' => $pack->id, 'status' => BookingStatus::Attended]);

    expect(app(SubmitReview::class)->handle($booking, 5, 'Lovely')->product_id)->toBe($package->id);
});

it('shows the Google link after the review only when it is set', function (): void {
    $booking = attendedBooking();
    postReview($booking, ['rating' => 5, 'body' => 'Lovely morning.']);

    $this->get(ReviewLink::for($booking))->assertSee('Thank you.')->assertDontSee('Review us on Google');

    app(UpdateSettings::class)->handle([SettingKey::GoogleReviewUrl->value => 'https://g.page/r/ride-pakistan/review']);
    $this->withSession(['status' => 'review-saved'])->get(ReviewLink::for($booking))
        ->assertSee('Review us on Google')->assertSee('https://g.page/r/ride-pakistan/review', false);
});

it('puts the signed review link in the thank-you email and on past bookings', function (): void {
    $booking = attendedBooking();

    $html = (string) (new ThankYou($booking->slot, Booking::query()->whereKey($booking->id)->get()))->toMail($booking->user)->render();

    expect($html)->toContain('Rate your session')
        ->and($html)->toContain(e(route('reviews.create', ['ticket' => $booking->ticket_code])));

    $this->travel(5)->days();
    $this->actingAs($booking->user)->get('/account/bookings')->assertOk()->assertSee('Leave a review');
});

it('lets only admins moderate, needs a reason to hide, and logs it', function (): void {
    $review = Review::factory()->create();
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->staff()->create();

    expect(fn () => app(ChangeReviewStatus::class)->handle($review, ReviewStatus::Approved, $staff))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ChangeReviewStatus::class)->handle($review, ReviewStatus::Hidden, $admin))->toThrow(InvalidStatusTransition::class)
        ->and(fn () => app(ReplyToReview::class)->handle($review, 'Thanks!', $staff))->toThrow(AuthorizationException::class);

    app(ChangeReviewStatus::class)->handle($review, ReviewStatus::Hidden, $admin, 'Personal details in the text');
    expect($review->refresh()->status)->toBe(ReviewStatus::Hidden)->and($review->hidden_reason)->toBe('Personal details in the text');

    app(ChangeReviewStatus::class)->handle($review, ReviewStatus::Approved, $admin);
    app(ReplyToReview::class)->handle($review, 'Thank you, see you on the trail.', $admin);

    expect($review->refresh()->status)->toBe(ReviewStatus::Approved)
        ->and($review->hidden_reason)->toBeNull()
        ->and($review->reply)->toBe('Thank you, see you on the trail.')
        ->and($review->moderated_by)->toBe($admin->id)
        ->and(Activity::query()->where('log_name', 'reviews')->where('event', 'status_changed')->count())->toBe(2);

    expect(fn () => app(ChangeReviewStatus::class)->handle($review, ReviewStatus::Pending, $admin))->toThrow(InvalidStatusTransition::class);
});

it('moderates from the admin list; staff see it read-only', function (): void {
    $review = Review::factory()->create();
    $admin = User::factory()->admin()->withMfa()->create();

    Livewire::actingAs($admin)->test(ListReviews::class)
        ->assertCanSeeTableRecords([$review])
        ->callTableAction('reply', $review, ['reply' => 'Thank you!'])
        ->callTableAction('approve', $review);

    expect($review->refresh()->status)->toBe(ReviewStatus::Approved)->and($review->reply)->toBe('Thank you!');

    Livewire::actingAs(User::factory()->staff()->withMfa()->create())->test(ListReviews::class)
        ->filterTable('status', ReviewStatus::Approved->value)
        ->assertCanSeeTableRecords([$review])
        ->assertTableActionHidden('hide', $review)
        ->assertTableActionHidden('reply', $review);
});

it('shows only approved reviews on the homepage and their product page, with the reply', function (): void {
    $this->seed();
    $product = Product::query()->where('slug', 'single-session')->firstOrFail();

    Review::factory()->approved()->forProduct($product)->create(['body' => 'Approved and lovely', 'display_name' => 'Sara M.']);
    Review::factory()->forProduct($product)->create(['body' => 'Still pending']);
    Review::factory()->forProduct($product)->create(['body' => 'Hidden one', 'status' => ReviewStatus::Hidden]);
    $reply = Review::factory()->approved()->forProduct($product)->create(['body' => 'With a reply', 'reply' => 'Thanks from the team']);
    Review::factory()->approved()->create(['body' => 'No product review']);

    $this->get('/')->assertOk()->assertSee('What riders say.')->assertSee('Approved and lovely')->assertSee('No product review')
        ->assertDontSee('Still pending')->assertDontSee('Hidden one');

    $this->get(route('sessions.show', $product))->assertOk()
        ->assertSee('Approved and lovely')->assertSee('Sara M.')->assertSee('Thanks from the team')
        ->assertSee('from 2 reviews')
        ->assertDontSee('No product review')->assertDontSee('Still pending')->assertDontSee('Hidden one');

    expect($reply->exists)->toBeTrue();
});

it('adds AggregateRating to the product page only from 5 approved reviews, never on the homepage', function (): void {
    $this->seed();
    $product = Product::query()->where('slug', 'single-session')->firstOrFail();
    Review::factory()->count(4)->approved()->forProduct($product)->create(['rating' => 5]);
    Review::factory()->count(3)->forProduct($product)->create(['rating' => 1]); // pending: never counted

    $types = fn (string $url): array => jsonLdTypes(jsonLdGraph((string) $this->get($url)->getContent()));

    expect($types(route('sessions.show', $product)))->not->toContain('AggregateRating');

    Review::factory()->approved()->forProduct($product)->create(['rating' => 4]);

    $graph = jsonLdGraph((string) $this->get(route('sessions.show', $product))->getContent());
    $node = collect($graph)->firstWhere('@type', 'Product');

    expect($node['aggregateRating'])->toMatchArray(['@type' => 'AggregateRating', 'ratingValue' => 4.8, 'reviewCount' => 5, 'bestRating' => 5])
        ->and($node['review'])->toHaveCount(5)
        ->and($node['review'][0]['@type'])->toBe('Review')
        ->and($node['review'][0]['reviewRating']['@type'])->toBe('Rating');

    expect($types('/'))->not->toContain('AggregateRating')->not->toContain('Review')
        ->and($types('/prices'))->not->toContain('AggregateRating');
});

it('clears the page cache when a review is approved', function (): void {
    $this->seed();
    config(['responsecache.enabled' => true]);
    $review = Review::factory()->create(['body' => 'Fresh from the trail']);

    $this->get('/')->assertDontSee('Fresh from the trail');

    app(ChangeReviewStatus::class)->handle($review, ReviewStatus::Approved, User::factory()->admin()->create());

    $this->get('/')->assertSee('Fresh from the trail');
});
