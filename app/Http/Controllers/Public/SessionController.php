<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enums\SettingKey;
use App\Http\Controllers\Controller;
use App\Models\Faq;
use App\Models\Product;
use App\Support\BusinessFacts;
use App\Support\Seo\SchemaFactory;
use App\Support\Seo\Seo;
use App\Support\SessionTimes;
use App\Support\Settings;
use Illuminate\Contracts\View\View;

/**
 * /sessions/{product}: product detail (inspiration 01). The booking widget
 * is a placeholder until milestone 5.
 */
final class SessionController extends Controller
{
    public function __invoke(
        Product $product,
        BusinessFacts $facts,
        SessionTimes $times,
        Settings $settings,
        SchemaFactory $schema,
        Seo $seo,
    ): View {
        abort_unless($product->is_active, 404);

        $faqs = $facts->withAnswers(Faq::query()->published()->where('product_id', $product->id)->ordered()->get());

        $nodes = [$schema->product($product)];

        if ($faqs->isNotEmpty()) {
            $nodes[] = $schema->faqPage($faqs, route('sessions.show', $product));
        }

        return view('public.session', [
            'product' => $product,
            'facts' => $facts,
            'others' => $facts->products()->reject(fn (Product $other): bool => $other->is($product))->values(),
            'faqs' => $faqs,
            'policies' => $settings->policies(SettingKey::BookingPolicies),
            'minAge' => $settings->int(SettingKey::MinRiderAge),
            'weekend' => $times->thisWeekend(),
            'seo' => $seo->make(
                title: $product->meta_title ?: $product->name.' – horse riding in Islamabad',
                description: $product->meta_description ?: $product->short_description,
                breadcrumbs: [
                    ['label' => 'Home', 'url' => route('home')],
                    ['label' => 'Experiences', 'url' => route('experiences')],
                    ['label' => $product->name],
                ],
                schema: $nodes,
                image: $product->cover_image ?? 'ride.jpg',
                imageAlt: $product->cover_image_alt,
                ogType: 'product',
            ),
        ]);
    }
}
