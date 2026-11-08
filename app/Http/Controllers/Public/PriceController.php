<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enums\SettingKey;
use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Product;
use App\Support\BusinessFacts;
use App\Support\Money;
use App\Support\Seo\SchemaFactory;
use App\Support\Seo\Seo;
use App\Support\Settings;
use App\Support\Turnstile;
use Illuminate\Contracts\View\View;

/**
 * /prices: lesson options, pricing with packs and validity, riding rules
 * and booking policies (inspiration 02). All from products and settings.
 */
final class PriceController extends Controller
{
    public function __invoke(BusinessFacts $facts, Settings $settings, SchemaFactory $schema, Seo $seo, Turnstile $turnstile): View
    {
        $products = $facts->products();

        return view('public.prices', [
            'facts' => $facts,
            'sessions' => $products->reject(fn (Product $p): bool => $p->isPackage())->values(),
            'packages' => $products->filter(fn (Product $p): bool => $p->isPackage())->values(),
            'ridingRules' => $settings->policies(SettingKey::RidingRules),
            'bookingPolicies' => $settings->policies(SettingKey::BookingPolicies),
            'refundPolicy' => $settings->string(SettingKey::RefundPolicy),
            'turnstileSiteKey' => $turnstile->configured() ? $turnstile->siteKey() : null,
            'seo' => $seo->make(
                title: 'Horse riding lesson prices in Islamabad',
                description: 'Riding lessons in Islamabad from '.$this->from($facts).' per rider. Single and private sessions, 8 and 16-class packages, validity and booking policies.',
                page: Page::query()->firstWhere('slug', 'prices'),
                breadcrumbs: [
                    ['label' => 'Home', 'url' => route('home')],
                    ['label' => 'Prices'],
                ],
                schema: array_values($products->map(fn (Product $product) => $schema->product($product))->all()),
                image: 'ride.jpg',
            ),
        ]);
    }

    private function from(BusinessFacts $facts): string
    {
        $from = $facts->fromPriceMinor();

        return $from === null ? 'PKR' : Money::format($from);
    }
}
