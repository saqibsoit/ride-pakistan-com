<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Moving a credit booking from the manage page (guest checkout). Access to
 * the order comes from EnsureOrderAccess on the route; the booking and the
 * cutoff are checked by the controller and RescheduleBooking.
 */
final class ManageRescheduleRequest extends FormRequest
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
            'slot' => ['required', 'integer', 'exists:slots,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slot.required' => 'Choose a new slot.',
            'slot.exists' => 'Choose a new slot.',
        ];
    }
}
