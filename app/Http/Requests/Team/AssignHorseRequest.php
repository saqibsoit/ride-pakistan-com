<?php

declare(strict_types=1);

namespace App\Http\Requests\Team;

use App\Models\Booking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Run sheet: put a rider on a horse, or take them off (empty horse). */
final class AssignHorseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $booking = $this->route('booking');

        return $booking instanceof Booking && $this->user()?->can('assignHorse', $booking) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'horse_id' => ['nullable', 'integer', Rule::exists('horses', 'id')],
        ];
    }
}
