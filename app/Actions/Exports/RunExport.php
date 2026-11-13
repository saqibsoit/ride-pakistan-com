<?php

declare(strict_types=1);

namespace App\Actions\Exports;

use App\Enums\DiscountKind;
use App\Enums\ExportDataset;
use App\Enums\ExportFormat;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Lead;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use App\Models\VoucherOrder;
use App\Support\Money;
use App\Support\OrderRevenue;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\WriterInterface;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Admin exports (milestone 10b): orders, payments, refunds, bookings and
 * leads between two Pakistan dates (inclusive), as CSV or Excel. Money is
 * PKR in major units with two decimals ("10000.00"), never floats, and
 * every money row carries the order reference. Dates are Pakistan time.
 * Text that a spreadsheet would run as a formula is prefixed with a quote.
 * Every export is logged in the audit trail (log "exports").
 */
final class RunExport
{
    public const MAX_DAYS = 366;

    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     */
    public function handle(ExportDataset $dataset, CarbonImmutable $fromDate, CarbonImmutable $untilDate, ExportFormat $format, User $actor): ExportFile
    {
        if (! $actor->can('export-data')) {
            throw new AuthorizationException('Only an admin can export data.');
        }

        $tz = (string) config('app.display_timezone');
        $from = CarbonImmutable::parse($fromDate->toDateString(), $tz)->startOfDay();
        $until = CarbonImmutable::parse($untilDate->toDateString(), $tz)->addDay()->startOfDay();

        if ($until->lessThanOrEqualTo($from)) {
            throw new InvalidArgumentException('The end date must be on or after the start date.');
        }

        if ($from->diffInDays($until) > self::MAX_DAYS) {
            throw new InvalidArgumentException(sprintf('Export at most %d days at a time.', self::MAX_DAYS));
        }

        $path = tempnam(sys_get_temp_dir(), 'rp-export-');

        if ($path === false) {
            throw new InvalidArgumentException('Could not create the export file.');
        }

        $writer = $format === ExportFormat::Xlsx ? new XlsxWriter : new CsvWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(self::headers($dataset)));

        $rows = $this->write($writer, $dataset, $from->utc(), $until->utc(), $tz);
        $writer->close();

        $filename = sprintf('ride-pakistan-%s-%s-to-%s.%s', $dataset->value, $from->toDateString(), $until->subDay()->toDateString(), $format->value);

        activity('exports')
            ->causedBy($actor)
            ->event('exported')
            ->withProperties([
                'dataset' => $dataset->value,
                'from' => $from->toDateString(),
                'until' => $until->subDay()->toDateString(),
                'format' => $format->value,
                'rows' => $rows,
            ])
            ->log(sprintf('Exported %d %s (%s to %s, %s)', $rows, $dataset->value, $from->toDateString(), $until->subDay()->toDateString(), strtoupper($format->value)));

        return new ExportFile($path, $filename, $format->mimeType(), $rows);
    }

    /** @return list<string> */
    public static function headers(ExportDataset $dataset): array
    {
        return match ($dataset) {
            ExportDataset::Orders => ['Reference', 'Created (Pakistan time)', 'Status', 'Payment method', 'Customer', 'Customer email', 'Product', 'Type', 'Amount (PKR)', 'Refunded (PKR)', 'Net (PKR)', 'Paid at (Pakistan time)', 'Subtotal (PKR)', 'Promo or referral (PKR)', 'Gift voucher (PKR)', 'Account credit (PKR)', 'Revenue (PKR)', 'Customer WhatsApp'],
            ExportDataset::Payments, ExportDataset::Refunds => ['Order reference', 'Date (Pakistan time)', 'Provider', 'Provider ID', 'Status', 'Amount (PKR)', 'Currency', 'Customer email', 'Bank transfer date (Pakistan)'],
            ExportDataset::VoucherSales => ['Purchase reference', 'Paid at (Pakistan time)', 'Status', 'Payment method', 'Payment reference', 'Buyer email', 'Voucher for', 'Received (PKR)', 'Refunded (PKR)', 'Net cash (PKR)', 'Refunded on', 'Refund references'],
            ExportDataset::Bookings => ['Ticket', 'Session (Pakistan time)', 'Product', 'Rider', 'Customer email', 'Status', 'Private', 'Paid with', 'Order reference', 'Price (PKR)', 'Horse', 'Price after discounts (PKR)', 'Customer WhatsApp'],
            ExportDataset::Leads => ['Received (Pakistan time)', 'Name', 'Email', 'Phone', 'Instagram', 'Source', 'Status', 'Interest', 'UTM source', 'UTM medium', 'UTM campaign', 'Assigned to', 'Booked order reference'],
        };
    }

    private function write(WriterInterface $writer, ExportDataset $dataset, CarbonImmutable $from, CarbonImmutable $until, string $tz): int
    {
        $rows = 0;
        /** @param  list<string>  $values */
        $add = function (array $values) use ($writer, &$rows): void {
            $writer->addRow(Row::fromValues(array_values(array_map(self::cell(...), $values))));
            $rows++;
        };
        $date = fn (?CarbonInterface $at): string => $at === null ? '' : $at->setTimezone($tz)->format('Y-m-d H:i');

        match ($dataset) {
            ExportDataset::Orders => Order::query()
                ->with(['user', 'discounts'])
                ->where('created_at', '>=', $from)->where('created_at', '<', $until)
                ->orderBy('created_at')->orderBy('id')
                ->chunk(500, fn ($orders) => $orders->each(fn (Order $order) => $add([
                    $order->reference,
                    $date($order->created_at),
                    $order->status->label(),
                    $order->payment_method?->label() ?? '',
                    $order->user->name ?? '',
                    $order->user->email,
                    $order->productName(),
                    $order->productType()->label(),
                    Money::decimal($order->amount_minor),
                    Money::decimal($order->amount_refunded_minor),
                    Money::decimal(max(0, $order->amount_minor - $order->amount_refunded_minor)),
                    $date($order->paid_at),
                    // Discounts (milestone 10e Part B). Amount is what was charged after them.
                    Money::decimal($order->subtotal_minor),
                    Money::decimal(self::discount($order, DiscountKind::Promo, DiscountKind::Referral)),
                    Money::decimal(self::discount($order, DiscountKind::Voucher)),
                    Money::decimal(self::discount($order, DiscountKind::AccountCredit)),
                    $order->paid_at === null ? '' : Money::decimal(OrderRevenue::of($order)),
                    $order->contactWhatsApp() ?? '',
                ]))),

            ExportDataset::Payments, ExportDataset::Refunds => Payment::query()
                ->with('order.user')
                ->where('status', $dataset === ExportDataset::Refunds ? PaymentStatus::Refunded : PaymentStatus::Succeeded)
                ->where('created_at', '>=', $from)->where('created_at', '<', $until)
                ->orderBy('created_at')->orderBy('id')
                ->chunk(500, fn ($payments) => $payments->each(fn (Payment $payment) => $add([
                    $payment->order->reference,
                    $date($payment->created_at),
                    $payment->provider->label(),
                    $payment->provider_id ?? '',
                    $payment->status->label(),
                    Money::decimal($payment->amount_minor),
                    $payment->currency,
                    $payment->order->user->email,
                    // Bank refunds (milestone 10e): the date the team's transfer was made.
                    $payment->transferred_on?->format('Y-m-d') ?? '',
                ]))),

            // Cash received for gift vouchers (milestone 10e), by payment date, for
            // reconciling with the bank statement. Revenue elsewhere counts a
            // voucher when it is spent; this is the money when it came in.
            // Codes are left out: a code can be spent like cash.
            ExportDataset::VoucherSales => VoucherOrder::query()
                ->with('user')
                ->whereNotNull('paid_at')
                ->where('paid_at', '>=', $from)->where('paid_at', '<', $until)
                ->orderBy('paid_at')->orderBy('id')
                ->chunk(500, fn ($sales) => $sales->each(function (VoucherOrder $sale) use ($add, $date): void {
                    // Late payments on closed purchases count too (received, then refunded automatically).
                    $received = $sale->receivedMinor();
                    $refunded = $sale->refundedMinor();
                    $refunds = collect($sale->refundEntries());

                    $add([
                        $sale->reference,
                        $date($sale->paid_at),
                        $sale->status->label(),
                        $sale->payment_method?->label() ?? '',
                        $sale->payment_method === PaymentMethod::Card ? (string) $sale->stripe_payment_intent_id : (string) $sale->transaction_ref,
                        $sale->user->email ?? '',
                        $sale->product_name ?? 'Amount',
                        Money::decimal($received),
                        Money::decimal($refunded),
                        Money::decimal(max(0, $received - $refunded)),
                        $refunds->pluck('on')->filter()->unique()->implode('; '),
                        $refunds->pluck('provider_id')->filter()->implode('; '),
                    ]);
                })),

            ExportDataset::Bookings => Booking::query()
                ->with(['slot', 'rider', 'user', 'horse', 'order.items', 'order.discounts', 'creditPack.order'])
                ->whereHas('slot', fn (Builder $q) => $q->where('starts_at', '>=', $from)->where('starts_at', '<', $until))
                ->join('slots', 'slots.id', '=', 'bookings.slot_id')
                ->orderBy('slots.starts_at')->orderBy('bookings.id')
                ->select('bookings.*')
                ->chunk(500, fn ($bookings) => $bookings->each(fn (Booking $booking) => $add([
                    $booking->ticket_code,
                    $date($booking->slot->starts_at),
                    $booking->order?->productName() ?? $booking->creditPack?->order->productName() ?? 'Manual booking',
                    $booking->rider->name,
                    $booking->user->email,
                    $booking->status->label(),
                    $booking->is_private ? 'Yes' : 'No',
                    $booking->credit_pack_id !== null ? 'Package credit' : ($booking->order_id !== null ? 'Order' : 'No charge'),
                    $booking->order->reference ?? $booking->creditPack?->order->reference ?? '',
                    self::bookingPrice($booking),
                    $booking->horse->name ?? '',
                    self::bookingNetPrice($booking),
                    $booking->contactWhatsApp() ?? '',
                ]))),

            ExportDataset::Leads => Lead::query()
                ->with(['assignee', 'bookedOrder'])
                ->where('created_at', '>=', $from)->where('created_at', '<', $until)
                ->orderBy('created_at')->orderBy('id')
                ->chunk(500, fn ($leads) => $leads->each(fn (Lead $lead) => $add([
                    $date($lead->created_at),
                    $lead->name,
                    $lead->email ?? '',
                    $lead->phone ?? '',
                    $lead->instagram_handle ?? '',
                    $lead->source->label(),
                    $lead->status->label(),
                    $lead->interest?->label() ?? '',
                    $lead->utm_source ?? '',
                    $lead->utm_medium ?? '',
                    $lead->utm_campaign ?? '',
                    $lead->assignee?->displayName() ?? '',
                    $lead->bookedOrder->reference ?? '',
                ]))),
        };

        return $rows;
    }

    /** Sum of an order's discount lines of these kinds. */
    private static function discount(Order $order, DiscountKind ...$kinds): int
    {
        return (int) $order->discounts->filter(fn ($line): bool => in_array($line->kind, $kinds, true))->sum('amount_minor');
    }

    /** This rider's seat after the order's discounts (its share of what was charged and paid by voucher or credit, without promo or referral). */
    private static function bookingNetPrice(Booking $booking): string
    {
        $order = $booking->order;

        if ($order === null || $order->subtotal_minor <= 0) {
            return '';
        }

        $item = $order->items->first(fn (OrderItem $item): bool => $item->slot_id === $booking->slot_id && $item->rider_id === $booking->rider_id);

        if ($item === null) {
            return '';
        }

        $afterPromo = $order->subtotal_minor - self::discount($order, DiscountKind::Promo, DiscountKind::Referral);

        return Money::decimal(intdiv($item->unit_price_minor * $afterPromo, $order->subtotal_minor));
    }

    /** What this rider's seat cost on its own order; empty for credits and no-charge bookings. */
    private static function bookingPrice(Booking $booking): string
    {
        if ($booking->order === null) {
            return '';
        }

        $item = $booking->order->items->first(fn (OrderItem $item): bool => $item->slot_id === $booking->slot_id && $item->rider_id === $booking->rider_id);

        return $item === null ? '' : Money::decimal($item->unit_price_minor);
    }

    /**
     * Neutralises spreadsheet formulas in text (CSV/formula injection):
     * a cell starting with = + - @ or a control character gets a leading
     * quote. Plain numbers such as -5.00 are left alone.
     */
    public static function cell(string $value): string
    {
        if ($value === '' || preg_match('/^-?\d+(\.\d+)?$/', $value) === 1) {
            return $value;
        }

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
