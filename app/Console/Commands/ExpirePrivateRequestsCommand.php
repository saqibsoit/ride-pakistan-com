<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\PrivateRequests\ExpirePrivateRequests;
use Illuminate\Console\Command;

final class ExpirePrivateRequestsCommand extends Command
{
    protected $signature = 'private-requests:expire';

    protected $description = 'Expire private session requests nobody answered in time and tell the customers';

    public function handle(ExpirePrivateRequests $expire): int
    {
        $count = $expire->handle();

        $this->info('Expired '.$count.' '.($count === 1 ? 'request' : 'requests').'.');

        return self::SUCCESS;
    }
}
