<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Upload hook for public images. Dispatch after storing a photo (e.g. from a
 * Filament FileUpload) so responsive variants are generated in the background.
 * Never dispatch this for private uploads such as transfer screenshots.
 */
final class ImageUploaded
{
    use Dispatchable;

    public function __construct(
        public readonly string $disk,
        public readonly string $path,
    ) {}
}
