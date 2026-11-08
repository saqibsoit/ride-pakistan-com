<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Referrals\RewardReferral;
use App\Events\BookingAttended;

/** Referral reward once the referred customer has attended a paid session (milestone 10e). */
final class RewardReferrerOnAttendance
{
    public function __construct(private readonly RewardReferral $reward) {}

    public function handle(BookingAttended $event): void
    {
        $this->reward->handle($event->booking);
    }
}
