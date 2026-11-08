<?php

declare(strict_types=1);

namespace App\Http\Requests\Team;

use App\Actions\Progress\RecordRiderProgress;
use App\Models\Booking;
use Illuminate\Foundation\Http\FormRequest;

/** Run sheet: skills the rider showed and the customer's progress note (milestone 10d). */
final class RiderProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        $booking = $this->route('booking');

        return $booking instanceof Booking && $this->user()?->can('recordProgress', $booking) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'skills' => ['array', 'max:100'],
            'skills.*' => ['integer', 'exists:skills,id'],
            'progress_note' => ['nullable', 'string', 'max:'.RecordRiderProgress::NOTE_MAX_LENGTH],
        ];
    }

    /** @return list<int> */
    public function skillIds(): array
    {
        $ids = $this->validated('skills', []);

        return array_values(array_map('intval', is_array($ids) ? $ids : []));
    }
}
