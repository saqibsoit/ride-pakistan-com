<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enums\ProductCategory;
use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Product;
use App\Support\BusinessFacts;
use App\Support\Seo\Seo;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * /experiences: everything bookable, with filter chips (inspiration 03).
 * Chips are plain links (?category=…), so filtering works without JS.
 */
final class ExperienceController extends Controller
{
    public const EXPEDITIONS = 'expeditions';

    public function __invoke(Request $request, BusinessFacts $facts, Seo $seo): View
    {
        $requested = $request->query('category');
        $category = is_string($requested) ? ProductCategory::tryFrom($requested) : null;
        $showExpeditions = $requested === self::EXPEDITIONS;
        $active = $category !== null ? $category->value : ($showExpeditions ? self::EXPEDITIONS : null);

        $products = $showExpeditions
            ? collect()
            : $facts->products()->when($category, fn ($all) => $all->filter(fn (Product $p): bool => $p->category === $category))->values();

        return view('public.experiences', [
            'facts' => $facts,
            'products' => $products,
            'active' => $active,
            'showExpeditions' => $active === null || $showExpeditions,
            'seo' => $seo->make(
                title: 'Horse riding experiences in Islamabad',
                description: 'Every way to ride with us: single and private sessions, 8 and 16-class packages in Islamabad, and expeditions in northern Pakistan. Prices in PKR.',
                page: Page::query()->firstWhere('slug', 'experiences'),
                breadcrumbs: [
                    ['label' => 'Home', 'url' => route('home')],
                    ['label' => 'Experiences'],
                ],
                image: 'ride.jpg',
            ),
        ]);
    }
}
