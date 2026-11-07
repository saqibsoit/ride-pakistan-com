<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Slots\GenerateSlots;
use Illuminate\Console\Command;

class GenerateSlotsCommand extends Command
{
    protected $signature = 'slots:generate {--weeks= : Weeks ahead (default: the slot horizon setting)}';

    protected $description = 'Create upcoming slots from the active schedule rules';

    public function handle(GenerateSlots $generate): int
    {
        $weeks = $this->option('weeks');

        if ($weeks !== null && (! is_numeric($weeks) || (int) $weeks < 1)) {
            $this->components->error('--weeks must be a whole number of at least 1.');

            return self::INVALID;
        }

        $result = $generate->handle($weeks === null ? null : (int) $weeks);

        $this->components->info('Slots: '.$result->summary().'.');

        return self::SUCCESS;
    }
}
