<?php

declare(strict_types=1);

use App\Actions\Auth\RequestLoginCode;
use App\Actions\Auth\VerifyLoginCode;
use App\Auth\LoginCodes\LoginCodeHasher;
use App\Enums\LoginCodeFailure;
use App\Exceptions\LoginCodeRejected;
use App\Mail\LoginCodeMail;
use App\Models\LoginCode;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();
});

/** Request a code and return the plain code from the sent email. */
function issueCode(string $email): string
{
    app(RequestLoginCode::class)->handle($email, '203.0.113.5');

    $mail = Mail::sent(LoginCodeMail::class)->last();
    expect($mail)->toBeInstanceOf(LoginCodeMail::class);

    return $mail->code;
}

function rejection(callable $callback): ?LoginCodeRejected
{
    try {
        $callback();
    } catch (LoginCodeRejected $e) {
        return $e;
    }

    return null;
}

it('sends a six-digit code and stores only its HMAC', function (): void {
    $code = issueCode('  Sana@Example.COM ');

    $row = LoginCode::query()->sole();

    expect($code)->toMatch('/^\d{6}$/')
        ->and($row->destination)->toBe('sana@example.com')
        ->and($row->code_hash)->not->toContain($code)
        ->and($row->code_hash)->toBe(app(LoginCodeHasher::class)->hash('sana@example.com', $code))
        ->and($row->ip_address)->toBe('203.0.113.5')
        ->and($row->expires_at->diffInMinutes(now(), true))->toBeGreaterThan(9.9)->toBeLessThanOrEqual(10.0);

    Mail::assertSent(LoginCodeMail::class, fn (LoginCodeMail $mail): bool => $mail->hasTo('sana@example.com'));
});

it('creates the account on the first successful verification, once', function (): void {
    $code = issueCode('new@example.com');

    expect(User::query()->where('email', 'new@example.com')->exists())->toBeFalse();

    $user = app(VerifyLoginCode::class)->handle('NEW@example.com', $code);

    expect($user->email)->toBe('new@example.com')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->name)->toBeNull()
        ->and($user->password)->toBeNull()
        ->and($user->isTeamMember())->toBeFalse();

    $again = app(VerifyLoginCode::class)->handle('new@example.com', issueCode('new@example.com'));

    expect($again->is($user))->toBeTrue()
        ->and(User::query()->where('email', 'new@example.com')->count())->toBe(1);
});

it('signs an existing customer into their own account', function (): void {
    $customer = User::factory()->create(['email' => 'rider@example.com']);

    $user = app(VerifyLoginCode::class)->handle('rider@example.com', issueCode('rider@example.com'));

    expect($user->is($customer))->toBeTrue();
});

it('accepts a code only once', function (): void {
    $code = issueCode('once@example.com');
    app(VerifyLoginCode::class)->handle('once@example.com', $code);

    $e = rejection(fn () => app(VerifyLoginCode::class)->handle('once@example.com', $code));

    expect($e?->failure)->toBe(LoginCodeFailure::Expired);
});

it('rejects a code after ten minutes', function (): void {
    $code = issueCode('late@example.com');

    $this->travel(10)->minutes();

    $e = rejection(fn () => app(VerifyLoginCode::class)->handle('late@example.com', $code));

    expect($e?->failure)->toBe(LoginCodeFailure::Expired)
        ->and(User::query()->where('email', 'late@example.com')->exists())->toBeFalse();
});

it('still accepts a code just before it expires', function (): void {
    $code = issueCode('ontime@example.com');

    $this->travel(9)->minutes();
    $this->travel(59)->seconds();

    expect(app(VerifyLoginCode::class)->handle('ontime@example.com', $code)->email)->toBe('ontime@example.com');
});

it('locks a code after five wrong guesses, even if the right code follows', function (): void {
    $code = issueCode('guess@example.com');
    $wrong = $code === '111111' ? '222222' : '111111';

    foreach ([4, 3, 2, 1] as $left) {
        $e = rejection(fn () => app(VerifyLoginCode::class)->handle('guess@example.com', $wrong));
        expect($e?->failure)->toBe(LoginCodeFailure::Invalid)->and($e?->attemptsLeft)->toBe($left);
    }

    $fifth = rejection(fn () => app(VerifyLoginCode::class)->handle('guess@example.com', $wrong));
    expect($fifth?->failure)->toBe(LoginCodeFailure::Locked);

    $right = rejection(fn () => app(VerifyLoginCode::class)->handle('guess@example.com', $code));
    expect($right)->not->toBeNull()
        ->and(LoginCode::query()->sole()->attempts)->toBe(5)
        ->and(User::query()->where('email', 'guess@example.com')->exists())->toBeFalse();
});

it('replaces earlier codes when a new one is requested', function (): void {
    $first = issueCode('twice@example.com');
    $second = issueCode('twice@example.com');

    if ($first !== $second) {
        expect(rejection(fn () => app(VerifyLoginCode::class)->handle('twice@example.com', $first)))->not->toBeNull();
    }

    expect(app(VerifyLoginCode::class)->handle('twice@example.com', $second)->email)->toBe('twice@example.com')
        ->and(LoginCode::query()->whereNull('consumed_at')->count())->toBe(0);
});

it('never sends a code to staff, trainers or admins, whose sign-in needs MFA', function (string $role): void {
    $user = User::factory()->{$role}()->create();

    $sent = app(RequestLoginCode::class)->handle($user->email);

    expect($sent)->toBeFalse()
        ->and(LoginCode::query()->count())->toBe(0);
    Mail::assertNothingSent();
})->with(['admin', 'staff', 'trainer']);

it('refuses a team account even with a valid code row', function (): void {
    $admin = User::factory()->admin()->create();
    LoginCode::query()->create([
        'channel' => 'email',
        'destination' => $admin->email,
        'code_hash' => app(LoginCodeHasher::class)->hash($admin->email, '123456'),
        'expires_at' => now()->addMinutes(10),
    ]);

    $e = rejection(fn () => app(VerifyLoginCode::class)->handle($admin->email, '123456'));

    expect($e)->not->toBeNull();
});

it('sends a code to a deleted account inside the grace period, not after it', function (): void {
    $recent = User::factory()->deletedDaysAgo(5)->create();
    $old = User::factory()->deletedDaysAgo(31)->create();

    expect(app(RequestLoginCode::class)->handle($recent->email))->toBeTrue()
        ->and(app(RequestLoginCode::class)->handle($old->email))->toBeFalse();

    $user = app(VerifyLoginCode::class)->handle($recent->email, Mail::sent(LoginCodeMail::class)->last()->code);

    expect($user->trashed())->toBeTrue();
});

it('ignores spaces and dashes typed into the code', function (): void {
    $code = issueCode('spaces@example.com');

    $typed = substr($code, 0, 3).' - '.substr($code, 3);

    expect(app(VerifyLoginCode::class)->handle('spaces@example.com', $typed)->email)->toBe('spaces@example.com');
});
