<?php

declare(strict_types=1);

use App\Actions\Account\SaveWhatsAppNumber;
use App\Livewire\Book\BookingFlow;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rider;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Prompt 14, item 2: email and WhatsApp number for every booking. The
 * email comes from the account (sign-in); the WhatsApp number is asked at
 * review, validated (Pakistani or international) and saved to the profile.
 */

beforeEach(function (): void {
    Product::factory()->create(['slug' => 'single-session', 'name' => 'Single session', 'price_minor' => 1_000_000]);
});

function whatsappReview(User $user): Testable
{
    $rider = Rider::factory()->for($user)->create();

    return Livewire::actingAs($user)->test(BookingFlow::class)
        ->call('chooseProduct', 'single-session')
        ->call('chooseSlot', nearSlot()->id)
        ->set('riderIds', [$rider->id])
        ->call('confirmRiders')
        ->assertSet('step', 'review');
}

it('asks a customer without a WhatsApp number for one before booking', function (): void {
    $user = User::factory()->withoutWhatsApp()->create();

    whatsappReview($user)
        ->assertSee('WhatsApp number')
        ->assertSee($user->email)
        ->call('placeOrder')
        ->assertHasErrors(['whatsapp' => 'required'])
        ->assertNoRedirect();

    expect(Order::query()->count())->toBe(0);
});

it('saves the number to the profile in E.164', function (string $typed, string $stored): void {
    $user = User::factory()->withoutWhatsApp()->create();

    whatsappReview($user)->set('whatsapp', $typed)->call('placeOrder')->assertHasNoErrors();

    expect($user->refresh()->whatsapp_phone)->toBe($stored)
        ->and(Order::query()->count())->toBe(1);
})->with([
    'Pakistani, national' => ['0334 4554500', '+923344554500'],
    'Pakistani, international' => ['+92 334 455 4500', '+923344554500'],
    'United Kingdom' => ['+44 7911 123456', '+447911123456'],
    'United Arab Emirates' => ['+971 50 123 4567', '+971501234567'],
]);

it('refuses numbers that are not phone numbers', function (string $typed): void {
    $user = User::factory()->withoutWhatsApp()->create();

    whatsappReview($user)->set('whatsapp', $typed)->call('placeOrder')->assertHasErrors('whatsapp');

    expect($user->refresh()->whatsapp_phone)->toBeNull()
        ->and(Order::query()->count())->toBe(0);
})->with(['12345', 'not a number', '+1 555', '0334']);

it('pre-ticks "this is also my WhatsApp" from the profile phone and saves it', function (): void {
    $user = User::factory()->withoutWhatsApp()->create(['phone' => '+923001234567']);

    whatsappReview($user)
        ->assertSet('whatsappIsPhone', true)
        ->assertSee('This is also my WhatsApp')
        ->call('placeOrder')
        ->assertHasNoErrors();

    expect($user->refresh()->whatsapp_phone)->toBe('+923001234567');
});

it('takes another number when the box is unticked', function (): void {
    $user = User::factory()->withoutWhatsApp()->create(['phone' => '+923001234567']);

    whatsappReview($user)
        ->set('whatsappIsPhone', false)
        ->call('placeOrder')
        ->assertHasErrors(['whatsapp' => 'required'])
        ->set('whatsapp', '+447911123456')
        ->call('placeOrder')
        ->assertHasNoErrors();

    expect($user->refresh()->whatsapp_phone)->toBe('+447911123456');
});

it('pre-fills the number a returning customer gave before', function (): void {
    $user = User::factory()->create(['whatsapp_phone' => '+923339876543']);

    whatsappReview($user)->assertSet('whatsapp', '+923339876543')->call('placeOrder')->assertHasNoErrors();

    expect(Order::query()->count())->toBe(1);
});

it('needs the number for credit bookings too', function (): void {
    $user = User::factory()->withoutWhatsApp()->create();
    $rider = Rider::factory()->for($user)->create();
    CreditPack::factory()->for($user)->create(['credits_total' => 8, 'credits_used' => 0, 'expires_at' => now()->addWeeks(6)]);

    $component = Livewire::actingAs($user)->test(BookingFlow::class)
        ->call('chooseProduct', BookingFlow::CREDITS)
        ->call('chooseSlot', nearSlot()->id)
        ->set('riderIds', [$rider->id])
        ->call('confirmRiders')
        ->call('placeOrder')
        ->assertHasErrors(['whatsapp' => 'required']);

    $component->set('whatsapp', '03344554500')->call('placeOrder')->assertHasNoErrors()->assertRedirect(route('account.bookings'));

    expect($user->refresh()->whatsapp_phone)->toBe('+923344554500');
});

it('asks guests before they sign in and keeps the number in the draft', function (): void {
    $slot = nearSlot();

    $component = Livewire::test(BookingFlow::class)
        ->call('chooseProduct', 'single-session')
        ->call('chooseSlot', $slot->id)
        ->set('guestRiders.0.name', 'Zara Malik')
        ->call('confirmRiders')
        ->assertSee('WhatsApp number')
        ->call('signInToContinue')
        ->assertHasErrors(['whatsapp' => 'required'])
        ->assertNoRedirect();

    $component->set('whatsapp', '0334 4554500')->call('signInToContinue')->assertRedirect(route('login'));

    expect(session(BookingFlow::DRAFT_KEY)['whatsapp'])->toBe('0334 4554500');
});

it('stores E.164 and keeps an unchanged number as it is', function (): void {
    $user = User::factory()->withoutWhatsApp()->create();
    $save = app(SaveWhatsAppNumber::class);

    $save->handle($user, '0300 1234567');
    expect($user->refresh()->whatsapp_phone)->toBe('+923001234567');

    $updatedAt = $user->updated_at;
    $this->travel(1)->minutes();
    $save->handle($user, '+92 300 1234567');
    expect($user->refresh()->updated_at->equalTo($updatedAt))->toBeTrue();
});
