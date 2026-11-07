<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something shown on public pages changed (content, products, settings,
 * schedule). Clears the full-page cache and regenerates the sitemap.
 */
final class PublicContentChanged
{
    use Dispatchable;
}
