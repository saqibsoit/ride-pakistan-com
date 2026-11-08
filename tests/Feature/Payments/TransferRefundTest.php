<?php

declare(strict_types=1);

use App\Actions\Booking\CreateOrder;
use App\Actions\Booking\FulfilOrder;
use App\Actions\Credit\AccountCredit;
use App\Actions\Dashboard\TodaySummary;
use App\Actions\Discounts\ChangeOrderDiscounts;
use App\Actions\Exports\RunExport;
use App\Actions\Payments\RecordTransferRefund;
use App\Enums\AccountCreditReason;
use App\Enums\BookingStatus;
use App\Enums\CreditPackStatus;
use App\Enums\ExportDataset;
use App\Enums\ExportFormat;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\ProductType;
use App\Enums\VoucherOrderStatus;
use App\Exceptions\BookingRejected;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\AccountCreditEntry;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Rider;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * Days ahead for the next order's slot.
 * Counts up from 3 in call order and is reset before every test, so no
 * two slots in a test share a start time (slots.starts_at is unique).
 * Replaces a random value that could repeat.
 */
function trSlotDay(bool $reset = false): int
{
    static $n = 2;

    if ($reset) {
        $n = 2;

        return $n;
    }

    return ++$n;
}

/**
 * A bank transfer order, approved and paid: $riders riders at PKR 10,000.
 * With $voucherPkr / $creditPkr the customer also used a gift voucher and
 * account credit at checkout.
 */
function trOrder(int $riders = 2, int $voucherPkr = 0, int $creditPkr = 0, ?Product $product = null): Order
{
    $user = User::factory()->create();
    $product ??= Product::factory()->create(['price_minor' => 1_000_000]);
    $riderIds = array_map('intval', Rider::factory()->count($riders)->for($user)->create()->modelKeys());
    $slotId = $product->type === ProductType::Package ? null : nearSlot(trSlotDay(), 8, ['capacity' => 6])->id;
    $order = app(CreateOrder::class)->handle($user, $product, $slotId, $slotId === null ? [] : $riderIds);
    $change = app(ChangeOrderDiscounts::class);

    if ($voucherPkr > 0) {
        $change->applyCode($order, $user, Voucher::factory()->create(['initial_minor' => $voucherPkr * 100, 'balance_minor' => $voucherPkr * 100])->code);
    }

    if ($creditPkr > 0) {
        AccountCreditEntry::query()->create(['user_id' => $user->id, 'delta_minor' => $creditPkr * 100, 'reason' => AccountCreditReason::Adjusted, 'note' => 'Test']);
        $change->useCredit($order, $user, true);
    }

    $order->refresh()->forceFill(['payment_method' => PaymentMethod::BankTransfer])->save();
    $order = app(FulfilOrder::class)->handle($order)->refresh();
    Payment::query()->create(['order_id' => $order->id, 'provider' => PaymentProvider::BankTransfer, 'amount_minor' => $order->amount_minor, 'currency' => 'PKR', 'status' => PaymentStatus::Succeeded]);

    return $order;
}

function trAdmin(): User
{
    return User::factory()->admin()->withMfa()->create();
}

function trToday(): CarbonImmutable
{
    return CarbonImmutable::now('Asia/Karachi')->startOfDay();
}

beforeEach(function (): void {
    trSlotDay(reset: true);
    Notification::fake();
});

it('records a full bank refund: payment, status, bookings and audit log', function (): void {
    $order = trOrder();
    $admin = trAdmin();

    app(RecordTransferRefund::class)->handle($order, $admin, 2_000_000, 'HBL-998877', trToday(), 'Family can’t come');

    $refund = Payment::query()->where('order_id', $order->id)->where('status', PaymentStatus::Refunded)->sole();

    expect($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and($order->amount_refunded_minor)->toBe(2_000_000)
        ->and($refund->provider)->toBe(PaymentProvider::BankTransfer)
        ->and($refund->provider_id)->toBe('HBL-998877')
        ->and($refund->transferred_on?->toDateString())->toBe(trToday()->toDateString())
        ->and($order->bookings()->pluck('status')->unique()->all())->toBe([BookingStatus::Cancelled])
        ->and($this->stripe()->refunds)->toBe([]);

    $log = Activity::query()->where('log_name', 'payments')->where('event', 'refunded')->latest('id')->firstOrFail();
    expect($log->properties->get('method'))->toBe('bank_transfer')
        ->and($log->properties->get('bank_reference'))->toBe('HBL-998877')
        ->and($log->causer_id)->toBe($admin->id);
});

it('records a partial bank refund for the chosen bookings only', function (): void {
    $order = trOrder();
    $keep = $order->bookings()->orderBy('id')->first();
    $cancel = $order->bookings()->orderBy('id')->skip(1)->first();

    app(RecordTransferRefund::class)->handle($order, trAdmin(), 1_000_000, 'MCB-1', trToday(), 'One rider ill', [$cancel->id]);

    expect($order->refresh()->status)->toBe(OrderStatus::PartiallyRefunded)
        ->and($cancel->refresh()->status)->toBe(BookingStatus::Cancelled)
        ->and($keep->refresh()->status)->toBe(BookingStatus::Confirmed);
});

it('voids a package’s unused credits on a full bank refund', function (): void {
    $order = trOrder(product: Product::factory()->create(['type' => ProductType::Package, 'price_minor' => 6_000_000, 'sessions_count' => 8, 'validity_days' => 42]));

    app(RecordTransferRefund::class)->handle($order, trAdmin(), 6_000_000, 'UBL-55', trToday(), 'Moved abroad');

    expect($order->creditPack()->firstOrFail()->status)->toBe(CreditPackStatus::Revoked);
});

it('gives back the same share of gift voucher and credit as a card refund', function (): void {
    // 20,000 − 5,000 voucher − 3,000 credit = 12,000 paid by transfer.
    $order = trOrder(voucherPkr: 5_000, creditPkr: 3_000);
    $voucher = Voucher::query()->sole();
    expect($order->amount_minor)->toBe(1_200_000);

    app(RecordTransferRefund::class)->handle($order, trAdmin(), 600_000, 'BAF-1', trToday(), 'Half');

    expect($voucher->refresh()->balance_minor)->toBe(250_000)
        ->and(app(AccountCredit::class)->balance($order->user))->toBe(150_000);

    app(RecordTransferRefund::class)->handle($order->refresh(), trAdmin(), 600_000, 'BAF-2', trToday(), 'Rest');

    expect($voucher->refresh()->balance_minor)->toBe(500_000)
        ->and(app(AccountCredit::class)->balance($order->user))->toBe(300_000)
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded);
});

it('refuses what it shouldn’t record', function (Closure $call, string $message): void {
    expect($call)->toThrow(BookingRejected::class, $message);
})->with([
    'staff' => [fn () => app(RecordTransferRefund::class)->handle(trOrder(), User::factory()->staff()->create(), 100_000, 'X', trToday(), 'R'), 'Only admins'],
    'no reference' => [fn () => app(RecordTransferRefund::class)->handle(trOrder(), trAdmin(), 100_000, '  ', trToday(), 'R'), 'bank reference'],
    'no reason' => [fn () => app(RecordTransferRefund::class)->handle(trOrder(), trAdmin(), 100_000, 'X', trToday(), ' '), 'reason'],
    'future date' => [fn () => app(RecordTransferRefund::class)->handle(trOrder(), trAdmin(), 100_000, 'X', trToday()->addDay(), 'R'), 'future'],
    'before payment' => [fn () => app(RecordTransferRefund::class)->handle(trOrder(), trAdmin(), 100_000, 'X', trToday()->subDays(3), 'R'), 'before the payment came in'],
    'too much' => [fn () => app(RecordTransferRefund::class)->handle(trOrder(), trAdmin(), 2_000_001, 'X', trToday(), 'R'), 'not yet refunded'],
    'card order' => [function (): void {
        $order = trOrder();
        $order->forceFill(['payment_method' => PaymentMethod::Card])->save();
        app(RecordTransferRefund::class)->handle($order, trAdmin(), 100_000, 'X', trToday(), 'R');
    }, 'bank transfer orders'],
]);

it('never records the same bank reference twice on an order', function (): void {
    $order = trOrder();
    app(RecordTransferRefund::class)->handle($order, trAdmin(), 100_000, 'HBL-1', trToday(), 'Part');

    expect(fn () => app(RecordTransferRefund::class)->handle($order->refresh(), trAdmin(), 100_000, 'HBL-1', trToday(), 'Again'))
        ->toThrow(BookingRejected::class, 'already recorded');
});

it('offers “Record bank refund” to admins on transfer orders only', function (): void {
    $order = trOrder();

    Livewire::actingAs(trAdmin())->test(ViewOrder::class, ['record' => $order->reference])
        ->assertActionVisible('transfer_refund')
        ->assertActionHidden('refund')
        ->callAction('transfer_refund', data: ['full' => true, 'bank_reference' => 'HBL-42', 'transferred_on' => trToday()->toDateString(), 'reason' => 'Cancelled'])
        ->assertNotified('Bank refund of PKR 20,000 recorded');

    expect($order->refresh()->status)->toBe(OrderStatus::Refunded);

    // Staff can't open orders at all (admin-only, like transfers), let alone refund them.
    expect(User::factory()->staff()->create()->can('refund', trOrder()))->toBeFalse();
});

it('puts bank refunds in the refunds export and takes them off revenue', function (): void {
    $order = trOrder();
    app(RecordTransferRefund::class)->handle($order, trAdmin(), 500_000, 'HBL-77', trToday()->subDay()->max($order->paid_at->setTimezone('Asia/Karachi')->startOfDay()), 'Part');

    $file = app(RunExport::class)->handle(ExportDataset::Refunds, trToday()->subDays(2), trToday()->addDay(), ExportFormat::Csv, trAdmin());
    $rows = array_map(fn (string $line): array => str_getcsv($line, escape: ''), array_values(array_filter(explode("\n", ltrim((string) file_get_contents($file->path), "\xEF\xBB\xBF")))));
    unlink($file->path);
    $row = array_combine($rows[0], $rows[1]);

    expect($row['Provider'])->toBe('Bank transfer')
        ->and($row['Provider ID'])->toBe('HBL-77')
        ->and($row['Amount (PKR)'])->toBe('5000.00')
        ->and($row['Bank transfer date (Pakistan)'])->not->toBe('');

    expect((new TodaySummary(CarbonImmutable::now('Asia/Karachi')))->revenue())->toBe(1_500_000);
});

it('exports gift voucher sales as cash received, for bank reconciliation', function (): void {
    $card = VoucherOrder::factory()->create(['amount_minor' => 500_000, 'payment_method' => PaymentMethod::Card, 'stripe_payment_intent_id' => 'pi_gv_1', 'paid_at' => now()]);
    $card->forceFill(['status' => VoucherOrderStatus::Paid])->save();
    $transfer = VoucherOrder::factory()->create(['amount_minor' => 300_000, 'payment_method' => PaymentMethod::BankTransfer, 'transaction_ref' => 'TRX-123', 'paid_at' => now(), 'meta' => ['amount_refunded_minor' => 100_000]]);
    $transfer->forceFill(['status' => VoucherOrderStatus::Refunded])->save();
    VoucherOrder::factory()->create(['amount_minor' => 900_000]); // never paid: not cash

    $file = app(RunExport::class)->handle(ExportDataset::VoucherSales, trToday()->subDay(), trToday()->addDay(), ExportFormat::Csv, trAdmin());
    $rows = array_map(fn (string $line): array => str_getcsv($line, escape: ''), array_values(array_filter(explode("\n", ltrim((string) file_get_contents($file->path), "\xEF\xBB\xBF")))));
    unlink($file->path);

    $byRef = collect(array_slice($rows, 1))->mapWithKeys(fn (array $r): array => [$r[0] => array_combine($rows[0], $r)]);

    expect($byRef)->toHaveCount(2)
        ->and($byRef[$card->reference]['Payment reference'])->toBe('pi_gv_1')
        ->and($byRef[$card->reference]['Net cash (PKR)'])->toBe('5000.00')
        ->and($byRef[$transfer->reference]['Payment reference'])->toBe('TRX-123')
        ->and($byRef[$transfer->reference]['Refunded (PKR)'])->toBe('1000.00')
        ->and($byRef[$transfer->reference]['Net cash (PKR)'])->toBe('2000.00')
        ->and(implode(',', $rows[0]))->not->toContain('Code')
        ->and(ExportDataset::VoucherSales->getLabel())->toBe('Gift voucher sales');
});
