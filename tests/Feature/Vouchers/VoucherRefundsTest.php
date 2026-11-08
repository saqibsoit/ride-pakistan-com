<?php

declare(strict_types=1);

use App\Actions\Exports\RunExport;
use App\Actions\Vouchers\CreateVoucherCheckout;
use App\Actions\Vouchers\CreateVoucherOrder;
use App\Actions\Vouchers\RecordVoucherTransferRefund;
use App\Actions\Vouchers\VoucherOrderStateMachine;
use App\Enums\ExportDataset;
use App\Enums\ExportFormat;
use App\Enums\PaymentMethod;
use App\Enums\VoucherLedgerReason;
use App\Enums\VoucherOrderStatus;
use App\Enums\VoucherStatus;
use App\Exceptions\BookingRejected;
use App\Filament\Resources\VoucherOrders\Pages\ListVoucherOrders;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeStripeGateway;

/** A PKR $pkr gift voucher bought by bank transfer, paid and issued, with $spentPkr already spent. */
function vrPurchase(int $pkr = 10_000, int $spentPkr = 0): VoucherOrder
{
    $order = VoucherOrder::factory()->status(VoucherOrderStatus::Paid)->create([
        'amount_minor' => $pkr * 100,
        'payment_method' => PaymentMethod::BankTransfer,
        'transaction_ref' => 'IN-'.random_int(1000, 9999),
    ]);
    Voucher::factory()->create([
        'voucher_order_id' => $order->id,
        'initial_minor' => $pkr * 100,
        'balance_minor' => ($pkr - $spentPkr) * 100,
    ]);

    return $order->refresh();
}

function vrAdmin(): User
{
    return User::factory()->admin()->withMfa()->create();
}

function vrToday(): CarbonImmutable
{
    return CarbonImmutable::now('Asia/Karachi')->startOfDay();
}

/** @return array<string, array<string, string>> voucher sales export rows by purchase reference */
function vrSalesExport(): array
{
    $file = app(RunExport::class)->handle(ExportDataset::VoucherSales, vrToday()->subDays(2), vrToday()->addDay(), ExportFormat::Csv, vrAdmin());
    $rows = array_map(fn (string $line): array => str_getcsv($line, escape: ''), array_values(array_filter(explode("\n", ltrim((string) file_get_contents($file->path), "\xEF\xBB\xBF")))));
    unlink($file->path);

    return collect(array_slice($rows, 1))->mapWithKeys(fn (array $r): array => [$r[0] => array_combine($rows[0], $r)])->all();
}

beforeEach(function (): void {
    Notification::fake();
});

it('records a late payment on a cancelled purchase and its automatic refund, so the export matches the bank', function (): void {
    $order = app(CreateVoucherOrder::class)->handle(User::factory()->create(), null, 500_000, 'Sara Khan', 'sara@example.com', null, null);
    app(CreateVoucherCheckout::class)->handle($order);
    $order->refresh();
    app(VoucherOrderStateMachine::class)->transition($order, VoucherOrderStatus::Cancelled, reason: 'Changed mind');

    $session = [
        'id' => $order->stripe_checkout_session_id, 'object' => 'checkout.session', 'status' => 'complete', 'payment_status' => 'paid',
        'client_reference_id' => 'voucher:'.$order->id, 'metadata' => ['kind' => 'voucher', 'reference' => $order->reference, 'voucher_order_id' => (string) $order->id],
        'amount_total' => 500_000, 'currency' => 'pkr', 'payment_intent' => 'pi_late_'.$order->id,
    ];
    $payload = (string) json_encode(FakeStripeGateway::event('checkout.session.completed', $session));
    $this->call('POST', '/webhooks/stripe', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => FakeStripeGateway::sign($payload)], content: $payload)->assertOk();

    $order->refresh();
    expect($order->status)->toBe(VoucherOrderStatus::Cancelled)
        ->and($order->paid_at)->not->toBeNull()
        ->and($order->receivedMinor())->toBe(500_000)
        ->and($order->refundedMinor())->toBe(500_000)
        ->and($order->refundEntries())->toHaveCount(1);

    $row = vrSalesExport()[$order->reference];

    expect($row['Status'])->toBe('Cancelled')
        ->and($row['Payment reference'])->toBe('pi_late_'.$order->id)
        ->and($row['Received (PKR)'])->toBe('5000.00')
        ->and($row['Refunded (PKR)'])->toBe('5000.00')
        ->and($row['Net cash (PKR)'])->toBe('0.00')
        ->and($row['Refund references'])->toStartWith('re_test_')
        ->and($row['Refunded on'])->toBe(CarbonImmutable::now()->toDateString());
});

it('refunds an unspent voucher bought by transfer and voids it', function (): void {
    $order = vrPurchase(10_000);
    $voucher = $order->voucher()->firstOrFail();

    app(RecordVoucherTransferRefund::class)->handle($order, vrAdmin(), 1_000_000, 'HBL-GV-1', vrToday(), 'Gift not wanted');

    expect($order->refresh()->status)->toBe(VoucherOrderStatus::Refunded)
        ->and($order->refundedMinor())->toBe(1_000_000)
        ->and($voucher->refresh()->balance_minor)->toBe(0)
        ->and($voucher->status)->toBe(VoucherStatus::Void)
        ->and($voucher->ledger()->where('reason', VoucherLedgerReason::Voided)->sum('delta_minor'))->toEqual(-1_000_000)
        ->and(Activity::query()->where('log_name', 'vouchers')->where('event', 'refunded')->exists())->toBeTrue();

    $row = vrSalesExport()[$order->reference];
    expect($row['Refunded (PKR)'])->toBe('10000.00')
        ->and($row['Net cash (PKR)'])->toBe('0.00')
        ->and($row['Refund references'])->toBe('HBL-GV-1')
        ->and($row['Refunded on'])->toBe(vrToday()->toDateString());
});

it('refunds part of a partly spent voucher, never more than the unspent balance', function (): void {
    $order = vrPurchase(10_000, spentPkr: 7_000);
    $voucher = $order->voucher()->firstOrFail();

    expect(RecordVoucherTransferRefund::refundableMinor($order))->toBe(300_000)
        ->and(fn () => app(RecordVoucherTransferRefund::class)->handle($order, vrAdmin(), 300_100, 'X', vrToday(), 'Too much'))->toThrow(BookingRejected::class, 'at most PKR 3,000');

    app(RecordVoucherTransferRefund::class)->handle($order, vrAdmin(), 100_000, 'MCB-1', vrToday(), 'Part');

    expect($voucher->refresh()->balance_minor)->toBe(200_000)
        ->and($voucher->status)->toBe(VoucherStatus::Active)
        ->and($order->refresh()->status)->toBe(VoucherOrderStatus::Paid);

    app(RecordVoucherTransferRefund::class)->handle($order, vrAdmin(), 200_000, 'MCB-2', vrToday(), 'Rest of the balance');

    // 7,000 was spent: the purchase stays paid, with nothing left to refund.
    expect($voucher->refresh()->status)->toBe(VoucherStatus::Void)
        ->and($order->refresh()->status)->toBe(VoucherOrderStatus::Paid)
        ->and(RecordVoucherTransferRefund::refundableMinor($order))->toBe(0);
});

it('refuses what it shouldn’t record for voucher purchases', function (Closure $call, string $message): void {
    expect($call)->toThrow(BookingRejected::class, $message);
})->with([
    'staff' => [fn () => app(RecordVoucherTransferRefund::class)->handle(vrPurchase(), User::factory()->staff()->create(), 100_000, 'X', vrToday(), 'R'), 'Only admins'],
    'no reference' => [fn () => app(RecordVoucherTransferRefund::class)->handle(vrPurchase(), vrAdmin(), 100_000, ' ', vrToday(), 'R'), 'bank reference'],
    'future date' => [fn () => app(RecordVoucherTransferRefund::class)->handle(vrPurchase(), vrAdmin(), 100_000, 'X', vrToday()->addDay(), 'R'), 'future'],
    'card purchase' => [function (): void {
        $order = vrPurchase();
        $order->forceFill(['payment_method' => PaymentMethod::Card])->save();
        app(RecordVoucherTransferRefund::class)->handle($order, vrAdmin(), 100_000, 'X', vrToday(), 'R');
    }, 'bought by bank transfer'],
    'expired voucher' => [function (): void {
        $order = vrPurchase();
        $order->voucher()->firstOrFail()->forceFill(['status' => VoucherStatus::Expired, 'balance_minor' => 0])->save();
        app(RecordVoucherTransferRefund::class)->handle($order, vrAdmin(), 100_000, 'X', vrToday(), 'R');
    }, 'at most PKR 0'],
    'duplicate reference' => [function (): void {
        $order = vrPurchase();
        app(RecordVoucherTransferRefund::class)->handle($order, vrAdmin(), 100_000, 'DUP', vrToday(), 'R');
        app(RecordVoucherTransferRefund::class)->handle($order->refresh(), vrAdmin(), 100_000, 'DUP', vrToday(), 'R');
    }, 'already recorded'],
]);

it('offers “Record bank refund” on transfer voucher purchases in the admin list', function (): void {
    $order = vrPurchase(5_000);

    Livewire::actingAs(vrAdmin())->test(ListVoucherOrders::class)
        ->assertTableActionVisible('transfer_refund', $order)
        ->callTableAction('transfer_refund', $order, ['amount' => 5_000, 'bank_reference' => 'UBL-9', 'transferred_on' => vrToday()->toDateString(), 'reason' => 'Refund asked'])
        ->assertNotified('Bank refund of PKR 5,000 recorded');

    expect($order->refresh()->status)->toBe(VoucherOrderStatus::Refunded);

    $card = VoucherOrder::factory()->status(VoucherOrderStatus::Paid)->create(['payment_method' => PaymentMethod::Card]);
    Livewire::actingAs(vrAdmin())->test(ListVoucherOrders::class)->assertTableActionHidden('transfer_refund', $card);
});
