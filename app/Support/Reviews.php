<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Models\Review;
use Illuminate\Database\Eloquent\Collection;

/**
 * Approved reviews for the public pages (milestone 10d). Only approved
 * reviews are ever read here; pending and hidden ones never count.
 */
final class Reviews
{
    /**
     * @return Collection<int, Review>
     */
    public function latest(int $limit = 6): Collection
    {
        return Review::query()->approved()->latest('id')->limit($limit)->get();
    }

    /**
     * @return Collection<int, Review>
     */
    public function forProduct(Product $product, int $limit = 6): Collection
    {
        return Review::query()->approved()->where('product_id', $product->id)->latest('id')->limit($limit)->get();
    }

    /**
     * Count and average of a product's approved reviews.
     *
     * @return array{count: int, average: float}
     */
    public function summary(Product $product): array
    {
        // One aggregate query (milestone 11).
        $row = Review::query()->approved()->where('product_id', $product->id)
            ->toBase()
            ->selectRaw('count(*) as total, avg(rating) as average')
            ->first();

        $count = (int) ($row->total ?? 0);

        return [
            'count' => $count,
            'average' => $count === 0 ? 0.0 : round((float) ($row->average ?? 0), 1),
        ];
    }
}
