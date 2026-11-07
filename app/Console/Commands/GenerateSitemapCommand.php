<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Seo\GenerateSitemap;
use Illuminate\Console\Command;

final class GenerateSitemapCommand extends Command
{
    protected $signature = 'sitemap:generate';

    protected $description = 'Write the XML sitemap of all indexable public pages';

    public function handle(GenerateSitemap $generate): int
    {
        $count = count($generate->handle()->getTags());

        $this->components->info("Sitemap written with {$count} URLs to ".config('site.sitemap_path'));

        return self::SUCCESS;
    }
}
