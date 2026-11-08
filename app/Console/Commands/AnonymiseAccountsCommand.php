<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Account\AnonymiseDeletedAccounts;
use Illuminate\Console\Command;

final class AnonymiseAccountsCommand extends Command
{
    protected $signature = 'accounts:anonymise';

    protected $description = 'Anonymise deleted accounts whose grace and retention periods have passed';

    public function handle(AnonymiseDeletedAccounts $anonymise): int
    {
        $count = $anonymise->handle();

        $this->info('Anonymised '.$count.' '.($count === 1 ? 'account' : 'accounts').'.');

        return self::SUCCESS;
    }
}
