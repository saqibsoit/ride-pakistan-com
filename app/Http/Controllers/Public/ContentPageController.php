<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Support\BusinessFacts;
use App\Support\Seo\SchemaFactory;
use App\Support\Seo\Seo;
use Illuminate\Contracts\View\View;

/**
 * CMS pages with fixed URLs: trainer profile, expeditions and the legal
 * pages. The route passes the slug; unpublished pages are 404.
 */
final class ContentPageController extends Controller
{
    public function __invoke(string $slug, BusinessFacts $facts, SchemaFactory $schema, Seo $seo): View
    {
        $page = Page::query()->published()->where('slug', $slug)->firstOrFail();

        $nodes = $slug === 'trainer' ? [$schema->trainer()] : [];

        return view('public.page', [
            'page' => $page,
            'facts' => $facts,
            'isTrainer' => $slug === 'trainer',
            'seo' => $seo->make(
                title: $page->title,
                description: $page->answer,
                page: $page,
                breadcrumbs: [
                    ['label' => 'Home', 'url' => route('home')],
                    ['label' => $page->title],
                ],
                schema: $nodes,
                image: $slug === 'trainer' ? 'tent.jpg' : ($slug === 'expeditions' ? 'group.jpg' : null),
                ogType: $slug === 'trainer' ? 'profile' : 'website',
            ),
        ]);
    }
}
