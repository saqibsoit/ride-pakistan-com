<?php

declare(strict_types=1);

namespace App\Actions\Progress;

use App\Enums\ProductType;
use App\Models\CreditPack;
use App\Models\Product;
use App\Models\User;

/**
 * The "next steps" card on a rider's progress (milestone 10d), from data,
 * never from fixed product names:
 *
 * - The customer has a usable package: book the next session with credits.
 * - Fewer than half the active skills ticked: the smallest package on sale
 *   (regular sessions build the basics).
 * - Half or more: the largest package on sale, or a private session if no
 *   package is on sale.
 *
 * Returns null when nothing fits (no products on sale).
 */
final class SuggestNextStep
{
    /**
     * @return array{title: string, body: string, label: string, url: string}|null
     */
    public function handle(User $user, int $skillsReached, int $skillsTotal): ?array
    {
        $hasCredits = $user->creditPacks()->get()->contains(fn (CreditPack $pack): bool => $pack->isUsable());

        if ($hasCredits) {
            return [
                'title' => 'Book the next session',
                'body' => 'You have package credits left. Regular sessions are the quickest way to new skills.',
                'label' => 'Book with credits',
                'url' => route('book', ['use' => 'credits']),
            ];
        }

        $packages = Product::query()->active()->where('type', ProductType::Package)->orderBy('sessions_count')->get();
        $advanced = $skillsTotal > 0 && $skillsReached * 2 >= $skillsTotal;

        $product = $advanced
            ? ($packages->last() ?? Product::query()->active()->where('type', ProductType::Private)->first())
            : $packages->first();

        if ($product === null) {
            return null;
        }

        return [
            'title' => $advanced ? 'Keep the progress going' : 'Build the basics',
            'body' => $advanced
                ? 'Most of the basics are there. '.$product->name.' gives time to work on the next skills.'
                : $product->name.' brings regular sessions at a lower price per session, so each one builds on the last.',
            'label' => 'See '.$product->name,
            'url' => route('sessions.show', $product),
        ];
    }
}
