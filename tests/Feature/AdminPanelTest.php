<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Auth\Login;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

it('serves the admin panel at the configured path', function (): void {
    expect(config('app.admin_path'))->toBe('test-admin');

    $this->get('/test-admin/login')
        ->assertOk()
        ->assertSee('Sign in');
});

it('redirects guests from the admin panel to its login page', function (): void {
    $this->get('/test-admin')
        ->assertRedirect('/test-admin/login');
});

it('does not serve the admin panel at the default path', function (): void {
    $this->get('/admin/login')->assertNotFound();
});

it('denies panel access to customers', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/test-admin')
        ->assertForbidden();
});

it('creates new users as customers and never mass-assigns the role', function (): void {
    $user = User::query()->create([
        'name' => 'Sana',
        'email' => 'sana@example.com',
        'password' => 'Secret-password-1',
        'role' => 'admin',
    ]);

    expect($user->refresh()->role)->toBe(UserRole::Customer);
});

it('sends staff and admins without MFA to the MFA set-up page', function (string $state): void {
    $user = User::factory()->{$state}()->create();

    $this->actingAs($user)
        ->get('/test-admin')
        ->assertRedirect(Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl());
})->with(['staff', 'admin']);

it('lets staff and admins with MFA into the panel', function (string $state): void {
    $this->actingAs(User::factory()->{$state}()->withMfa()->create())
        ->get('/test-admin')
        ->assertOk();
})->with(['staff', 'admin']);

it('stores the MFA secret and recovery codes encrypted and hidden', function (): void {
    $user = User::factory()->admin()->withMfa()->create();

    $raw = DB::table('users')->where('id', $user->id)->first();

    expect($raw->app_authentication_secret)->not->toBe($user->getAppAuthenticationSecret())
        ->and($user->toArray())->not->toHaveKeys(['app_authentication_secret', 'app_authentication_recovery_codes', 'password']);
});

it('throttles sign-in attempts per email address across IPs', function (): void {
    $user = User::factory()->admin()->withMfa()->create();
    $key = 'admin-login:'.hash('sha256', strtolower($user->email));

    for ($i = 0; $i < Login::MAX_ATTEMPTS_PER_EMAIL; $i++) {
        RateLimiter::hit($key, Login::DECAY_SECONDS);
    }

    Livewire::test(Login::class)
        ->fillForm(['email' => strtoupper($user->email), 'password' => 'password'])
        ->call('authenticate')
        ->assertNotified('Too many sign-in attempts');

    expect(RateLimiter::attempts($key))->toBe(Login::MAX_ATTEMPTS_PER_EMAIL);
});

it('counts failed sign-ins against the email address', function (): void {
    $user = User::factory()->admin()->withMfa()->create();
    $key = 'admin-login:'.hash('sha256', strtolower($user->email));

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'wrong-password'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    expect(RateLimiter::attempts($key))->toBe(1);
});

it('requires strong passwords', function (string $password, bool $valid): void {
    $passes = Validator::make(['password' => $password], ['password' => Password::defaults()])->passes();

    expect($passes)->toBe($valid);
})->with([
    'too short' => ['Ab1!short', false],
    'no symbol' => ['Abcdefghijk12', false],
    'no upper case' => ['abcdefghij1!x', false],
    'strong' => ['Correct-Horse-9-Battery', true],
]);

it('creates a team member from the command and logs it', function (): void {
    $this->artisan('admin:create')
        ->expectsQuestion('Name', 'Omar Saleem')
        ->expectsQuestion('Email', 'omar@example.com')
        ->expectsQuestion('Role', 'admin')
        ->expectsQuestion('Password', 'Correct-Horse-9-Battery')
        ->assertSuccessful();

    $user = User::query()->where('email', 'omar@example.com')->firstOrFail();

    expect($user->role)->toBe(UserRole::Admin)
        ->and(Hash::check('Correct-Horse-9-Battery', $user->password))->toBeTrue()
        ->and(Activity::query()->where('log_name', 'users')->where('subject_id', $user->id)->exists())->toBeTrue();
});
