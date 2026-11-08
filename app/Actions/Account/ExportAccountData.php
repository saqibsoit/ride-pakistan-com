<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\AccountCreditEntry;
use App\Models\BankTransfer;
use App\Models\Booking;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPack;
use App\Models\Lead;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Rider;
use App\Models\User;
use App\Models\VoucherOrder;
use Carbon\CarbonInterface;

/**
 * "Download my data" (PLAN §7): everything held about the customer, as
 * plain JSON-ready arrays. Rider contacts and notes are decrypted (it is
 * their data). Internal secrets never leave: no TOTP secrets, no session
 * data, no transfer screenshot paths, no raw payment-provider payloads.
 * Dates are UTC ISO 8601.
 */
final class ExportAccountData
{
    /**
     * @return array<string, mixed>
     */
    public function handle(User $user): array
    {
        activity('account')
            ->performedOn($user)
            ->causedBy($user)
            ->event('exported')
            ->log('Customer downloaded their data');

        return [
            'exported_at' => self::date(now()),
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => self::date($user->email_verified_at),
                'phone' => $user->phone,
                'marketing_consent' => $user->marketing_consent,
                'marketing_consent_changed_at' => self::date($user->marketing_consent_at),
                'created_at' => self::date($user->created_at),
            ],
            'riders' => $user->riders()->orderBy('id')->get()->map(fn (Rider $rider): array => [
                'id' => $rider->id,
                'name' => $rider->name,
                'age_bracket' => $rider->age_bracket?->label(),
                'experience_level' => $rider->experience_level?->label(),
                'emergency_contact_name' => $rider->emergency_contact_name,
                'emergency_contact_phone' => $rider->emergency_contact_phone,
                'notes' => $rider->notes,
            ])->all(),
            'orders' => $user->orders()->with(['product', 'items.slot', 'payments', 'bankTransfers'])->orderBy('id')->get()
                ->map(fn (Order $order): array => [
                    'reference' => $order->reference,
                    'product' => $order->productName(),
                    'status' => $order->status->label(),
                    'payment_method' => $order->payment_method?->value,
                    'amount_minor' => $order->amount_minor,
                    'amount_refunded_minor' => $order->amount_refunded_minor,
                    'currency' => $order->currency,
                    'created_at' => self::date($order->created_at),
                    'paid_at' => self::date($order->paid_at),
                    'items' => $order->items->map(fn (OrderItem $item): array => [
                        'rider_id' => $item->rider_id,
                        'slot_starts_at' => self::date($item->slot?->starts_at),
                        'unit_price_minor' => $item->unit_price_minor,
                    ])->all(),
                    'payments' => $order->payments->map(fn (Payment $payment): array => [
                        'provider' => $payment->provider->value,
                        'amount_minor' => $payment->amount_minor,
                        'currency' => $payment->currency,
                        'status' => $payment->status->value,
                        'created_at' => self::date($payment->created_at),
                    ])->all(),
                    'bank_transfers' => $order->bankTransfers->map(fn (BankTransfer $transfer): array => [
                        'transaction_ref' => $transfer->transaction_ref,
                        'submitted_at' => self::date($transfer->submitted_at),
                        'status' => $transfer->status->value,
                        'verified_at' => self::date($transfer->verified_at),
                        'rejection_reason' => $transfer->rejection_reason,
                        'screenshot' => 'held on file',
                    ])->all(),
                ])->all(),
            'bookings' => $user->bookings()->with(['slot', 'rider'])->orderBy('id')->get()->map(fn (Booking $booking): array => [
                'ticket_code' => $booking->ticket_code,
                'rider' => $booking->rider->name,
                'slot_starts_at' => self::date($booking->slot->starts_at),
                'slot_ends_at' => self::date($booking->slot->ends_at),
                'status' => $booking->status->value,
                'cancelled_at' => self::date($booking->cancelled_at),
            ])->all(),
            'credit_packs' => $user->creditPacks()->with('ledger')->orderBy('id')->get()->map(fn (CreditPack $pack): array => [
                'credits_total' => $pack->credits_total,
                'credits_used' => $pack->credits_used,
                'valid_from' => self::date($pack->valid_from),
                'expires_at' => self::date($pack->expires_at),
                'status' => $pack->status->value,
                'ledger' => $pack->ledger->map(fn (CreditLedgerEntry $entry): array => [
                    'delta' => $entry->delta,
                    'reason' => $entry->reason->value,
                    'note' => $entry->note,
                    'created_at' => self::date($entry->created_at),
                ])->all(),
            ])->all(),
            'gift_vouchers' => $user->voucherOrders()->with('voucher')->orderBy('id')->get()->map(fn (VoucherOrder $order): array => [
                'reference' => $order->reference,
                'amount_minor' => $order->amount_minor,
                'currency' => $order->currency,
                'status' => $order->status->value,
                'recipient_name' => $order->recipient_name,
                'recipient_email' => $order->recipient_email,
                'message' => $order->message,
                'send_on' => $order->send_on?->format('Y-m-d'),
                'paid_at' => self::date($order->paid_at),
                'voucher_balance_minor' => $order->voucher?->balance_minor,
                'voucher_expires_at' => self::date($order->voucher?->expires_at),
            ])->all(),
            'referral_code' => $user->referral_code,
            'referred_by_customer' => $user->referredBy()->exists(),
            'friends_referred' => $user->referrals()->count(),
            'account_credit' => $user->accountCredit()->orderBy('id')->get()->map(fn (AccountCreditEntry $entry): array => [
                'delta_minor' => $entry->delta_minor,
                'reason' => $entry->reason->value,
                'created_at' => self::date($entry->created_at),
            ])->all(),
            'enquiries' => Lead::query()->where('user_id', $user->id)->orderBy('id')->get()->map(fn (Lead $lead): array => [
                'name' => $lead->name,
                'phone' => $lead->phone,
                'email' => $lead->email,
                'instagram_handle' => $lead->instagram_handle,
                'message' => $lead->message,
                'created_at' => self::date($lead->created_at),
            ])->all(),
        ];
    }

    private static function date(?CarbonInterface $date): ?string
    {
        return $date?->copy()->utc()->toIso8601String();
    }
}
