<?php

declare(strict_types=1);

use App\Actions\Dashboard\TodaySummary;
use App\Enums\BookingStatus;
use App\Enums\CreditPackStatus;
use App\Enums\LeadStatus;
use App\Enums\OrderStatus;
use App\Enums\SlotStatus;
use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;

function pkt(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Asia/Karachi');
}

function dashboardSlot(string $local, int $riders = 0, array $attributes = []): Slot
{
    $slot = Slot::factory()->at(pkt($local))->create($attributes);
    Booking::factory()->count($riders)->create(['slot_id' => $slot->id, 'status' => BookingStatus::Confirmed]);

    return $slot;
}

function paidOrder(string $paidLocal, int $amount, int $refunded = 0, OrderStatus $status = OrderStatus::Paid): Order
{
    $order = Order::factory()->create(['amount_minor' => $amount]);
    $order->forceFill(['status' => $status, 'paid_at' => pkt($paidLocal)->utc(), 'amount_refunded_minor' => $refunded])->save();

    return $order;
}

it('counts today and this weekend with occupancy, leaving cancelled slots out', function (): void {
    // Wednesday 11 Nov 2026, 10:00 Pakistan time.
    $summary = new TodaySummary(pkt('2026-11-11 10:00'));

    dashboardSlot('2026-11-11 08:00', 2); // today, already started: still today's
    dashboardSlot('2026-11-11 18:00', 1);
    dashboardSlot('2026-11-12 00:30', 3); // tomorrow in Pakistan (still the 11th in UTC)
    dashboardSlot('2026-11-14 08:00', 4);
    dashboardSlot('2026-11-15 15:00', 1);
    dashboardSlot('2026-11-15 08:00', 5)->forceFill(['status' => SlotStatus::Cancelled])->save();
    dashboardSlot('2026-11-21 08:00', 6);
    Booking::factory()->create(['slot_id' => Slot::query()->orderBy('starts_at')->first()->id, 'status' => BookingStatus::Cancelled]);

    expect(TodaySummary::occupancy($summary->sessionsToday()))->toBe(['sessions' => 2, 'riders' => 3, 'capacity' => 12])
        ->and(TodaySummary::occupancy($summary->sessionsThisWeekend()))->toBe(['sessions' => 2, 'riders' => 5, 'capacity' => 12]);
});

it('treats the current Saturday and Sunday as this weekend', function (): void {
    expect((new TodaySummary(pkt('2026-11-14 09:00')))->weekend()[0]->toDateString())->toBe('2026-11-14')
        ->and((new TodaySummary(pkt('2026-11-15 20:00')))->weekend()[0]->toDateString())->toBe('2026-11-14')
        ->and((new TodaySummary(pkt('2026-11-16 08:00')))->weekend()[0]->toDateString())->toBe('2026-11-21');
});

it('counts open leads due for follow-up and new leads since Monday', function (): void {
    $this->travelTo(pkt('2026-11-11 10:00'));
    Lead::factory()->create(['next_follow_up_at' => '2026-11-11']);
    Lead::factory()->create(['next_follow_up_at' => '2026-11-09']);
    Lead::factory()->create(['next_follow_up_at' => '2026-11-12']);
    Lead::factory()->status(LeadStatus::Lost)->create(['next_follow_up_at' => '2026-11-10', 'lost_reason' => 'x']);

    Lead::factory()->create()->forceFill(['created_at' => pkt('2026-11-08 23:30')->utc()])->save(); // Sunday before
    Lead::factory()->create()->forceFill(['created_at' => pkt('2026-11-09 00:10')->utc()])->save(); // Monday

    $summary = new TodaySummary(pkt('2026-11-11 10:00'));

    expect($summary->followUpsDue())->toBe(2)
        ->and($summary->newLeadsThisWeek())->toBe(4 + 1);
});

it('sums revenue for orders paid in the Pakistan month, net of refunds', function (): void {
    paidOrder('2026-11-01 00:30', 1_000_000); // 31 Oct in UTC, November in Pakistan
    paidOrder('2026-11-20 12:00', 6_000_000, 1_000_000, OrderStatus::PartiallyRefunded);
    paidOrder('2026-11-21 12:00', 1_500_000, 1_500_000, OrderStatus::Refunded);
    paidOrder('2026-10-31 23:30', 2_000_000); // October
    paidOrder('2026-10-05 10:00', 2_000_000);
    Order::factory()->status(OrderStatus::AwaitingVerification)->create(['amount_minor' => 9_000_000]);

    $summary = new TodaySummary(pkt('2026-11-25 10:00'));

    expect($summary->revenue())->toBe(6_000_000)
        ->and($summary->revenue(1))->toBe(4_000_000)
        ->and($summary->revenueChangePercent())->toBe(50);
});

it('counts active packs with credits left expiring within 14 days', function (): void {
    $now = pkt('2026-11-11 10:00')->utc();
    $this->travelTo($now);

    CreditPack::factory()->create(['expires_at' => $now->addDays(3), 'credits_total' => 8, 'credits_used' => 5]);
    CreditPack::factory()->create(['expires_at' => $now->addDays(14), 'credits_total' => 8, 'credits_used' => 7]);
    CreditPack::factory()->create(['expires_at' => $now->addDays(15), 'credits_total' => 8]);
    CreditPack::factory()->create(['expires_at' => $now->addDays(2), 'credits_total' => 8, 'credits_used' => 8]);
    CreditPack::factory()->create(['expires_at' => $now->addDays(2), 'status' => CreditPackStatus::Revoked]);
    CreditPack::factory()->create(['expires_at' => $now->subDay()]);

    expect((new TodaySummary($now))->packsExpiringSoon())->toBe(['packs' => 2, 'credits' => 4]);
});

it('shows admins every card, linking to filtered lists', function (): void {
    $this->travelTo(pkt('2026-11-11 10:00'));
    paidOrder('2026-11-02 10:00', 1_000_000);

    $this->actingAs(User::factory()->admin()->withMfa()->create())->get('/test-admin')
        ->assertOk()
        ->assertSee('Today')
        ->assertSee('Sessions today')
        ->assertSee('This weekend')
        ->assertSee('Follow-ups due')
        ->assertSee('New leads this week')
        ->assertSee('Revenue this month')
        ->assertSee('PKR 10,000')
        ->assertSee('Packs expiring in 14 days')
        ->assertSee('Transfers to verify')
        ->assertSee('filters%5Bfollow_up_due%5D%5BisActive%5D=1', false)
        ->assertSee('filters%5Bexpiring%5D%5BisActive%5D=1', false)
        ->assertSee('filters%5Bpaid%5D%5Bfrom%5D=2026-11-01', false)
        ->assertSee('filters%5Bdates%5D%5Bfrom%5D=2026-11-14', false);
});

it('shows staff sessions and leads but no money or packs', function (): void {
    $this->actingAs(User::factory()->staff()->withMfa()->create())->get('/test-admin')
        ->assertOk()
        ->assertSee('Sessions today')
        ->assertSee('Follow-ups due')
        ->assertDontSee('Revenue this month')
        ->assertDontSee('Packs expiring')
        ->assertDontSee('Transfers to verify');
});

it('opens the filtered lists the cards link to', function (): void {
    $this->travelTo(pkt('2026-11-11 10:00'));
    $admin = User::factory()->admin()->withMfa()->create();
    CreditPack::factory()->create(['expires_at' => now()->addDays(3)]);

    $this->actingAs($admin)->get('/test-admin/credit-packs?filters[expiring][isActive]=1')->assertOk();
    $this->actingAs($admin)->get('/test-admin/orders?filters[paid][from]=2026-11-01&filters[paid][until]=2026-11-30')->assertOk();
    $this->actingAs($admin)->get('/test-admin/slots?filters[upcoming][value]=&filters[dates][from]=2026-11-11&filters[dates][until]=2026-11-11')->assertOk();
    $this->actingAs(User::factory()->staff()->withMfa()->create())->get('/test-admin/credit-packs')->assertForbidden();
});
