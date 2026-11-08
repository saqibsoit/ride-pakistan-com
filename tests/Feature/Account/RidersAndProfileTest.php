<?php

declare(strict_types=1);

use App\Actions\Account\UpdateProfile;
use App\Enums\AgeBracket;
use App\Enums\ExperienceLevel;
use App\Livewire\Account\Riders;
use App\Models\Booking;
use App\Models\Rider;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

it('renders the riders page with the component', function (): void {
    $user = User::factory()->create();
    Rider::factory()->for($user)->create(['name' => 'Aisha']);

    $this->actingAs($user)->get('/account/riders')
        ->assertOk()
        ->assertSeeLivewire(Riders::class)
        ->assertSee('Aisha');
});

it('adds a rider with an encrypted emergency contact', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(Riders::class)
        ->call('create')
        ->set('name', 'Zara')
        ->set('age_bracket', AgeBracket::From12To15->value)
        ->set('experience_level', ExperienceLevel::None->value)
        ->set('emergency_contact_name', 'Mother')
        ->set('emergency_contact_phone', '0300 1234567')
        ->set('notes', 'Nervous around big horses')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('status', 'Zara added.')
        ->assertSee('Zara');

    $rider = $user->riders()->sole();
    $raw = DB::table('riders')->where('id', $rider->id)->first();

    expect($rider->emergency_contact_phone)->toBe('+923001234567')
        ->and($rider->notes)->toBe('Nervous around big horses')
        ->and($rider->age_bracket)->toBe(AgeBracket::From12To15)
        ->and($raw->emergency_contact_phone)->not->toContain('923001234567')
        ->and($raw->notes)->not->toContain('Nervous');
});

it('validates rider details', function (): void {
    Livewire::actingAs(User::factory()->create())->test(Riders::class)
        ->call('create')
        ->set('name', '')
        ->set('age_bracket', 'ancient')
        ->set('emergency_contact_phone', '12')
        ->call('save')
        ->assertHasErrors(['name' => 'required', 'age_bracket', 'emergency_contact_phone']);
});

it('edits a rider', function (): void {
    $user = User::factory()->create();
    $rider = Rider::factory()->for($user)->create(['name' => 'Old name']);

    Livewire::actingAs($user)->test(Riders::class)
        ->call('edit', $rider->id)
        ->assertSet('name', 'Old name')
        ->set('name', 'New name')
        ->call('save')
        ->assertSet('status', 'New name updated.');

    expect($rider->fresh()?->name)->toBe('New name');
});

it('removes an unused rider after confirmation, keeps a booked one', function (): void {
    $user = User::factory()->create();
    $unused = Rider::factory()->for($user)->create(['name' => 'Unused']);
    $booked = Rider::factory()->for($user)->create(['name' => 'Booked']);
    Booking::factory()->create(['rider_id' => $booked->id]);

    Livewire::actingAs($user)->test(Riders::class)
        ->call('confirmDelete', $unused->id)
        ->assertSee('Remove Unused?')
        ->call('delete', $unused->id)
        ->assertSet('status', 'Unused removed.')
        ->call('delete', $booked->id)
        ->assertSet('status', 'Booked has bookings or orders, so they stay on your account.');

    expect(Rider::query()->find($unused->id))->toBeNull()
        ->and(Rider::query()->find($booked->id))->not->toBeNull();
});

it('never lets a customer read, change or remove another customer’s rider', function (string $method): void {
    $owner = User::factory()->create();
    $rider = Rider::factory()->for($owner)->create(['name' => 'Private']);

    $component = Livewire::actingAs(User::factory()->create())->test(Riders::class);

    $component->call($method, $rider->id)->assertNotFound();

    expect($rider->fresh()?->name)->toBe('Private');
})->with(['edit', 'confirmDelete', 'delete']);

it('cannot save over another customer’s rider by setting the id', function (): void {
    $rider = Rider::factory()->create(['name' => 'Private']);

    // The id is #[Locked]: the browser can't change it at all.
    expect(fn () => Livewire::actingAs(User::factory()->create())->test(Riders::class)
        ->set('editingId', $rider->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect($rider->fresh()?->name)->toBe('Private');
});

it('normalises phone numbers to E.164 for Pakistan and abroad', function (string $typed, string $stored): void {
    expect(UpdateProfile::toE164($typed))->toBe($stored);
})->with([
    ['0300 1234567', '+923001234567'],
    ['+92 300 1234567', '+923001234567'],
    ['0092 300 1234567', '+923001234567'],
    ['+44 7700 900123', '+447700900123'],
    ['+1 (415) 555-2671', '+14155552671'],
]);

it('saves the profile with an E.164 phone and consent off by default', function (): void {
    $user = User::factory()->passwordless()->create();

    $this->actingAs($user)->get('/account/profile')
        ->assertOk()
        ->assertSee('name="marketing_consent"', false)
        ->assertDontSee('checked', false);

    $this->actingAs($user)->put('/account/profile', ['name' => 'Sana Malik', 'phone' => '0321 7654321', 'marketing_consent' => '0'])
        ->assertRedirect(route('account.profile'))
        ->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->name)->toBe('Sana Malik')
        ->and($user->phone)->toBe('+923217654321')
        ->and($user->marketing_consent)->toBeFalse()
        ->and($user->marketing_consent_at)->toBeNull();
});

it('timestamps every change of marketing consent and logs it', function (): void {
    $user = User::factory()->create();

    $this->travelTo(now()->setTime(9, 0));
    $this->actingAs($user)->put('/account/profile', ['name' => 'Sana', 'marketing_consent' => '1']);
    $given = $user->refresh()->marketing_consent_at;

    expect($user->marketing_consent)->toBeTrue()->and($given?->format('H:i'))->toBe('09:00');

    // Saving again without a change keeps the timestamp.
    $this->travel(1)->hour();
    $this->actingAs($user)->put('/account/profile', ['name' => 'Sana Malik', 'marketing_consent' => '1']);
    expect($user->refresh()->marketing_consent_at?->equalTo($given))->toBeTrue();

    $this->travel(1)->hour();
    $this->actingAs($user)->put('/account/profile', ['name' => 'Sana Malik', 'marketing_consent' => '0']);
    expect($user->refresh()->marketing_consent)->toBeFalse()
        ->and($user->marketing_consent_at?->format('H:i'))->toBe('11:00');

    expect(Activity::query()->where('subject_id', $user->id)->where('subject_type', $user->getMorphClass())->get()
        ->where('event', 'updated')
        ->filter(fn (Activity $a): bool => array_key_exists('marketing_consent', (array) $a->properties?->get('attributes')))
        ->count())->toBe(2);
});

it('rejects invalid profile input', function (): void {
    $this->actingAs(User::factory()->create())
        ->put('/account/profile', ['name' => '', 'phone' => '12345'])
        ->assertSessionHasErrors(['name', 'phone']);
});

it('does not let the profile form change email or role', function (): void {
    $user = User::factory()->create(['email' => 'me@example.com']);

    $this->actingAs($user)->put('/account/profile', ['name' => 'Me', 'email' => 'other@example.com', 'role' => 'admin']);

    $user->refresh();
    expect($user->email)->toBe('me@example.com')->and($user->isAdmin())->toBeFalse();
});
