<?php

declare(strict_types=1);

use App\Actions\Exports\RunExport;
use App\Enums\BookingStatus;
use App\Enums\ExportDataset;
use App\Enums\ExportFormat;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Filament\Pages\Exports;
use App\Models\Booking;
use App\Models\Horse;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Spatie\Activitylog\Models\Activity;

/** @return list<list<string>> */
function exportRows(ExportDataset $dataset, string $from, string $until, ExportFormat $format = ExportFormat::Csv, ?User $admin = null): array
{
    $file = app(RunExport::class)->handle(
        $dataset,
        CarbonImmutable::parse($from, 'Asia/Karachi'),
        CarbonImmutable::parse($until, 'Asia/Karachi'),
        $format,
        $admin ?? User::factory()->admin()->create(),
    );

    $rows = [];

    if ($format === ExportFormat::Xlsx) {
        $reader = new XlsxReader;
        $reader->open($file->path);
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(fn ($value): string => (string) $value, $row->toArray());
            }
        }
        $reader->close();
    } else {
        $handle = fopen($file->path, 'rb');
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);
    }

    unlink($file->path);

    return $rows;
}

function exportOrder(string $createdLocal, array $attributes = []): Order
{
    $order = Order::factory()->create(['amount_minor' => 6_000_000, ...$attributes]);
    $order->forceFill(['created_at' => CarbonImmutable::parse($createdLocal, 'Asia/Karachi')->utc()])->save();

    return $order;
}

it('exports orders in the Pakistan date range with PKR amounts and the reference', function (): void {
    $order = exportOrder('2026-11-01 00:30'); // 31 Oct in UTC
    $order->forceFill(['status' => OrderStatus::PartiallyRefunded, 'amount_refunded_minor' => 1_000_000, 'paid_at' => CarbonImmutable::parse('2026-11-01 01:00', 'Asia/Karachi')->utc()])->save();
    exportOrder('2026-10-31 23:30');
    exportOrder('2026-12-01 00:10');

    $rows = exportRows(ExportDataset::Orders, '2026-11-01', '2026-11-30');

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBe(RunExport::headers(ExportDataset::Orders))
        ->and($rows[1][0])->toBe($order->reference)
        ->and($rows[1][1])->toBe('2026-11-01 00:30')
        ->and($rows[1][8])->toBe('60000.00')
        ->and($rows[1][9])->toBe('10000.00')
        ->and($rows[1][10])->toBe('50000.00')
        ->and($rows[1][11])->toBe('2026-11-01 01:00');
});

it('exports payments and refunds separately, each with the order reference', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00', 'Asia/Karachi'));
    $order = exportOrder('2026-11-05 10:00');
    Payment::query()->create(['order_id' => $order->id, 'provider' => PaymentProvider::Stripe, 'provider_id' => 'pi_1', 'amount_minor' => 6_000_000, 'currency' => 'PKR', 'status' => PaymentStatus::Succeeded]);
    Payment::query()->create(['order_id' => $order->id, 'provider' => PaymentProvider::Stripe, 'provider_id' => 're_1', 'amount_minor' => 1_000_000, 'currency' => 'PKR', 'status' => PaymentStatus::Refunded]);

    $payments = exportRows(ExportDataset::Payments, '2026-11-01', '2026-11-30');
    $refunds = exportRows(ExportDataset::Refunds, '2026-11-01', '2026-11-30');

    expect($payments)->toHaveCount(2)
        ->and($payments[1][0])->toBe($order->reference)
        ->and($payments[1][5])->toBe('60000.00')
        ->and($refunds)->toHaveCount(2)
        ->and($refunds[1][3])->toBe('re_1')
        ->and($refunds[1][5])->toBe('10000.00');
});

it('exports bookings by session date with how they were paid, price and horse', function (): void {
    $slot = Slot::factory()->at(CarbonImmutable::parse('2026-11-14 08:00', 'Asia/Karachi'))->create();
    $order = Order::factory()->status(OrderStatus::Paid)->create();
    $booking = Booking::factory()->create(['slot_id' => $slot->id, 'order_id' => $order->id, 'status' => BookingStatus::Attended, 'horse_id' => Horse::factory()->create(['name' => 'Toofan'])->id]);
    $order->items()->create(['slot_id' => $slot->id, 'rider_id' => $booking->rider_id, 'unit_price_minor' => 1_000_000]);
    Booking::factory()->create(['slot_id' => Slot::factory()->at(CarbonImmutable::parse('2026-12-14 08:00', 'Asia/Karachi'))->create()->id]);

    $rows = exportRows(ExportDataset::Bookings, '2026-11-14', '2026-11-14');

    expect($rows)->toHaveCount(2)
        ->and($rows[1][0])->toBe($booking->ticket_code)
        ->and($rows[1][1])->toBe('2026-11-14 08:00')
        ->and($rows[1][5])->toBe('Attended')
        ->and($rows[1][7])->toBe('Order')
        ->and($rows[1][8])->toBe($order->reference)
        ->and($rows[1][9])->toBe('10000.00')
        ->and($rows[1][10])->toBe('Toofan');
});

it('exports leads and neutralises spreadsheet formulas', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'Asia/Karachi'));
    Lead::factory()->create(['name' => '=HYPERLINK("http://evil.test","x")', 'email' => 'a@example.com']);

    $rows = exportRows(ExportDataset::Leads, '2026-11-10', '2026-11-10');

    expect($rows)->toHaveCount(2)
        ->and($rows[1][1])->toBe('\'=HYPERLINK("http://evil.test","x")')
        ->and($rows[1][2])->toBe('a@example.com');

    expect(RunExport::cell('-5.00'))->toBe('-5.00')
        ->and(RunExport::cell('+923001234567'))->toBe('\'+923001234567')
        ->and(RunExport::cell('@cmd'))->toBe('\'@cmd');
});

it('writes the same content as Excel', function (): void {
    $order = exportOrder('2026-11-05 10:00');

    $rows = exportRows(ExportDataset::Orders, '2026-11-01', '2026-11-30', ExportFormat::Xlsx);

    expect($rows)->toHaveCount(2)
        ->and($rows[0][8])->toBe('Amount (PKR)')
        ->and($rows[1][0])->toBe($order->reference)
        ->and($rows[1][8])->toBe('60000.00');
});

it('logs every export in the audit trail', function (): void {
    $admin = User::factory()->admin()->create();
    exportOrder('2026-11-05 10:00');

    exportRows(ExportDataset::Orders, '2026-11-01', '2026-11-30', ExportFormat::Csv, $admin);

    $log = Activity::query()->where('log_name', 'exports')->sole();
    expect($log->event)->toBe('exported')
        ->and($log->causer_id)->toBe($admin->id)
        ->and($log->properties->toArray())->toBe(['dataset' => 'orders', 'from' => '2026-11-01', 'until' => '2026-11-30', 'format' => 'csv', 'rows' => 1]);
});

it('refuses non-admins and bad ranges', function (): void {
    foreach (['staff', 'trainer'] as $role) {
        expect(fn () => exportRows(ExportDataset::Orders, '2026-11-01', '2026-11-30', ExportFormat::Csv, User::factory()->{$role}()->create()))
            ->toThrow(AuthorizationException::class);
    }

    expect(fn () => exportRows(ExportDataset::Orders, '2026-11-30', '2026-11-01'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => exportRows(ExportDataset::Orders, '2025-01-01', '2026-11-30'))->toThrow(InvalidArgumentException::class);
});

it('is an admin-only panel page that downloads the file', function (): void {
    $this->actingAs(User::factory()->staff()->withMfa()->create())->get('/test-admin/exports')->assertForbidden();

    $admin = User::factory()->admin()->withMfa()->create();
    $this->actingAs($admin)->get('/test-admin/exports')->assertOk()->assertSee('What you can export');

    Livewire::actingAs($admin)->test(Exports::class)
        ->callAction('export', ['dataset' => 'orders', 'from' => '2026-11-01', 'until' => '2026-11-30', 'format' => 'csv'])
        ->assertHasNoActionErrors()
        ->assertFileDownloaded('ride-pakistan-orders-2026-11-01-to-2026-11-30.csv');
});
