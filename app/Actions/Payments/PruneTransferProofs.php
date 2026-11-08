<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\BankTransferStatus;
use App\Enums\SettingKey;
use App\Models\BankTransfer;
use App\Support\Settings;
use App\Support\TransferProofs;
use Carbon\CarbonImmutable;

/**
 * Daily (transfers:prune-proofs). Deletes the screenshot or PDF of every
 * transfer reviewed (approved or rejected) more than
 * privacy.transfer_proof_retention_days [90] ago. Only the file goes: the
 * transfer record (reference, transaction ID, verifier, time), the payment
 * record and the audit log are kept permanently for accounting. Proofs
 * still waiting for review are never touched.
 */
final class PruneTransferProofs
{
    public function __construct(
        private readonly TransferProofs $proofs,
        private readonly Settings $settings,
    ) {}

    /** @return int proofs deleted */
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $days = max(1, $this->settings->int(SettingKey::TransferProofRetentionDays) ?? 90);
        $deleted = 0;

        BankTransfer::query()
            ->whereIn('status', [BankTransferStatus::Approved, BankTransferStatus::Rejected])
            ->whereNotNull('verified_at')
            ->where('verified_at', '<=', $now->subDays($days))
            ->whereNotNull('screenshot_path')
            ->orderBy('id')
            ->each(function (BankTransfer $transfer) use ($now, $days, &$deleted): void {
                $this->proofs->delete($transfer->screenshot_path);

                $transfer->screenshot_path = null;
                $transfer->proof_deleted_at = $now;
                $transfer->save();

                activity('payments')
                    ->performedOn($transfer)
                    ->event('transfer_proof_deleted')
                    ->withProperties(['order_id' => $transfer->order_id, 'retention_days' => $days])
                    ->log('Transfer proof deleted after the retention period');

                $deleted++;
            });

        return $deleted;
    }
}
