<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Actions\Vouchers\VoucherRules;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buying a gift voucher (milestone 10e): a product or a whole-rupee
 * amount, the recipient and an optional message and send date. Range and
 * product checks are repeated in CreateVoucherOrder (settings may change).
 */
final class StoreVoucherOrderRequest extends FormRequest
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
        $rules = app(VoucherRules::class);
        $today = VoucherRules::today();

        return [
            'kind' => ['required', Rule::in(['product', 'amount'])],
            'product_id' => ['exclude_unless:kind,product', 'required', 'integer', Rule::exists(Product::class, 'id')->where('is_active', true)->whereNull('archived_at')],
            'amount_pkr' => ['exclude_unless:kind,amount', 'required', 'integer', 'min:'.intdiv($rules->minAmountMinor(), 100), 'max:'.intdiv($rules->maxAmountMinor(), 100)],
            'recipient_name' => ['required', 'string', 'max:120'],
            'recipient_email' => ['required', 'string', 'email:rfc', 'max:190'],
            'message' => ['nullable', 'string', 'max:'.VoucherRules::MAX_MESSAGE_LENGTH],
            'send' => ['required', Rule::in(['now', 'later'])],
            'send_on' => ['exclude_unless:send,later', 'required', 'date_format:Y-m-d', 'after_or_equal:'.$today->format('Y-m-d'), 'before_or_equal:'.$today->addDays(VoucherRules::MAX_SEND_AHEAD_DAYS)->format('Y-m-d')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.required' => 'Choose a session for the voucher.',
            'product_id.exists' => 'That session is not on sale any more. Choose another.',
            'amount_pkr.required' => 'Enter the voucher amount in rupees.',
            'amount_pkr.min' => 'The smallest voucher is PKR :min.',
            'amount_pkr.max' => 'The largest voucher is PKR :max.',
            'send_on.required' => 'Choose the date to send the voucher.',
            'send_on.after_or_equal' => 'Choose a date from today.',
            'send_on.before_or_equal' => 'Choose a date up to '.VoucherRules::MAX_SEND_AHEAD_DAYS.' days ahead.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'amount_pkr' => 'amount',
            'recipient_name' => 'recipient’s name',
            'recipient_email' => 'recipient’s email',
            'send_on' => 'send date',
        ];
    }

    public function sendOn(): ?CarbonImmutable
    {
        $date = $this->validated('send_on');

        return is_string($date) ? CarbonImmutable::createFromFormat('Y-m-d', $date, (string) config('app.display_timezone')) ?: null : null;
    }
}
