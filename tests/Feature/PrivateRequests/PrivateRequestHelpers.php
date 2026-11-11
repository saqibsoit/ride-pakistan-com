<?php

declare(strict_types=1);

use App\Actions\PrivateRequests\ApprovePrivateRequest;
use App\Actions\PrivateRequests\SubmitPrivateRequest;
use App\Models\PrivateSessionRequest;
use App\Models\Product;
use App\Models\Rider;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
| Helpers for the prompt 15B tests. Prefixed `pr` so they never clash with
| other test files' helpers (test files share one global namespace).
*/

function prProduct(): Product
{
    return Product::query()->where('type', 'private')->first() ?? Product::factory()->private()->create();
}

/** A Pakistan local time $days ahead at $time ("10:00"), in UTC. */
function prStart(int $days = 3, string $time = '10:00'): CarbonImmutable
{
    [$h, $m] = array_map('intval', explode(':', $time));

    return CarbonImmutable::now((string) config('app.display_timezone'))->addDays($days)->setTime($h, $m)->utc();
}

/** A customer with WhatsApp and $riders riders. */
function prCustomer(int $riders = 1): User
{
    $user = User::factory()->create();
    Rider::factory()->count($riders)->for($user)->create();

    return $user;
}

/** Sends a request through the real action. */
function prRequest(?User $user = null, ?CarbonImmutable $start = null, int $riders = 1, ?string $note = null): PrivateSessionRequest
{
    $user ??= prCustomer($riders);

    return app(SubmitPrivateRequest::class)->handle($user, prProduct(), $start ?? prStart(), $user->riders()->pluck('id')->map(fn ($id): int => (int) $id)->all(), $note);
}

function prAdmin(): User
{
    return User::query()->where('role', 'admin')->first() ?? User::factory()->admin()->create();
}

function prApprove(PrivateSessionRequest $request, ?User $trainer = null): PrivateSessionRequest
{
    return app(ApprovePrivateRequest::class)->handle($request, prAdmin(), $trainer);
}

/** A tiny JPEG on disk, for bank transfer proofs. */
function prProofFile(): string
{
    $image = imagecreatetruecolor(60, 40);
    ob_start();
    imagejpeg($image);
    $path = (string) tempnam(sys_get_temp_dir(), 'proof');
    file_put_contents($path, (string) ob_get_clean());

    return $path;
}
