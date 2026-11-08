<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enums\ProductType;
use App\Enums\SettingKey;
use App\Http\Controllers\Controller;
use App\Models\Faq;
use App\Models\Product;
use App\Support\Availability;
use App\Support\BusinessFacts;
use App\Support\Reviews;
use App\Support\Seo\SchemaFactory;
use App\Support\Seo\Seo;
use App\Support\Settings;
use Illuminate\Contracts\View\View;

/**
 * /sessions/{product}: product detail (inspiration 01) with a booking
 * widget showing the next slots and seats left (plain form into /book).
 */
final class SessionController extends Controller
{
    public function __invoke(
        Product $product,
        BusinessFacts $facts,
        Availability $availability,
        Settings $settings,
        SchemaFactory $schema,
        Seo $seo,
        Reviews $reviews,
    ): View {
        abort_unless($product->isOnSale(), 404);

        $faqs = $facts->withAnswers(Faq::query()->published()->where('product_id', $product->id)->ordered()->get());

        // Approved reviews of this product (milestone 10d); stars in the schema from 5 reviews.
        $productReviews = $reviews->forProduct($product);
        $summary = $reviews->summary($product);

        $nodes = [$schema->product($product, $productReviews, $summary)];

        if ($faqs->isNotEmpty()) {
            $nodes[] = $schema->faqPage($faqs, route('sessions.show', $product));
        }

        return view('public.session', [
            'product' => $product,
            'facts' => $facts,
            'others' => $facts->products()->reject(fn (Product $other): bool => $other->is($product))->values(),
            'faqs' => $faqs,
            'reviews' => $productReviews,
            'reviewSummary' => $summary,
            'policies' => $settings->policies(SettingKey::BookingPolicies),
            'minAge' => $settings->int(SettingKey::MinRiderAge),
            'slots' => $availability->upcoming(8, $product->type === ProductType::Private ? Availability::TYPE_PRIVATE : Availability::TYPE_GROUP),
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
