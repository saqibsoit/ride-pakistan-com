<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\BusinessFacts;
use App\Support\Seo\Seo;
use Illuminate\Contracts\View\View;

/**
 * /book: hosts the Livewire booking flow (App\Livewire\Book\BookingFlow).
 * Not response-cached (per-visitor state and CSRF) and not indexed.
 * Every "Book" button points here, optionally with ?product, ?slot and
 * ?riders to start part-way through.
 */
final class BookController extends Controller
{
    public function __invoke(BusinessFacts $facts, Seo $seo): View
    {
        return view('public.book', [
            'facts' => $facts,
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
