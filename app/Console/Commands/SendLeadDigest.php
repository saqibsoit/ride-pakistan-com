<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Leads\SendFollowUpDigest;
use Illuminate\Console\Command;

/** Daily at 09:00 Pakistan time: the follow-up digest to admins and staff (milestone 10c). */
class SendLeadDigest extends Command
{
    protected $signature = 'leads:digest';

    protected $description = 'Email admins and staff the leads due for follow-up today';

    public function handle(SendFollowUpDigest $digest): int
    {
        $sent = $digest->handle();

        $this->info($sent === 0 ? 'Nothing due, or already sent today.' : sprintf('Digest queued for %d team %s.', $sent, $sent === 1 ? 'member' : 'members'));

        return self::SUCCESS;
    }
}
