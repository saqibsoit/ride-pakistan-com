<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Waitlist\ProcessWaitlists;
use Illuminate\Console\Command;

final class ProcessWaitlistsCommand extends Command
{
    protected $signature = 'waitlist:process';

    protected $description = 'Offer freed seats to the next person on each waitlist, and move lapsed offers on';

    public function handle(ProcessWaitlists $process): int
    {
        $count = $process->handle();

        $this->info('Sent '.$count.' waitlist '.($count === 1 ? 'offer' : 'offers').'.');

        return self::SUCCESS;
    }
}
