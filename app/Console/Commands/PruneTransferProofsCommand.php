<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Payments\PruneTransferProofs;
use Illuminate\Console\Command;

final class PruneTransferProofsCommand extends Command
{
    protected $signature = 'transfers:prune-proofs';

    protected $description = 'Delete bank transfer screenshots reviewed longer ago than the retention period (records are kept)';

    public function handle(PruneTransferProofs $prune): int
    {
        $count = $prune->handle();

        $this->info('Deleted '.$count.' transfer '.($count === 1 ? 'proof' : 'proofs').'.');

        return self::SUCCESS;
    }
}
