<?php

declare(strict_types=1);

namespace App\Support\Seo;

use App\Enums\SettingKey;
use App\Models\Page;
use App\Support\Settings;
use Illuminate\Http\Request;
use Spatie\SchemaOrg\Type;

/**
 * Builds the SEO data for a page with sensible fallbacks:
 * CMS page overrides → values passed by the controller → site defaults.
 *
 * Every page gets Organization + WebSite nodes and, when breadcrumbs are
 * given, a BreadcrumbList; controllers add page-specific nodes.
 */
final class Seo
{
    public function __construct(
        private readonly Settings $settings,
        private readonly SchemaFactory $schema,
        private readonly Request $request,
    ) {}

    /** Whether this environment may be indexed at all (production only). */
    public static function siteIndexable(): bool
    {
        return (bool) config('site.indexable');
    }

    /**
     * @param  string|null  $title  short title; null means the site default title (home)
     * @param  list<array{label: string, url?: string|null}>  $breadcrumbs
     * @param  list<Type>  $schema  extra page nodes
     * @param  string|null  $image  path under public/images, e.g. "ride.jpg"
     */
    public function make(
        ?string $title,
        ?string $description = null,
        ?Page $page = null,
        array $breadcrumbs = [],
        array $schema = [],
        ?string $image = null,
        ?string $imageAlt = null,
        bool $indexable = true,
        string $ogType = 'website',
    ): SeoData {
        $title = $this->filled($page?->meta_title) ?? $title;
        $fullTitle = $title === null
            ? $this->settings->string(SettingKey::DefaultTitle)
            : $title.config('site.title_suffix');

        $description = $this->filled($page?->meta_description)
            ?? $this->filled($description)
            ?? $this->settings->string(SettingKey::DefaultDescription);

        $image = $this->filled($page?->og_image) ?? $image ?? 'hero.jpg';

        if ($page !== null && ! $page->is_indexable) {
            $indexable = false;
        }

        $canonical = $this->request->url();

        $nodes = [$this->schema->organization(), $this->schema->website()];

        if ($breadcrumbs !== []) {
            $nodes[] = $this->schema->breadcrumbs($breadcrumbs, $canonical);
        }

        return new SeoData(
            title: $fullTitle,
            description: $description,
            canonical: $canonical,
            robots: $this->robots($indexable),
            image: str_starts_with($image, 'http') ? $image : asset('images/'.ltrim($image, '/')),
            imageAlt: $imageAlt ?? $this->settings->string(SettingKey::Tagline),
            ogType: $ogType,
            schema: [...$nodes, ...$schema],
        );
    }

    private function robots(bool $indexable): string
    {
        if (! self::siteIndexable()) {
            return 'noindex, nofollow';
        }

        return $indexable ? 'index, follow, max-image-preview:large' : 'noindex, follow';
    }

    private function filled(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
