<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Review;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Review form (milestone 10d). The signed URL authorises it; "website" is
 * a honeypot that real visitors never see or fill in.
 */
final class SubmitReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'body' => ['required', 'string', 'min:3', 'max:'.Review::BODY_MAX_LENGTH],
            'website' => ['nullable', 'string', 'max:200'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'rating.*' => 'Choose from 1 to 5 stars.',
            'body.required' => 'Write a few words about the session.',
            'body.min' => 'Write a few words about the session.',
            'body.max' => 'Keep it under '.Review::BODY_MAX_LENGTH.' characters, please.',
        ];
    }

    public function isSpam(): bool
    {
        return trim((string) $this->input('website')) !== '';
    }
}
