<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Rules\PassesTurnstile;
use App\Support\Turnstile;
use Illuminate\Foundation\Http\FormRequest;

final class RequestLoginCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            Turnstile::RESPONSE_FIELD => [new PassesTurnstile($this->ip())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Enter your email address.',
            'email.email' => 'Enter an email address like name@example.com.',
        ];
    }

    public function email(): string
    {
        return $this->string('email')->trim()->lower()->toString();
    }
}
