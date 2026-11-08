<?php

declare(strict_types=1);

namespace App\Http\Requests\Team;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Run sheet: check a rider in or record a no-show (CheckInBooking rules). */
final class CheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        $booking = $this->route('booking');

        return $booking instanceof Booking && $this->user()?->can('checkIn', $booking) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([BookingStatus::Attended->value, BookingStatus::NoShow->value])],
        ];
    }

    public function status(): BookingStatus
    {
        return BookingStatus::from((string) $this->validated('status'));
    }
}
