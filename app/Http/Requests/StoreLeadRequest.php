<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Actions\Leads\CaptureLead;
use App\Enums\LeadInterest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Contact form. Either a phone number or an email is required so we can
 * reply. "website" is a honeypot: real visitors never see or fill it.
 */
final class StoreLeadRequest extends FormRequest
{
    public const HONEYPOT = 'website';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tracking = [];

        foreach (CaptureLead::ATTRIBUTION_FIELDS as $field) {
            $tracking[$field] = ['nullable', 'string', 'max:2000'];
        }

        return [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:32', 'regex:/^\+?[0-9 ()-]{7,20}$/'],
            'email' => ['nullable', 'required_without:phone', 'string', 'email:rfc', 'max:190'],
            'interest' => ['nullable', Rule::enum(LeadInterest::class)],
            'preferred_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'message' => ['nullable', 'string', 'max:2000'],
            self::HONEYPOT => ['nullable', 'string', 'max:255'],
            ...$tracking,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.required_without' => 'Add a phone number or an email so we can reply.',
            'email.required_without' => 'Add an email or a phone number so we can reply.',
            'phone.regex' => 'Enter a phone number using digits, for example +92 300 1234567.',
            'preferred_date.after_or_equal' => 'Choose today or a later date.',
        ];
    }

    public function isSpam(): bool
    {
        return filled($this->input(self::HONEYPOT));
    }
}
