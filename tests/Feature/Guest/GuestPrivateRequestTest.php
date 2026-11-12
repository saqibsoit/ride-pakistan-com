<?php

declare(strict_types=1);

use App\Enums\PrivateRequestStatus;
use App\Livewire\Book\BookingFlow;
use App\Models\PrivateSessionRequest;
use App\Models\Product;
use App\Models\Rider;
use App\Models\User;
use App\Notifications\PrivateRequestNotSent;
use App\Notifications\PrivateRequestReceived;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/*
| Private session requests without signing in (guest checkout). Helpers
| are prefixed `gpr`.
*/

beforeEach(function (): void {
    Notification::fake();
    $this->private = Product::factory()->private()->create(['slug' => 'private-session', 'name' => 'Private session']);
});

/** A request draft for a Pakistan date $days ahead at $time. */
function gprDraft(int $days = 4, string $time = '10:00'): void
{
    session()->put(BookingFlow::DRAFT_KEY, [
        'whatsapp' => '',
        'product' => 'private-session',
        'use' => '',
        'slot' => null,
        'later' => false,
        'riders' => [['name' => 'Hamza Khan', 'age_bracket' => '', 'experience_level' => '']],
        'request' => ['date' => CarbonImmutable::now((string) config('app.display_timezone'))->addDays($days)->toDateString(), 'time' => $time, 'note' => ''],
    ]);
}

/** @return array<string, string> */
function gprForm(string $email): array
{
    return [
        'name' => 'Hamza Khan',
        'email' => $email,
        'email_confirmation' => $email,
        'whatsapp' => '0300 1234567',
        'note' => 'Birthday ride',
        'draft' => hash_hmac('sha256', (string) json_encode(session(BookingFlow::DRAFT_KEY)), (string) config('app.key')),
    ];
}

it('sends a guest request and says so', function (): void {
    gprDraft();
    $this->get(route('book.details'))->assertOk()->assertSee('Send request')->assertSee('(requested)');

    $this->post(route('book.details.store'), gprForm('hamza@example.com'))->assertRedirect(route('book.request-sent'));

    $request = PrivateSessionRequest::query()->sole();
    expect($request->placed_as_guest)->toBeTrue()
        ->and($request->contact_name)->toBe('Hamza Khan')
        ->and($request->contact_whatsapp)->toBe('+923001234567')
        ->and($request->note)->toBe('Birthday ride')
        ->and($request->status)->toBe(PrivateRequestStatus::Requested);

    Notification::assertSentTo($request->user, PrivateRequestReceived::class);
    $this->get(route('book.request-sent'))->assertOk()->assertSee('hamza@example.com');
});

it('keeps an account’s open request from the guest and tells the owner instead', function (): void {
    $owner = User::factory()->create(['email' => 'busy@example.com']);
    $open = PrivateSessionRequest::factory()->for($owner)->create(['product_id' => $this->private->id]);
    $ridersBefore = Rider::query()->count();

    gprDraft(5);
    $response = $this->post(route('book.details.store'), gprForm('busy@example.com'));

    // Exactly what a successful request gets.
    $response->assertRedirect(route('book.request-sent'))->assertSessionHasNoErrors();

    expect(PrivateSessionRequest::query()->count())->toBe(1)
        ->and(PrivateSessionRequest::query()->sole()->is($open))->toBeTrue()
        ->and(Rider::query()->count())->toBe($ridersBefore);

    Notification::assertSentTo($owner, PrivateRequestNotSent::class);
    Notification::assertNotSentTo($owner, PrivateRequestReceived::class);
});

it('shows a time that isn’t free to every guest the same way', function (string $email): void {
    User::factory()->create(['email' => 'known@example.com']);
    nearSlot(4, 10);

    gprDraft(4, '10:00');
    $this->post(route('book.details.store'), gprForm($email))
        ->assertSessionHasErrors(['email' => 'That time isn’t free: there’s another session close to it. Please choose another time.']);

    expect(PrivateSessionRequest::query()->count())->toBe(0);
})->with(['known@example.com', 'new@example.com']);
