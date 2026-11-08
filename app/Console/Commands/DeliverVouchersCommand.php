<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Vouchers\DeliverVoucher;
use Illuminate\Console\Command;

final class DeliverVouchersCommand extends Command
{
    protected $signature = 'vouchers:deliver';

    protected $description = 'Email gift vouchers whose send date has come (09:00 Pakistan time), each once';

    public function handle(DeliverVoucher $deliver): int
    {
        $count = $deliver->due();

        $this->info('Sent '.$count.' gift '.($count === 1 ? 'voucher' : 'vouchers').'.');

        return self::SUCCESS;
    }
}
