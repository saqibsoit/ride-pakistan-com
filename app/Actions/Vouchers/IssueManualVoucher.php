<?php

declare(strict_types=1);

namespace App\Actions\Vouchers;

use App\Actions\Orders\OrderStateMachine;
use App\Events\VoucherIssued;
use App\Exceptions\VoucherRejected;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * An admin issues a voucher without a purchase (milestone 10e): a goodwill
 * gesture, a competition prize, a voucher paid outside the site. Reason
 * required and logged; the voucher has the normal validity and is emailed
 * to the recipient straight away.
 */
final class IssueManualVoucher
{
    public function __construct(
        private readonly GenerateVoucherCode $codes,
        private readonly VoucherRules $rules,
        private readonly VoucherBalance $balance,
    ) {}

    /** @throws VoucherRejected */
    public function handle(User $admin, int $amountMinor, string $recipientName, string $recipientEmail, string $reason, ?string $label = null): Voucher
    {
        if (! $admin->isAdmin()) {
            throw VoucherRejected::notUsable('Only admins can issue vouchers.');
        }

        $reason = OrderStateMachine::cleanReason($reason);
        $recipientEmail = mb_strtolower(trim($recipientEmail));

        if ($reason === null) {
            throw VoucherRejected::notUsable('Give a reason for issuing this voucher.');
        }

        if ($amountMinor <= 0 || $amountMinor > $this->rules->maxAmountMinor() || $amountMinor % Money::MINOR_PER_MAJOR !== 0) {
            throw VoucherRejected::amountOutOfRange(Money::format(Money::MINOR_PER_MAJOR), Money::format($this->rules->maxAmountMinor()));
        }

        if (trim($recipientName) === '' || filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw VoucherRejected::notUsable('Enter the recipient’s name and a valid email address.');
        }

        return DB::transaction(function () use ($admin, $amountMinor, $recipientName, $recipientEmail, $reason, $label): Voucher {
            $label = trim((string) $label);

            $voucher = Voucher::query()->create([
                'code' => $this->codes->handle(),
                'issued_by' => $admin->id,
                'issue_reason' => $reason,
                'label' => $label === '' ? null : mb_substr($label, 0, 120),
                'recipient_name' => mb_substr(trim($recipientName), 0, 120),
                'recipient_email' => $recipientEmail,
                'initial_minor' => $amountMinor,
                'balance_minor' => $amountMinor,
                'currency' => 'PKR',
                'expires_at' => $this->rules->expiresAt(now()->toImmutable()),
            ]);

            $this->balance->opened($voucher, $admin, $reason);

            activity('vouchers')
                ->performedOn($voucher)
                ->causedBy($admin)
                ->event('issued_manually')
                ->withProperties(['amount_minor' => $amountMinor, 'reason' => $reason])
                ->log('Voucher issued by an admin');

            event(new VoucherIssued($voucher));

            return $voucher;
        });
    }
}
