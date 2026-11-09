<?php

declare(strict_types=1);

use App\Actions\Auth\ReplaceTemporaryPassword;
use App\Filament\Auth\ChangeTemporaryPassword;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
 * Temporary passwords (PLAN §7): admin:create gives a team member a
 * temporary password; the panel lets them do nothing but replace it (then
 * set up MFA), and the new one must meet the password rules.
 */

const TEMPORARY_PASSWORD = 'Temporary-Pass-1!';

const NEW_PASSWORD = 'Correct-Horse-9-Battery';

function teamMemberWithTemporaryPassword(bool $mfa = false): User
{
    $factory = User::factory()->admin();
    $user = ($mfa ? $factory->withMfa() : $factory)->create(['password' => TEMPORARY_PASSWORD]);
    $user->must_change_password = true;
    $user->save();

    return $user;
}

function changePasswordUrl(): string
{
    return route(ChangeTemporaryPassword::ROUTE_NAME);
}

it('flags accounts made or reset by admin:create', function (): void {
    $existing = User::factory()->admin()->withMfa()->create(['email' => 'existing@example.com']);

    foreach (['new@example.com', 'existing@example.com'] as $email) {
        $this->artisan('admin:create')
            ->expectsQuestion('Name', 'Team member')
            ->expectsQuestion('Email', $email)
            ->expectsQuestion('Role', 'admin')
            ->expectsQuestion(
                $email === 'existing@example.com' ? 'New password (the account already exists)' : 'Password',
                TEMPORARY_PASSWORD,
            )
            ->assertSuccessful();
    }

    expect(User::query()->where('email', 'new@example.com')->sole()->must_change_password)->toBeTrue()
        ->and($existing->refresh()->must_change_password)->toBeTrue()
        ->and(Hash::check(TEMPORARY_PASSWORD, $existing->password))->toBeTrue();
});

it('never flags customers or accounts made elsewhere', function (): void {
    expect(User::factory()->create()->refresh()->must_change_password)->toBeFalse()
        ->and(User::factory()->admin()->create()->refresh()->must_change_password)->toBeFalse();
});

it('sends a team member with a temporary password to the change-password screen first', function (bool $mfa): void {
    $this->actingAs(teamMemberWithTemporaryPassword($mfa));

    // Ahead of the MFA set-up, and from every panel page.
    $this->get('/test-admin')->assertRedirect(changePasswordUrl());
    $this->get('/test-admin/verify-transfers')->assertRedirect(changePasswordUrl());
    $this->get('/test-admin/profile')->assertRedirect(changePasswordUrl());
    $this->get(Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl())->assertRedirect(changePasswordUrl());

    $this->get(changePasswordUrl())->assertOk()->assertSee('Change your temporary password');
})->with(['without MFA' => false, 'with MFA' => true]);

it('blocks Livewire requests from the rest of the panel', function (): void {
    $this->actingAs(teamMemberWithTemporaryPassword(mfa: true))
        ->withHeader('X-Livewire', 'true')
        ->get('/test-admin')
        ->assertForbidden();
});

it('still lets them sign out', function (): void {
    $this->actingAs(teamMemberWithTemporaryPassword())
        ->post('/test-admin/logout')
        ->assertRedirect();

    $this->assertGuest();
});

it('sends everyone else away from the change-password screen', function (): void {
    $this->actingAs(User::factory()->admin()->withMfa()->create())
        ->get(changePasswordUrl())
        ->assertRedirect(Filament::getPanel('admin')->getUrl());
});

it('replaces the temporary password, then goes on to the MFA set-up', function (): void {
    $user = teamMemberWithTemporaryPassword();
    $this->actingAs($user);

    Livewire::test(ChangeTemporaryPassword::class)
        ->fillForm(['password' => NEW_PASSWORD, 'passwordConfirmation' => NEW_PASSWORD])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(Filament::getPanel('admin')->getUrl());

    $user->refresh();

    expect($user->must_change_password)->toBeFalse()
        ->and(Hash::check(NEW_PASSWORD, $user->password))->toBeTrue()
        ->and(Activity::query()->where('log_name', 'auth')->where('event', 'password_changed')->where('subject_id', $user->id)->exists())->toBeTrue();

    // Audited without the value.
    expect(Activity::query()->where('subject_id', $user->id)->get()->toJson())->not->toContain(NEW_PASSWORD);

    $this->get('/test-admin')->assertRedirect(Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl());
});

it('enforces the password rules', function (string $password, string $confirmation): void {
    $user = teamMemberWithTemporaryPassword();
    $this->actingAs($user);

    Livewire::test(ChangeTemporaryPassword::class)
        ->fillForm(['password' => $password, 'passwordConfirmation' => $confirmation])
        ->call('save')
        ->assertHasFormErrors(['password']);

    expect($user->refresh()->must_change_password)->toBeTrue()
        ->and(Hash::check(TEMPORARY_PASSWORD, $user->password))->toBeTrue();
})->with([
    'too short' => ['Ab1!short', 'Ab1!short'],
    'no symbol' => ['Abcdefghijk12', 'Abcdefghijk12'],
    'confirmation differs' => [NEW_PASSWORD, NEW_PASSWORD.'x'],
]);

it('rejects the temporary password as the new one', function (): void {
    $user = teamMemberWithTemporaryPassword();
    $this->actingAs($user);

    Livewire::test(ChangeTemporaryPassword::class)
        ->fillForm(['password' => TEMPORARY_PASSWORD, 'passwordConfirmation' => TEMPORARY_PASSWORD])
        ->call('save')
        ->assertHasErrors(['password']);

    expect($user->refresh()->must_change_password)->toBeTrue();
});

it('replaces the password and clears the flag (action)', function (): void {
    $user = teamMemberWithTemporaryPassword();

    app(ReplaceTemporaryPassword::class)->handle($user, NEW_PASSWORD);

    expect($user->refresh()->must_change_password)->toBeFalse()
        ->and(Hash::check(NEW_PASSWORD, $user->password))->toBeTrue();

    expect(fn () => app(ReplaceTemporaryPassword::class)->handle($user, NEW_PASSWORD))
        ->toThrow(ValidationException::class);
});
