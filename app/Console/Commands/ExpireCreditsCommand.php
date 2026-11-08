<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Booking\ExpireCreditPacks;
use Illuminate\Console\Command;

final class ExpireCreditsCommand extends Command
{
    protected $signature = 'credits:expire';

    protected $description = 'Mark credit packs past their expiry as expired and write off unused credits';

    public function handle(ExpireCreditPacks $expire): int
    {
        $count = $expire->handle();

        $this->info('Expired '.$count.' credit '.($count === 1 ? 'pack' : 'packs').'.');

        return self::SUCCESS;
    }
}
