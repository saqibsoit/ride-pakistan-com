<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\BusinessFacts;
use App\Support\Seo\Seo;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * /book holding page until the Livewire booking flow ships (milestone 5).
 * Every "Book" button already points here. Not indexed.
 */
final class BookController extends Controller
{
    public function __invoke(Request $request, BusinessFacts $facts, Seo $seo): View
    {
        $slug = $request->query('product');
        $product = is_string($slug) ? $facts->product($slug) : null;

        return view('public.book', [
            'facts' => $facts,
            'product' => $product,
            'products' => $facts->products(),
            'seo' => $seo->make(
                title: 'Book a riding session',
                description: 'Book a weekend horse riding session in Islamabad with Ride Pakistan.',
                breadcrumbs: [
                    ['label' => 'Home', 'url' => route('home')],
                    ['label' => 'Book'],
                ],
                indexable: false,
            ),
        ]);
    }

    /** Book link for a product, used by cards and price boxes. */
    public static function urlFor(?Product $product = null): string
    {
        return $product === null ? route('book') : route('book', ['product' => $product->slug]);
    }
}
