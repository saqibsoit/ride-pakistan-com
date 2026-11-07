<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Seo\GenerateSitemap;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued sitemap rebuild. Unique, so a burst of admin saves runs it once.
 */
final class RegenerateSitemap implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public function handle(GenerateSitemap $generate): void
    {
        $generate->handle();
    }
}
