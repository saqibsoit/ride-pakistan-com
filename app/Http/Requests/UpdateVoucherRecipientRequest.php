<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Correcting a gift voucher's recipient before it is sent (milestone 10e). */
final class UpdateVoucherRecipientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null; // ownership: the controller scopes the purchase to the user
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'recipient_name' => ['required', 'string', 'max:120'],
            'recipient_email' => ['required', 'string', 'email:rfc', 'max:190'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['recipient_name' => 'recipient’s name', 'recipient_email' => 'recipient’s email'];
    }
}
