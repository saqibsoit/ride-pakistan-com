<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Monitoring\CheckOperationalHealth;
use Illuminate\Console\Command;

final class MonitorOperationsCommand extends Command
{
    protected $signature = 'monitor:operations';

    protected $description = 'Email admins when queued jobs wait over 5 minutes or transfers wait for review over 12 hours';

    public function handle(CheckOperationalHealth $check): int
    {
        foreach ($check->handle() as $problem => $outcome) {
            $this->components->twoColumnDetail($problem, $outcome);
        }

        return self::SUCCESS;
    }
}
