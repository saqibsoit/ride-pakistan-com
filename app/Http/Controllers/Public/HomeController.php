<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use App\Models\Page;
use App\Support\Availability;
use App\Support\BusinessFacts;
use App\Support\Reviews;
use App\Support\Seo\SchemaFactory;
use App\Support\Seo\Seo;
use Illuminate\Contracts\View\View;

final class HomeController extends Controller
{
    public function __invoke(BusinessFacts $facts, Availability $availability, SchemaFactory $schema, Seo $seo, Reviews $reviews): View
    {
        $page = Page::query()->firstWhere('slug', 'home');
        $products = $facts->products();

        $faqs = $facts->withAnswers(
            Faq::query()->published()->where('page_id', $page?->id)->ordered()->limit(5)->get(),
        );

        return view('public.home', [
            'facts' => $facts,
            'products' => $products,
            'weekend' => $availability->weekend(),
            'faqs' => $faqs,
            'reviews' => $reviews->latest(6),
            'seo' => $seo->make(
                title: null,
                page: $page,
                schema: [
                    $schema->location($facts->rules(), $products),
                    $schema->trainer(),
                ],
            ),
        ]);
    }
}
