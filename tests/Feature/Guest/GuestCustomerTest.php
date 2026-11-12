<?php

declare(strict_types=1);

use App\Actions\Guest\CreateGuestRiders;
use App\Actions\Guest\ResolveGuestCustomer;
use App\Exceptions\GuestCheckoutRejected;
use App\Models\Rider;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
| Guest checkout (decided 2026-11-12): the account behind a guest's email,
| and the riders they typed. Unit tests for the two actions.
*/

function guestResolve(string $email, string $name = 'Ayesha Khan', ?string $whatsapp = '+923001112233'): User
{
    return DB::transaction(fn (): User => app(ResolveGuestCustomer::class)->handle($email, $name, $whatsapp));
}

it('creates an unverified customer account for a new email', function (): void {
    $user = guestResolve('  New.Guest@Example.com ');

    expect($user->email)->toBe('new.guest@example.com')
        ->and($user->name)->toBe('Ayesha Khan')
        ->and($user->whatsapp_phone)->toBe('+923001112233')
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->isPanelUser())->toBeFalse()
        ->and(User::query()->count())->toBe(1);
});

it('reuses an existing customer account without changing its details', function (): void {
    $existing = User::factory()->create(['email' => 'sana@example.com', 'name' => 'Sana Iqbal', 'whatsapp_phone' => '+923339998877', 'marketing_consent' => true]);

    $user = guestResolve('SANA@example.com', 'Someone Else', '+923001112233');

    $existing->refresh();
    expect($user->id)->toBe($existing->id)
        ->and($existing->name)->toBe('Sana Iqbal')
        ->and($existing->whatsapp_phone)->toBe('+923339998877')
        ->and($existing->marketing_consent)->toBeTrue()
        ->and(User::query()->count())->toBe(1);
});

it('fills in a missing name and WhatsApp number on an existing account', function (): void {
    $existing = User::factory()->passwordless()->withoutWhatsApp()->create(['email' => 'codes@example.com']);

    guestResolve('codes@example.com', 'Bilal Ahmed', '+923001112233');

    expect($existing->refresh()->name)->toBe('Bilal Ahmed')
        ->and($existing->whatsapp_phone)->toBe('+923001112233');
});

it('refuses team, deleted and anonymised accounts with one neutral message and leaves them alone', function (User $account): void {
    $before = User::withTrashed()->findOrFail($account->id)->getAttributes();

    expect(fn () => guestResolve($account->email))
        ->toThrow(GuestCheckoutRejected::class, GuestCheckoutRejected::EMAIL);

    $after = User::withTrashed()->findOrFail($account->id)->getAttributes();
    expect($after['name'])->toBe($before['name'])
        ->and($after['whatsapp_phone'])->toBe($before['whatsapp_phone'])
        ->and($after['deleted_at'] === null)->toBe($before['deleted_at'] === null);
})->with([
    'admin' => fn (): User => User::factory()->admin()->withMfa()->create(),
    'staff' => fn (): User => User::factory()->staff()->withMfa()->create(),
    'trainer' => fn (): User => User::factory()->trainer()->withMfa()->create(),
    'scheduled for deletion' => fn (): User => User::factory()->deletedDaysAgo(3)->create(),
    'anonymised' => fn (): User => tap(User::factory()->create(), fn (User $u) => $u->forceFill(['anonymised_at' => now()])->save()),
]);

it('adds the typed riders to the account and reuses a rider with the same name', function (): void {
    $user = User::factory()->create();
    $kept = Rider::factory()->for($user)->create(['name' => 'Zara Malik', 'notes' => 'Allergic to hay']);

    $ids = app(CreateGuestRiders::class)->handle($user, [
        ['name' => '  zara   MALIK '],
        ['name' => 'Omar Malik', 'age_bracket' => null, 'experience_level' => null],
        ['name' => 'Omar Malik'],
        ['name' => '   '],
    ]);

    $omar = Rider::query()->where('name', 'Omar Malik')->sole();
    expect($ids)->toBe([$kept->id, $omar->id])
        ->and($kept->refresh()->name)->toBe('Zara Malik')
        ->and($kept->notes)->toBe('Allergic to hay')
        ->and($omar->user_id)->toBe($user->id)
        ->and($user->riders()->count())->toBe(2);
});
