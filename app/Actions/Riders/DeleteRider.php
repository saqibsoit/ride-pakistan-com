<?php

declare(strict_types=1);

namespace App\Actions\Riders;

use App\Exceptions\AccountActionBlocked;
use App\Models\Rider;
use Illuminate\Support\Facades\DB;

/** Removes a rider who was never booked or ordered for (logged). */
final class DeleteRider
{
    /**
     * @throws AccountActionBlocked
     */
    public function handle(Rider $rider): void
    {
        DB::transaction(function () use ($rider): void {
            $rider = Rider::query()->lockForUpdate()->findOrFail($rider->id);

            if ($rider->isInUse()) {
                throw new AccountActionBlocked([$rider->name.' has bookings or orders, so they stay on your account.']);
            }

            $rider->delete();
        });
    }
}
