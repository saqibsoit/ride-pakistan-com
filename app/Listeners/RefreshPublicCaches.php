<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PublicContentChanged;
use App\Jobs\RegenerateSitemap;
use App\Support\BusinessFacts;
use App\Support\Settings;
use Illuminate\Contracts\Foundation\Application;
use Spatie\ResponseCache\Facades\ResponseCache;

/**
 * Clears the full-page cache straight away (so visitors never see stale
 * prices), drops memoised settings and facts, and queues a sitemap rebuild
 * once the transaction commits.
 */
final class RefreshPublicCaches
{
    public function __construct(
        private readonly Application $app,
        private readonly Settings $settings,
    ) {}

    public function handle(PublicContentChanged $event): void
    {
        $this->settings->flush();
        $this->app->forgetInstance(BusinessFacts::class);

        ResponseCache::clear();
        RegenerateSitemap::dispatch()->afterCommit();
    }
}
