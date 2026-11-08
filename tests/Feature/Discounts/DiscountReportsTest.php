<?php

declare(strict_types=1);

use App\Actions\Account\ExportAccountData;
use App\Actions\Booking\CreateOrder;
use App\Actions\Booking\FulfilOrder;
use App\Actions\Dashboard\TodaySummary;
use App\Actions\Discounts\ChangeOrderDiscounts;
use App\Actions\Exports\RunExport;
use App\Actions\Leads\LeadReport;
use App\Actions\Payments\RefundOrder;
use App\Enums\AccountCreditReason;
use App\Enums\ExportDataset;
use App\Enums\ExportFormat;
use App\Enums\LeadSource;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\AccountCreditEntry;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Rider;
use App\Models\User;
use App\Models\Voucher;
use App\Notifications\OrderConfirmed;
use App\Support\OrderRevenue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Paid order for 2 riders at PKR 10,000: PKR 2,000 promo, PKR 5,000 gift
 * voucher, PKR 3,000 account credit, PKR 10,000 charged.
 */
function rpOrder(?User $user = null): Order
{
    $user ??= User::factory()->create();
    $riderIds = array_map('intval', Rider::factory()->count(2)->for($user)->create()->modelKeys());
    $order = app(CreateOrder::class)->handle($user, Product::factory()->create(['price_minor' => 1_000_000, 'name' => 'Single session']), nearSlot(5, 8, ['capacity' => 6])->id, $riderIds);
    AccountCreditEntry::query()->create(['user_id' => $user->id, 'delta_minor' => 300_000, 'reason' => AccountCreditReason::Adjusted, 'note' => 'Test']);

    $change = app(ChangeOrderDiscounts::class);
    $change->applyCode($order, $user, PromoCode::factory()->fixed(200_000)->create(['code' => 'SPRING2K'])->code);
    $change->applyCode($order, $user, Voucher::factory()->create(['initial_minor' => 500_000, 'balance_minor' => 500_000])->code);
    $change->useCredit($order, $user, true);

    $order->refresh()->forceFill(['payment_method' => 'card', 'stripe_payment_intent_id' => 'pi_rp_'.$order->id])->save();

    return app(FulfilOrder::class)->handle($order)->refresh();
}

/** @return list<list<string>> */
function rpExport(ExportDataset $dataset): array
{
    $from = CarbonImmutable::now('Asia/Karachi')->subDays(30)->format('Y-m-d');
    $until = CarbonImmutable::now('Asia/Karachi')->addDays(30)->format('Y-m-d');
    $file = app(RunExport::class)->handle($dataset, CarbonImmutable::parse($from, 'Asia/Karachi'), CarbonImmutable::parse($until, 'Asia/Karachi'), ExportFormat::Csv, User::factory()->admin()->create());

    $handle = fopen($file->path, 'rb');
    if (fread($handle, 3) !== "\xEF\xBB\xBF") {
        rewind($handle);
    }
    $rows = [];
    while (($row = fgetcsv($handle, escape: '')) !== false) {
        $rows[] = $row;
    }
    fclose($handle);
    unlink($file->path);

    return $rows;
}

beforeEach(function (): void {
    Notification::fake();
});

it('counts money received plus gift voucher spent as revenue, never promo or credit', function (): void {
    $order = rpOrder();

    expect(OrderRevenue::of($order))->toBe(1_500_000) // 10,000 card + 5,000 voucher
        ->and((new TodaySummary(CarbonImmutable::now('Asia/Karachi')))->revenue())->toBe(1_500_000);

    // Refund half the card money: half the voucher goes back too.
    app(RefundOrder::class)->handle($order, User::factory()->admin()->create(), 500_000, 'Half');

    expect(OrderRevenue::of($order->refresh()))->toBe(750_000);
});

it('reports lead revenue with gift voucher spending', function (): void {
    $user = User::factory()->create();
    Lead::factory()->create(['source' => LeadSource::InstagramDm, 'user_id' => $user->id, 'created_at' => now()->subDays(3)]);
    rpOrder($user);

    $row = collect((new LeadReport(4))->bySource())->firstWhere('source', LeadSource::InstagramDm);

    expect($row['revenue_minor'])->toBe(1_500_000);
});

it('exports the subtotal, each kind of discount and the revenue per order', function (): void {
    $order = rpOrder();
    $rows = rpExport(ExportDataset::Orders);
    $header = $rows[0];
    $line = array_combine($header, collect($rows)->first(fn (array $r): bool => $r[0] === $order->reference));

    expect($line['Amount (PKR)'])->toBe('10000.00')
        ->and($line['Subtotal (PKR)'])->toBe('20000.00')
        ->and($line['Promo or referral (PKR)'])->toBe('2000.00')
        ->and($line['Gift voucher (PKR)'])->toBe('5000.00')
        ->and($line['Account credit (PKR)'])->toBe('3000.00')
        ->and($line['Revenue (PKR)'])->toBe('15000.00')
        // Existing columns keep their places.
        ->and(array_slice($header, 0, 12))->toBe(['Reference', 'Created (Pakistan time)', 'Status', 'Payment method', 'Customer', 'Customer email', 'Product', 'Type', 'Amount (PKR)', 'Refunded (PKR)', 'Net (PKR)', 'Paid at (Pakistan time)']);

    $bookings = rpExport(ExportDataset::Bookings);
    $booking = array_combine($bookings[0], $bookings[1]);

    expect($booking['Price (PKR)'])->toBe('10000.00')
        ->and($booking['Price after discounts (PKR)'])->toBe('9000.00'); // the promo shared over two riders
});

it('shows the breakdown on the receipt email, the account and the admin order page', function (): void {
    $order = rpOrder();

    $html = (string) (new OrderConfirmed($order))->toMail($order->user)->render();
    expect($html)->toContain('Promo code SPRING2K')->toContain('PKR 20,000')->toContain('Paid')->toContain('PKR 10,000');

    $this->actingAs($order->user)->get(route('account.orders.show', $order->reference))
        ->assertOk()->assertSee('Subtotal')->assertSee('Gift voucher')->assertSee('Account credit');

    Livewire::actingAs(User::factory()->admin()->withMfa()->create())->test(ViewOrder::class, ['record' => $order->reference])
        ->assertSee('Discounts')->assertSee('Promo code SPRING2K')->assertSee('Charged (after discounts)');
});

it('puts discounts in the customer data export', function (): void {
    $order = rpOrder();

    $data = app(ExportAccountData::class)->handle($order->user);
    $exported = collect($data['orders'])->firstWhere('reference', $order->reference);

    expect($exported['subtotal_minor'])->toBe(2_000_000)
        ->and(collect($exported['discounts'])->pluck('kind')->all())->toBe(['promo', 'voucher', 'account_credit']);
});
