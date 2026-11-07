<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use App\Models\Page;
use App\Support\BusinessFacts;
use App\Support\Seo\SchemaFactory;
use App\Support\Seo\Seo;
use Illuminate\Contracts\View\View;

final class FaqController extends Controller
{
    public function __invoke(BusinessFacts $facts, SchemaFactory $schema, Seo $seo): View
    {
        $faqs = $facts->withAnswers(Faq::query()->published()->whereNull('product_id')->ordered()->get());

        return view('public.faq', [
            'facts' => $facts,
            'groups' => $faqs->groupBy(fn (Faq $faq): string => $faq->topic ?: 'General'),
            'seo' => $seo->make(
                title: 'Horse riding lessons FAQ',
                description: 'Answers about our weekend horse riding sessions in Islamabad: beginners, what to wear, prices, packages, booking, cancellations and location.',
                page: Page::query()->firstWhere('slug', 'faq'),
                breadcrumbs: [
                    ['label' => 'Home', 'url' => route('home')],
                    ['label' => 'FAQ'],
                ],
                schema: $faqs->isEmpty() ? [] : [$schema->faqPage($faqs, route('faq'))],
                image: 'graze.jpg',
            ),
        ]);
    }
}
