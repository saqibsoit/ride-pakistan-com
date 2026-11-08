<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Enums\CreditPackStatus;
use App\Enums\CreditReason;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPack;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Daily (credits:expire). Active packs past their expiry become expired
 * and their unused credits are written off in the ledger. Booking checks
 * never rely on this job: they compare expires_at directly.
 */
final class ExpireCreditPacks
{
    /** @return int packs expired */
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $count = 0;

        CreditPack::query()
            ->where('status', CreditPackStatus::Active)
            ->where('expires_at', '<=', $now)
            ->orderBy('id')
            ->each(function (CreditPack $pack) use ($now, &$count): void {
                DB::transaction(function () use ($pack, $now, &$count): void {
                    $locked = CreditPack::query()->whereKey($pack->id)->lockForUpdate()->first();

                    if ($locked === null || $locked->status !== CreditPackStatus::Active || $locked->expires_at->isAfter($now)) {
                        return;
                    }

                    $unused = $locked->creditsRemaining();
                    $locked->status = CreditPackStatus::Expired;
                    $locked->save();

                    if ($unused > 0) {
                        CreditLedgerEntry::query()->create([
                            'credit_pack_id' => $locked->id,
                            'delta' => -$unused,
                            'reason' => CreditReason::Expired,
                            'note' => 'Expired '.$locked->expires_at->toDateString(),
                        ]);
                    }

                    $count++;
                });
            });

        return $count;
    }
}
