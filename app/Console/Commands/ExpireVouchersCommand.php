<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Vouchers\ExpireVouchers;
use Illuminate\Console\Command;

final class ExpireVouchersCommand extends Command
{
    protected $signature = 'vouchers:expire';

    protected $description = 'Mark gift vouchers past their expiry as expired and write off what was left';

    public function handle(ExpireVouchers $expire): int
    {
        $count = $expire->handle();

        $this->info('Expired '.$count.' gift '.($count === 1 ? 'voucher' : 'vouchers').'.');

        return self::SUCCESS;
    }
}
