<?php

declare(strict_types=1);

namespace App\Http\Requests\Team;

use App\Actions\Booking\WriteSessionNote;
use App\Models\Booking;
use Illuminate\Foundation\Http\FormRequest;

/** Run sheet: the trainer's note for one rider's session. Empty clears it. */
final class SessionNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $booking = $this->route('booking');

        return $booking instanceof Booking && $this->user()?->can('writeNote', $booking) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'session_note' => ['nullable', 'string', 'max:'.WriteSessionNote::MAX_LENGTH],
        ];
    }
}
