<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class VerifyLoginCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $code = $this->input('code');

        if (is_string($code)) {
            $this->merge(['code' => preg_replace('/\D/', '', $code)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $length = (int) config('login.code.length');

        return [
            'code' => ['required', 'string', 'digits:'.$length],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $length = (int) config('login.code.length');

        return [
            'code.required' => 'Enter the '.$length.'-digit code from the email.',
            'code.digits' => 'The code has '.$length.' digits.',
        ];
    }
}
