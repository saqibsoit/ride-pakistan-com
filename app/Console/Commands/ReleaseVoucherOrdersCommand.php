<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Vouchers\ReleaseExpiredVoucherOrders;
use Illuminate\Console\Command;

final class ReleaseVoucherOrdersCommand extends Command
{
    protected $signature = 'vouchers:release';

    protected $description = 'Close unpaid gift voucher purchases past their deadline (uploaded transfers are kept)';

    public function handle(ReleaseExpiredVoucherOrders $release): int
    {
        $count = $release->handle();

        $this->info('Closed '.$count.' gift voucher '.($count === 1 ? 'purchase' : 'purchases').'.');

        return self::SUCCESS;
    }
}
