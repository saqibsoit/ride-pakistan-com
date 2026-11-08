<?php

declare(strict_types=1);

namespace App\Actions\Seo;

use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\Tags\Url;

/**
 * Writes the XML sitemap: every indexable public page, active product and
 * published guide. Drafts, unpublished/noindex CMS pages, /book and private
 * pages are left out. Runs on content change and nightly.
 */
final class GenerateSitemap
{
    /**
     * Templated public routes, keyed by the CMS slug that can override them
     * (an is_indexable=false page record removes the route from the sitemap).
     */
    public const STATIC_ROUTES = [
        'home' => 'home',
        'experiences' => 'experiences',
        'schedule' => 'schedule',
        'prices' => 'prices',
        'gift-vouchers' => 'gift-vouchers',
        'faq' => 'faq',
        'guides' => 'guides.index',
        'contact' => 'contact',
    ];

    /** CMS-backed routes: listed only when the page is published and indexable. */
    public const PAGE_ROUTES = [
        'trainer' => 'trainer',
        'expeditions' => 'expeditions',
        'privacy' => 'legal.privacy',
        'terms' => 'legal.terms',
        'refund-policy' => 'legal.refund-policy',
    ];

    public function handle(): Sitemap
    {
        $sitemap = Sitemap::create();
        $pages = Page::query()->get()->keyBy('slug');
        $hasGuides = Post::query()->published()->exists();

        foreach (self::STATIC_ROUTES as $slug => $route) {
            $page = $pages->get($slug);

            if ($page !== null && ! $page->is_indexable) {
                continue;
            }

            if ($slug === 'guides' && ! $hasGuides) {
                continue;
            }

            $sitemap->add($this->url(route($route), $page?->updated_at, $slug === 'home' ? 1.0 : 0.8));
        }

        foreach (self::PAGE_ROUTES as $slug => $route) {
            $page = $pages->get($slug);

            if ($page !== null && $page->isIndexable()) {
                $sitemap->add($this->url(route($route), $page->updated_at, 0.6));
            }
        }

        Product::query()->active()->ordered()->get()->each(
            fn (Product $product) => $sitemap->add($this->url(route('sessions.show', $product), $product->updated_at, 0.9)),
        );

        Post::query()->published()->latest('published_at')->get()->each(
            fn (Post $post) => $sitemap->add($this->url(route('guides.show', $post), $post->updated_at, 0.7)),
        );

        $path = (string) config('site.sitemap_path');
        File::ensureDirectoryExists(dirname($path));
        $sitemap->writeToFile($path);

        return $sitemap;
    }

    private function url(string $location, ?\DateTimeInterface $modified, float $priority): Url
    {
        $url = Url::create($location)->setPriority($priority)->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY);

        if ($modified !== null) {
            $url->setLastModificationDate(Carbon::instance($modified));
        }

        return $url;
    }
}
