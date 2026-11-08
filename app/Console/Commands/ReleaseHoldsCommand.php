<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Booking\ReleaseExpiredHolds;
use Illuminate\Console\Command;

final class ReleaseHoldsCommand extends Command
{
    protected $signature = 'holds:release';

    protected $description = 'Expire unpaid card orders and unproven bank transfer orders whose seat hold ran out, and give the seats back';

    public function handle(ReleaseExpiredHolds $release): int
    {
        $count = $release->handle();

        $this->info('Expired '.$count.' '.($count === 1 ? 'order' : 'orders').'.');

        return self::SUCCESS;
    }
}
