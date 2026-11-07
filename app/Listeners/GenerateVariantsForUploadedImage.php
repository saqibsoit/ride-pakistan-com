<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Images\GenerateResponsiveVariants;
use App\Events\ImageUploaded;
use Illuminate\Contracts\Queue\ShouldQueue;

final class GenerateVariantsForUploadedImage implements ShouldQueue
{
    public int $tries = 2;

    public function __construct(private readonly GenerateResponsiveVariants $generate) {}

    public function handle(ImageUploaded $event): void
    {
        $this->generate->handle($event->disk, $event->path);
    }
}
