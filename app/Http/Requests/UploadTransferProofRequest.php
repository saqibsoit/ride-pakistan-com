<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\TransferProofs;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Proof of a bank transfer (PLAN §7 uploads): one screenshot, photo or
 * PDF up to 5 MB. "mimes" checks the extension against the type sniffed
 * from the content, "mimetypes" the sniffed type itself, so a renamed or
 * disguised file fails. TransferProofs then decodes and re-encodes images.
 */
final class UploadTransferProofRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access to the order: EnsureOrderAccess on the route (owner or guest grant)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'proof' => [
                'required',
                'file',
                'max:'.TransferProofs::MAX_KILOBYTES,
                'mimes:jpg,jpeg,png,webp,pdf',
                'mimetypes:image/jpeg,image/png,image/webp,application/pdf',
            ],
            'transaction_ref' => ['nullable', 'string', 'max:100', 'regex:/^[\pL\pN \-\/._#:]*$/u'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'proof.required' => 'Choose the screenshot or PDF of your transfer.',
            'proof.max' => 'The file is larger than 5 MB. Take a screenshot instead, or upload a smaller file.',
            'proof.mimes' => 'Upload a JPG, PNG, WebP or PDF file.',
            'proof.mimetypes' => 'Upload a JPG, PNG, WebP or PDF file.',
            'transaction_ref.regex' => 'Use only letters, numbers and - / . _ # : in the transaction ID.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['proof' => 'transfer proof', 'transaction_ref' => 'transaction ID'];
    }
}
