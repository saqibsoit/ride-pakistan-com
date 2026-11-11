<?php

declare(strict_types=1);

use App\Actions\Exports\ExportCustomers;
use App\Enums\BookingStatus;
use App\Enums\ExportFormat;
use App\Enums\LeadSource;
use App\Enums\OrderStatus;
use App\Enums\VoucherLedgerReason;
use App\Filament\Pages\Exports;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Models\Booking;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Referral;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherLedgerEntry;
use App\Support\CustomerStats;
use App\Support\OrderRevenue;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
 * Prompt 14, item 3: the admin Customers list with sign-up date, contact,
 * bookings, total paid, last booking and lead source; filters; and an
 * admin-only, rate-limited, audit-logged CSV/Excel export.
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00', 'Asia/Karachi'));
    $this->admin = User::factory()->admin()->withMfa()->create(['created_at' => now()->subYear()]);

    // A booked customer: two paid orders (one partly refunded, one part paid by gift voucher), three bookings (one cancelled).
    $this->booked = User::factory()->create(['name' => 'Sana Malik', 'whatsapp_phone' => '+923344554500', 'created_at' => CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Karachi')->utc()]);
    $first = Order::factory()->status(OrderStatus::PartiallyRefunded)->create(['user_id' => $this->booked->id, 'amount_minor' => 2_000_000, 'amount_refunded_minor' => 500_000, 'paid_at' => now()]);
    $second = Order::factory()->status(OrderStatus::Paid)->create(['user_id' => $this->booked->id, 'amount_minor' => 400_000, 'paid_at' => now()]);
    Order::factory()->create(['user_id' => $this->booked->id, 'amount_minor' => 9_000_000]); // unpaid: not counted
    VoucherLedgerEntry::query()->create(['voucher_id' => Voucher::factory()->create()->id, 'order_id' => $second->id, 'delta_minor' => -600_000, 'reason' => VoucherLedgerReason::Redeemed]);
    Booking::factory()->create(['user_id' => $this->booked->id, 'order_id' => $first->id, 'status' => BookingStatus::Confirmed, 'created_at' => now()->subDays(20)]);
    Booking::factory()->create(['user_id' => $this->booked->id, 'order_id' => $first->id, 'status' => BookingStatus::Attended, 'created_at' => CarbonImmutable::parse('2026-11-09 18:30', 'Asia/Karachi')->utc()]);
    Booking::factory()->create(['user_id' => $this->booked->id, 'order_id' => $second->id, 'status' => BookingStatus::Cancelled, 'created_at' => now()]);
    Lead::factory()->create(['email' => $this->booked->email, 'source' => LeadSource::InstagramDm, 'created_at' => now()->subMonths(2)]);
    Lead::factory()->create(['email' => $this->booked->email, 'source' => LeadSource::ContactForm, 'created_at' => now()->subMonth()]);

    // Signed up, never booked, came through a referral link.
    $this->referred = User::factory()->create(['name' => 'Bilal Ahmed', 'created_at' => CarbonImmutable::parse('2026-11-08 12:00', 'Asia/Karachi')->utc()]);
    Referral::factory()->create(['referred_id' => $this->referred->id]);

    $this->actingAs($this->admin);
});

function customerRow(User $user): User
{
    return CustomerStats::apply(User::query()->whereKey($user->id))->sole();
}

it('works out bookings, total paid, last booking and source per customer', function (): void {
    $row = customerRow($this->booked);

    $expectedPaid = Order::query()->where('user_id', $this->booked->id)->whereNotNull('paid_at')->get()->sum(fn (Order $o): int => OrderRevenue::of($o));

    expect((int) $row->getAttribute('bookings_count'))->toBe(2)
        ->and((int) $row->getAttribute('total_paid_minor'))->toBe($expectedPaid)
        ->and($expectedPaid)->toBe(1_500_000 + 400_000 + 600_000)
        ->and((string) $row->getAttribute('lead_source'))->toBe(LeadSource::InstagramDm->value) // first enquiry
        ->and(customerRow($this->referred)->getAttribute('lead_source'))->toBe(LeadSource::Referral->value)
        ->and((int) customerRow($this->referred)->getAttribute('total_paid_minor'))->toBe(0)
        ->and(customerRow($this->admin)->getAttribute('lead_source'))->toBeNull();
});

it('shows the new columns on the Customers screen', function (): void {
    $this->get('/test-admin/customers')
        ->assertOk()
        ->assertSee('Total paid')
        ->assertSee('Last booking')
        ->assertSee('PKR 25,000')
        ->assertSee('+923344554500')
        ->assertSee('Instagram DM')
        ->assertSee('9 Nov 2026')
        ->assertSee('5 Oct 2026');
});

it('filters by sign-up date, bookings and source', function (): void {
    Livewire::test(ListCustomers::class)
        ->filterTable('booked', true)
        ->assertCanSeeTableRecords([$this->booked])
        ->assertCanNotSeeTableRecords([$this->referred, $this->admin])
        ->filterTable('booked', false)
        ->assertCanSeeTableRecords([$this->referred, $this->admin])
        ->assertCanNotSeeTableRecords([$this->booked]);

    Livewire::test(ListCustomers::class)
        ->filterTable('source', LeadSource::Referral->value)
        ->assertCanSeeTableRecords([$this->referred])
        ->assertCanNotSeeTableRecords([$this->booked, $this->admin]);

    Livewire::test(ListCustomers::class)
        ->filterTable('joined', ['from' => '2026-11-01', 'until' => '2026-11-30'])
        ->assertCanSeeTableRecords([$this->referred])
        ->assertCanNotSeeTableRecords([$this->booked, $this->admin]);
});

it('sorts by total paid and last booking', function (): void {
    Livewire::test(ListCustomers::class)
        ->sortTable('total_paid_minor', 'desc')
        ->assertCanSeeTableRecords([$this->booked, $this->referred], inOrder: true)
        ->sortTable('last_booked_at', 'desc')
        ->assertCanSeeTableRecords([$this->booked, $this->referred], inOrder: true);
});

it('exports the filtered list with every column and logs it', function (): void {
    $file = app(ExportCustomers::class)->handle(
        CustomerStats::whereBooked(User::query(), true),
        ExportFormat::Csv,
        $this->admin,
        ['booked' => ['value' => true]],
    );

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

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBe(ExportCustomers::headers())
        ->and($rows[1])->toBe([
            '2026-10-05 10:00', 'Sana Malik', $this->booked->email, '', "'+923344554500", 'Customer',
            '2', '25000.00', '2026-11-09 18:30', 'Instagram DM', 'No', 'Active',
        ]);

    $log = Activity::query()->where('log_name', 'exports')->latest('id')->firstOrFail();
    expect($log->causer_id)->toBe($this->admin->id)
        ->and($log->properties['dataset'])->toBe('customers')
        ->and($log->properties['rows'])->toBe(1)
        ->and($log->properties['filters'])->toBe(['booked' => ['value' => true]]);
});

it('neutralises spreadsheet formulas in names', function (): void {
    $this->booked->forceFill(['name' => '=HYPERLINK("http://evil")'])->save();

    $file = app(ExportCustomers::class)->handle(User::query()->whereKey($this->booked->id), ExportFormat::Csv, $this->admin);
    $content = (string) file_get_contents($file->path);
    unlink($file->path);

    expect($content)->toContain("'=HYPERLINK");
});

it('downloads from the Customers screen as CSV or Excel', function (string $format): void {
    Livewire::test(ListCustomers::class)
        ->callAction(TestAction::make('export')->table(), data: ['format' => $format])
        ->assertHasNoActionErrors()
        ->assertFileDownloaded('ride-pakistan-customers-2026-11-11.'.$format);
})->with(['csv', 'xlsx']);

it('shares the exports limit of 10 an hour', function (): void {
    for ($i = 0; $i < Exports::PER_HOUR; $i++) {
        RateLimiter::hit('exports:'.$this->admin->id, 3600);
    }

    Livewire::test(ListCustomers::class)
        ->callAction(TestAction::make('export')->table(), data: ['format' => 'csv'])
        ->assertNotified('Too many exports')
        ->assertNoFileDownloaded();
});

it('is admin only', function (): void {
    expect(fn () => app(ExportCustomers::class)->handle(User::query(), ExportFormat::Csv, User::factory()->staff()->create()))
        ->toThrow(AuthorizationException::class);

    $this->actingAs(User::factory()->staff()->withMfa()->create())->get('/test-admin/customers')->assertForbidden();
});
