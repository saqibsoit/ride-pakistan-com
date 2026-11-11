<?php

declare(strict_types=1);

use App\Actions\Account\AnonymiseAccount;
use App\Actions\Exports\RunExport;
use App\Enums\BookingStatus;
use App\Enums\ExportDataset;
use App\Enums\ExportFormat;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Booking;
use App\Models\Order;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * Prompt 14, item 2: the customer's WhatsApp number on the run sheet, the
 * order pages and the exports; removed when the account is anonymised.
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 07:00', 'Asia/Karachi'));
    $this->customer = User::factory()->create(['name' => 'Sana Malik', 'whatsapp_phone' => '+923344554500']);
    $this->slot = Slot::factory()->at(CarbonImmutable::parse('2026-11-14 08:00', 'Asia/Karachi'))->create();
    $this->order = Order::factory()->status(OrderStatus::Paid)->create(['user_id' => $this->customer->id, 'amount_minor' => 1_000_000]);
    $this->booking = Booking::factory()->create([
        'slot_id' => $this->slot->id, 'user_id' => $this->customer->id, 'order_id' => $this->order->id, 'status' => BookingStatus::Confirmed,
    ]);
});

/** @return list<list<string>> */
function whatsappExportRows(ExportDataset $dataset): array
{
    $file = app(RunExport::class)->handle(
        $dataset,
        CarbonImmutable::parse('2026-11-01', 'Asia/Karachi'),
        CarbonImmutable::parse('2026-11-30', 'Asia/Karachi'),
        ExportFormat::Csv,
        User::factory()->admin()->create(),
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

    return $rows;
}

it('shows the booker’s WhatsApp on the run sheet with a wa.me link', function (): void {
    $staff = User::factory()->staff()->withMfa()->create();

    $this->actingAs($staff)->get(route('team.sessions.show', $this->slot))
        ->assertOk()
        ->assertSee('href="https://wa.me/923344554500"', false)
        ->assertSee('WhatsApp +923344554500');

    $this->actingAs($staff)->get(route('team.sessions.show', [$this->slot, 'print' => 1]))
        ->assertOk()
        ->assertSee('+923344554500');
});

it('says when a booker has no WhatsApp number yet', function (): void {
    $this->customer->forceFill(['whatsapp_phone' => null])->save();

    $this->actingAs(User::factory()->staff()->withMfa()->create())->get(route('team.sessions.show', $this->slot))
        ->assertOk()
        ->assertSee('No WhatsApp number yet');
});

it('shows it on the admin and the account order pages', function (): void {
    Livewire::actingAs(User::factory()->admin()->withMfa()->create())
        ->test(ViewOrder::class, ['record' => $this->order->reference])
        ->assertSee('+923344554500');

    $this->actingAs($this->customer)->get(route('account.orders.show', $this->order->reference))
        ->assertOk()
        ->assertSee('WhatsApp +923344554500')
        ->assertSee($this->customer->email);
});

it('adds a Customer WhatsApp column to the orders and bookings exports', function (ExportDataset $dataset): void {
    $rows = whatsappExportRows($dataset);
    $column = array_search('Customer WhatsApp', $rows[0], true);

    expect($column)->not->toBeFalse()
        // Formula guard: a leading + is quoted, like the leads export's phone.
        ->and($rows[1][$column])->toBe("'+923344554500");
})->with([ExportDataset::Orders, ExportDataset::Bookings]);

it('lets the customer change it on their profile', function (): void {
    $this->actingAs($this->customer)->put('/account/profile', ['name' => 'Sana Malik', 'whatsapp_phone' => '0300 7654321'])
        ->assertSessionHasNoErrors();

    expect($this->customer->refresh()->whatsapp_phone)->toBe('+923007654321');

    $this->actingAs($this->customer)->put('/account/profile', ['name' => 'Sana Malik', 'whatsapp_phone' => 'nope'])
        ->assertSessionHasErrors('whatsapp_phone');
});

it('is part of the data download and cleared by anonymisation', function (): void {
    expect($this->actingAs($this->customer)->post(route('account.privacy.export'))->assertOk()->streamedContent())->toContain('+923344554500');

    $this->customer->delete();
    app(AnonymiseAccount::class)->handle($this->customer);

    expect($this->customer->refresh()->whatsapp_phone)->toBeNull();
});
