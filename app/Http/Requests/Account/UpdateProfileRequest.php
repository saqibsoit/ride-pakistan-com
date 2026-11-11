<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Actions\Account\SaveWhatsAppNumber;
use App\Actions\Account\UpdateProfile;
use Illuminate\Foundation\Http\FormRequest;
use Propaganistas\LaravelPhone\Rules\Phone;

final class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            // Pakistani numbers nationally (0300 1234567) or any country with +.
            'phone' => ['nullable', 'string', 'max:32', (new Phone)->international()->country(UpdateProfile::DEFAULT_PHONE_COUNTRY)],
            'whatsapp_phone' => SaveWhatsAppNumber::rules(required: false),
            'marketing_consent' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter your name.',
            'phone' => 'Enter a valid phone number, for example 0300 1234567 or +44 7700 900123.',
            'whatsapp_phone' => SaveWhatsAppNumber::MESSAGE,
        ];
    }

    /**
     * @return array{name: string, phone: string|null, whatsapp_phone: string|null, marketing_consent: bool}
     */
    public function profile(): array
    {
        $phone = $this->input('phone');
        $whatsapp = $this->input('whatsapp_phone');

        return [
            'name' => $this->string('name')->toString(),
            'phone' => is_string($phone) ? $phone : null,
            'whatsapp_phone' => is_string($whatsapp) ? $whatsapp : null,
            'marketing_consent' => $this->boolean('marketing_consent'),
        ];
    }
}
