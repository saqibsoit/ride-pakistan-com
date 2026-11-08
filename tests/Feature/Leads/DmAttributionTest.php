<?php

declare(strict_types=1);

use App\Enums\LeadStatus;
use App\Enums\OrderStatus;
use App\Events\OrderPaid;
use App\Http\Middleware\CaptureLeadReference;
use App\Livewire\Book\BookingFlow;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rider;
use App\Models\User;
use App\Support\LeadRef;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

function dmPaidOrder(User $customer, ?int $leadId): Order
{
    $order = Order::factory()->status(OrderStatus::Paid)->create(['user_id' => $customer->id, 'meta' => $leadId === null ? [] : ['lead_id' => $leadId]]);
    event(new OrderPaid($order));

    return $order;
}

beforeEach(function (): void {
    Product::factory()->create(['slug' => 'single-session', 'name' => 'Single session', 'price_minor' => 1_000_000]);
});

it('keeps a signed lead reference in the session and redirects to the clean URL', function (): void {
    $lead = Lead::factory()->create();

    $this->get('/book?'.http_build_query([...LeadRef::query($lead), 'product' => 'single-session']))
        ->assertRedirect(route('book').'?product=single-session');

    expect(session(CaptureLeadReference::SESSION_KEY))->toBe($lead->id);
});

it('works on full-page cached pages too', function (): void {
    $lead = Lead::factory()->create();
    $url = route('sessions.show', 'single-session');

    $this->get($url)->assertOk(); // warm the cache
    $this->get($url.'?'.http_build_query(LeadRef::query($lead)))->assertRedirect($url);

    expect(session(CaptureLeadReference::SESSION_KEY))->toBe($lead->id);
});

it('ignores forged references but still cleans the URL', function (): void {
    $lead = Lead::factory()->create();

    $this->get('/book?ref=ig-dm&lead='.$lead->id)->assertRedirect(route('book'));
    $this->get('/book?ref=ig-dm&lead='.$lead->id.'-aaaaaaaaaa')->assertRedirect(route('book'));

    expect(session(CaptureLeadReference::SESSION_KEY))->toBeNull();
});

it('puts the lead on the order placed in that visit', function (): void {
    $lead = Lead::factory()->create();
    $user = User::factory()->create();
    $rider = Rider::factory()->for($user)->create();
    $slot = nearSlot();

    session([CaptureLeadReference::SESSION_KEY => $lead->id]);

    Livewire::actingAs($user)->test(BookingFlow::class)
        ->call('chooseProduct', 'single-session')
        ->call('chooseSlot', $slot->id)
        ->set('riderIds', [$rider->id])
        ->call('confirmRiders')
        ->call('placeOrder');

    expect(Order::query()->sole()->meta['lead_id'])->toBe($lead->id);
});

it('books and links the lead when the order from its link is paid', function (): void {
    $lead = Lead::factory()->instagramDm()->create(['email' => null]);
    $customer = User::factory()->create(['email' => 'someone-else@example.com']);

    $order = dmPaidOrder($customer, $lead->id);

    $lead->refresh();
    expect($lead->status)->toBe(LeadStatus::Booked)
        ->and($lead->user_id)->toBe($customer->id)
        ->and($lead->booked_order_id)->toBe($order->id);
    expect(Activity::query()->where('event', 'linked_from_dm_link')->where('subject_id', $lead->id)->exists())->toBeTrue();
});

it('brings a lost lead back as booked', function (): void {
    $lead = Lead::factory()->status(LeadStatus::Lost)->create(['lost_reason' => 'No reply', 'email' => null]);

    dmPaidOrder(User::factory()->create(), $lead->id);

    expect($lead->refresh()->status)->toBe(LeadStatus::Booked);
});

it('leaves a lead that belongs to another customer alone and logs it', function (): void {
    $owner = User::factory()->create();
    $lead = Lead::factory()->create(['user_id' => $owner->id, 'email' => null]);

    dmPaidOrder(User::factory()->create(), $lead->id);

    $lead->refresh();
    expect($lead->user_id)->toBe($owner->id)
        ->and($lead->status)->not->toBe(LeadStatus::Booked);
    expect(Activity::query()->where('event', 'lead_ref_mismatch')->where('subject_id', $lead->id)->exists())->toBeTrue();
});

it('does nothing for unpaid orders', function (): void {
    $lead = Lead::factory()->create(['email' => null]);
    Order::factory()->status(OrderStatus::Expired)->create(['meta' => ['lead_id' => $lead->id]]);

    expect($lead->refresh()->status)->toBe(LeadStatus::New)
        ->and($lead->user_id)->toBeNull();
});
