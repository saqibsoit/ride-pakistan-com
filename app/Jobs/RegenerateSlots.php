<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Slots\GenerateSlots;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Brings future empty slots in line after a schedule rule changes. Unique,
 * so a burst of rule edits runs the generator once.
 */
class RegenerateSlots implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public function handle(GenerateSlots $generate): void
    {
        $generate->handle();
    }
}
