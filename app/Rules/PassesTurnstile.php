<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Turnstile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Runs even when the token field is missing (a bot that skips the widget). */
final class PassesTurnstile implements ValidationRule
{
    public bool $implicit = true;

    public function __construct(private readonly ?string $ip = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! app(Turnstile::class)->verify(is_string($value) ? $value : null, $this->ip)) {
            $fail('Please complete the security check and try again.');
        }
    }
}
