<?php

declare(strict_types=1);

use App\Actions\Auth\RecordFailedTeamLogin;
use App\Filament\Auth\Login;
use App\Http\Middleware\EnforceAdminIdleTimeout;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\User;
use App\Notifications\FailedTeamLogin;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/*
| Idle timeout
*/

it('signs an idle admin out of the panel', function (): void {
    $admin = User::factory()->admin()->withMfa()->create();

    $this->actingAs($admin)->get('/test-admin')->assertOk();

    $this->travel(29)->minutes();
    $this->get('/test-admin')->assertOk();

    $this->travel(31)->minutes();
    $this->get('/test-admin')->assertRedirect(Filament::getLoginUrl());

    $this->assertGuest();
    expect(Activity::query()->where('event', 'admin_idle_timeout')->where('causer_id', $admin->id)->exists())->toBeTrue();
});

it('does not count Livewire polling as activity', function (): void {
    $admin = User::factory()->admin()->withMfa()->create();
    $this->actingAs($admin)->get('/test-admin')->assertOk();
    $started = session(EnforceAdminIdleTimeout::SESSION_KEY);

    $this->travel(10)->minutes();

    // A poll: no updates, no calls.
    $request = Request::create('/livewire/update', 'POST', ['components' => [['snapshot' => '{}', 'updates' => [], 'calls' => []]]]);
    $request->headers->set('X-Livewire', '1');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => $admin);

    app(EnforceAdminIdleTimeout::class)->handle($request, fn () => response('ok'));

    expect(session(EnforceAdminIdleTimeout::SESSION_KEY))->toBe($started);

    // A real action does count.
    $request = Request::create('/livewire/update', 'POST', ['components' => [['snapshot' => '{}', 'updates' => [], 'calls' => [['method' => 'save', 'params' => []]]]]]);
    $request->headers->set('X-Livewire', '1');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => $admin);

    app(EnforceAdminIdleTimeout::class)->handle($request, fn () => response('ok'));

    expect(session(EnforceAdminIdleTimeout::SESSION_KEY))->toBeGreaterThan($started);
});

/*
| Failed sign-in alerts
*/

it('emails the account and every admin after five failed sign-ins, once an hour', function (): void {
    Notification::fake();

    $staff = User::factory()->staff()->withMfa()->create();
    $adminA = User::factory()->admin()->withMfa()->create();
    $adminB = User::factory()->admin()->withMfa()->create();
    $customer = User::factory()->create();

    // Five wrong passwords through the real form (Filament allows five a
    // minute per IP), then two more failures in the same hour.
    for ($i = 0; $i < 5; $i++) {
        Livewire::test(Login::class)
            ->fillForm(['email' => $staff->email, 'password' => 'wrong-password'])
            ->call('authenticate');
    }

    app(RecordFailedTeamLogin::class)->handle($staff, RecordFailedTeamLogin::KIND_PASSWORD, '203.0.113.9', 'Test');
    app(RecordFailedTeamLogin::class)->handle($staff, RecordFailedTeamLogin::KIND_MFA, '203.0.113.9', 'Test');

    Notification::assertSentToTimes($staff, FailedTeamLogin::class, 1);
    Notification::assertSentToTimes($adminA, FailedTeamLogin::class, 1);
    Notification::assertSentToTimes($adminB, FailedTeamLogin::class, 1);
    Notification::assertNotSentTo($customer, FailedTeamLogin::class);

    expect(Activity::query()->where('event', 'admin_login_failed')->where('subject_id', $staff->id)->count())->toBe(7);
});

it('stays below the threshold quietly', function (): void {
    Notification::fake();
    $admin = User::factory()->admin()->withMfa()->create();

    for ($i = 0; $i < 4; $i++) {
        app(RecordFailedTeamLogin::class)->handle($admin, RecordFailedTeamLogin::KIND_PASSWORD, '203.0.113.9', 'Test');
    }

    Notification::assertNothingSent();
});

it('never alerts or audits for customers or unknown emails', function (): void {
    Notification::fake();
    User::factory()->admin()->withMfa()->create();
    $customer = User::factory()->create();

    foreach ([$customer->email, 'nobody@example.com'] as $email) {
        // Past Filament's five-a-minute IP limit for the next email.
        $this->travel(2)->minutes();

        for ($i = 0; $i < 5; $i++) {
            Livewire::test(Login::class)
                ->fillForm(['email' => $email, 'password' => 'wrong-password'])
                ->call('authenticate');
        }
    }

    Notification::assertNothingSent();
    expect(Activity::query()->where('event', 'admin_login_failed')->exists())->toBeFalse();
});

it('counts wrong MFA codes as failed sign-ins', function (): void {
    $admin = User::factory()->admin()->withMfa()->create();

    $component = Livewire::test(Login::class)
        ->fillForm(['email' => $admin->email, 'password' => 'password'])
        ->call('authenticate');

    $component->fillForm(['multiFactor' => ['app' => ['code' => '000000']]])
        ->call('authenticate');

    $this->assertGuest();
    expect(Activity::query()->where('event', 'admin_login_failed')->where('subject_id', $admin->id)->sole()->properties->get('kind'))
        ->toBe(RecordFailedTeamLogin::KIND_MFA);
});

/*
| Audit log
*/

it('audits team sign-ins, sign-outs and MFA changes, never customers', function (): void {
    $admin = User::factory()->admin()->withMfa()->create();
    $customer = User::factory()->create();

    event(new Illuminate\Auth\Events\Login('web', $admin, false));
    event(new Logout('web', $admin));
    event(new Illuminate\Auth\Events\Login('web', $customer, false));

    $admin->app_authentication_secret = null;
    $admin->save();

    $events = Activity::query()->where('log_name', 'auth')->pluck('event')->all();

    expect($events)->toContain('admin_signed_in', 'admin_signed_out', 'mfa_disabled')
        ->and(Activity::query()->where('log_name', 'auth')->where('subject_id', $customer->id)->exists())->toBeFalse();

    // Values are never logged.
    $mfa = Activity::query()->where('event', 'mfa_disabled')->sole();
    expect($mfa->properties->toArray())->toBe([]);
});

it('audits lead notes and lead deletes without copying their text', function (): void {
    $admin = User::factory()->admin()->withMfa()->create();
    $this->actingAs($admin);

    $lead = Lead::factory()->create(['name' => 'Private Name']);
    $note = new LeadNote(['body' => 'Called, wants Sunday']);
    $note->lead()->associate($lead);
    $note->author_id = $admin->id;
    $note->save();
    $note->delete();
    $lead->delete();

    $rows = Activity::query()->where('log_name', 'leads')->get();

    expect($rows->pluck('event')->all())->toContain('created', 'deleted')
        ->and($rows->every(fn (Activity $row): bool => $row->causer_id === $admin->id))->toBeTrue()
        ->and($rows->toJson())->not->toContain('Private Name')->not->toContain('wants Sunday');
});

it('requires MFA for every panel user', function (string $role): void {
    $user = User::factory()->{$role}()->create();

    $this->actingAs($user)->get('/test-admin')
        ->assertRedirect(Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl());
})->with(['staff', 'admin']);
