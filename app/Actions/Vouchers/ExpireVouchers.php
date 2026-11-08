<?php

declare(strict_types=1);

namespace App\Actions\Vouchers;

use App\Enums\VoucherStatus;
use App\Models\Voucher;
use Carbon\CarbonImmutable;

/**
 * vouchers:expire (daily, milestone 10e): active vouchers past their
 * expiry become expired and their balance is written off (ledger
 * "expired"). An admin extension brings the balance back.
 */
final class ExpireVouchers
{
    public function __construct(private readonly VoucherBalance $balance) {}

    /** @return int how many vouchers expired */
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $count = 0;

        Voucher::query()
            ->where('status', VoucherStatus::Active)
            ->where('expires_at', '<=', $now)
            ->orderBy('id')
            ->each(function (Voucher $voucher) use ($now, &$count): void {
                if ($this->balance->expire($voucher, $now)) {
                    $count++;
                }
            });

        return $count;
    }
}
