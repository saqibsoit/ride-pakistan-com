<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use App\Http\Middleware\EnsureSessionIsCurrent;
use App\Mail\LoginCodeMail;
use App\Models\LoginCode;
use App\Models\User;
use App\Support\Turnstile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    Mail::fake();
    RateLimiter::clear('login-email-10m:'.hash('sha256', 'flow@example.com'));
    config()->set('services.turnstile.site_key', null);
    config()->set('services.turnstile.secret_key', null);
});

function lastSentCode(): string
{
    $mail = Mail::sent(LoginCodeMail::class)->last();
    expect($mail)->toBeInstanceOf(LoginCodeMail::class);

    return $mail->code;
}

it('shows the sign-in page, never indexed or cached', function (): void {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Sign in.')
        ->assertSee('name="email"', false)
        ->assertHeader('X-Robots-Tag')
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('signs a new customer in end to end with a fresh session', function (): void {
    $this->get('/login');
    $before = session()->getId();

    $this->post('/login', ['email' => 'Flow@Example.com'])
        ->assertRedirect(route('login.verify'));

    $this->get(route('login.verify'))
        ->assertOk()
        ->assertSee('flow@example.com');

    $this->post(route('login.verify.store'), ['code' => lastSentCode()])
        ->assertRedirect(route('account.dashboard'));

    $user = User::query()->where('email', 'flow@example.com')->sole();

    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($before)
        ->and(session(EnsureSessionIsCurrent::SESSION_KEY))->toBe(0)
        ->and(session(LoginController::PENDING_EMAIL))->toBeNull();
});

it('returns to the page the customer first asked for', function (): void {
    $this->get('/account/orders')->assertRedirect(route('login'));

    $this->post('/login', ['email' => 'flow@example.com']);
    $this->post(route('login.verify.store'), ['code' => lastSentCode()])
        ->assertRedirect(route('account.orders'));
});

it('gives the same response whether or not the email can sign in', function (): void {
    $admin = User::factory()->admin()->create(['email' => 'boss@example.com']);

    $team = $this->post('/login', ['email' => $admin->email]);
    $this->flushSession();
    $stranger = $this->post('/login', ['email' => 'flow@example.com']);

    expect($team->headers->get('Location'))->toBe($stranger->headers->get('Location'));
    $this->get(route('login.verify'))->assertSee('If <strong class="text-sand">flow@example.com</strong> can sign in', false);
    Mail::assertSentCount(1);
});

it('shows a clear error for a wrong code and keeps the customer signed out', function (): void {
    $this->post('/login', ['email' => 'flow@example.com']);
    $code = lastSentCode();

    $this->from(route('login.verify'))
        ->post(route('login.verify.store'), ['code' => $code === '000000' ? '111111' : '000000'])
        ->assertRedirect(route('login.verify'))
        ->assertSessionHasErrors(['code' => 'That code isn’t right. You have 4 tries left.']);

    $this->assertGuest();
});

it('validates the code format before checking it', function (): void {
    $this->post('/login', ['email' => 'flow@example.com']);

    $this->post(route('login.verify.store'), ['code' => '12'])->assertSessionHasErrors('code');

    expect(LoginCode::query()->sole()->attempts)->toBe(0);
});

it('sends the code form back to the email step without a pending email', function (): void {
    $this->get(route('login.verify'))->assertRedirect(route('login'));
    $this->post(route('login.verify.store'), ['code' => '123456'])->assertRedirect(route('login'));
});

it('limits code requests per email', function (): void {
    foreach (range(1, 3) as $i) {
        $this->post('/login', ['email' => 'flow@example.com'])->assertSessionHasNoErrors();
    }

    $this->from('/login')->post('/login', ['email' => 'FLOW@example.com'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['email' => 'Too many code requests. Please wait a few minutes and try again.']);

    Mail::assertSentCount(3);
});

it('limits code requests per IP address across emails', function (): void {
    foreach (range(1, 10) as $i) {
        $this->post('/login', ['email' => "ip{$i}@example.com"])->assertSessionHasNoErrors();
    }

    $this->post('/login', ['email' => 'ip11@example.com'])->assertSessionHasErrors('email');

    Mail::assertSentCount(10);
});

it('limits code submissions per IP address', function (): void {
    $this->post('/login', ['email' => 'flow@example.com']);

    foreach (range(1, 10) as $i) {
        $this->post(route('login.verify.store'), ['code' => 'abc']);
    }

    $this->post(route('login.verify.store'), ['code' => lastSentCode()])
        ->assertSessionHasErrors(['code' => 'Too many attempts. Please wait a minute and try again.']);

    $this->assertGuest();
});

it('requires a passing Turnstile check when keys are set', function (): void {
    config()->set('services.turnstile.site_key', 'site-key');
    config()->set('services.turnstile.secret_key', 'secret-key');

    Http::fake([
        'challenges.cloudflare.com/*' => Http::sequence()
            ->push(['success' => false])
            ->push(['success' => true]),
    ]);

    $this->get('/login')->assertSee('data-sitekey="site-key"', false)->assertSee(Turnstile::SCRIPT_URL, false);

    $this->post('/login', ['email' => 'flow@example.com', 'cf-turnstile-response' => 'bad'])
        ->assertSessionHasErrors([Turnstile::RESPONSE_FIELD]);
    Mail::assertNothingSent();

    $this->post('/login', ['email' => 'flow@example.com', 'cf-turnstile-response' => 'good'])
        ->assertSessionHasNoErrors();
    Mail::assertSentCount(1);

    Http::assertSent(fn ($request): bool => $request['secret'] === 'secret-key' && $request['response'] === 'good');
});

it('rejects a missing Turnstile token without calling Cloudflare', function (): void {
    config()->set('services.turnstile.secret_key', 'secret-key');
    Http::fake();

    $this->post('/login', ['email' => 'flow@example.com'])->assertSessionHasErrors([Turnstile::RESPONSE_FIELD]);

    Http::assertNothingSent();
});

it('fails closed in production when Turnstile is not configured', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    expect(app(Turnstile::class)->verify('anything'))->toBeFalse();

    app()->detectEnvironment(fn (): string => 'testing');

    expect(app(Turnstile::class)->verify(null))->toBeTrue();
});

it('treats a Cloudflare outage as a failed check', function (): void {
    config()->set('services.turnstile.secret_key', 'secret-key');
    Http::fake(['challenges.cloudflare.com/*' => Http::failedConnection()]);

    expect(app(Turnstile::class)->verify('token'))->toBeFalse();
});

it('sends signed-in customers away from the sign-in page', function (): void {
    $this->actingAs(User::factory()->create())->get('/login')->assertRedirect(route('account.dashboard'));
});

it('signs out with a new session and CSRF token', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout')->assertRedirect(route('home'));

    $this->assertGuest();
});

it('offers to keep an account that is scheduled for deletion', function (): void {
    $user = User::factory()->deletedDaysAgo(3)->create(['email' => 'flow@example.com']);

    $this->post('/login', ['email' => 'flow@example.com']);
    $this->post(route('login.verify.store'), ['code' => lastSentCode()])
        ->assertRedirect(route('account.restore'));

    $this->assertGuest();

    $this->get(route('account.restore'))
        ->assertOk()
        ->assertSee('This account is scheduled for deletion.')
        ->assertSee(now()->subDays(3)->addDays(30)->setTimezone('Asia/Karachi')->format('j F Y'));

    $this->post(route('account.restore.store'))->assertRedirect(route('account.dashboard'));

    expect($user->fresh()?->trashed())->toBeFalse();
    $this->assertAuthenticatedAs($user->fresh());
    $this->get(route('account.dashboard'))->assertSee('Welcome back.');
});

it('lets the customer leave the account scheduled for deletion', function (): void {
    $user = User::factory()->deletedDaysAgo(3)->create(['email' => 'flow@example.com']);

    $this->post('/login', ['email' => 'flow@example.com']);
    $this->post(route('login.verify.store'), ['code' => lastSentCode()]);

    $this->delete(route('account.restore.cancel'))->assertRedirect(route('home'));

    $this->assertGuest();
    expect(User::withTrashed()->find($user->id)?->trashed())->toBeTrue();
    $this->get(route('account.restore'))->assertRedirect(route('login'));
});

it('does not open the restore page without a verified code', function (): void {
    User::factory()->deletedDaysAgo(3)->create();

    $this->get(route('account.restore'))->assertRedirect(route('login'));
    $this->post(route('account.restore.store'))->assertRedirect(route('login'));
});

it('expires the restore offer after fifteen minutes', function (): void {
    User::factory()->deletedDaysAgo(3)->create(['email' => 'flow@example.com']);

    $this->post('/login', ['email' => 'flow@example.com']);
    $this->post(route('login.verify.store'), ['code' => lastSentCode()]);

    $this->travel(16)->minutes();

    $this->post(route('account.restore.store'))->assertRedirect(route('login'));
    $this->assertGuest();
});
