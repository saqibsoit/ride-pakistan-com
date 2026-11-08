<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Models\Review;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The team's public reply under a review (milestone 10d). Admins only; an
 * empty reply removes it. Shown wherever the approved review appears.
 */
final class ReplyToReview
{
    public const MAX_LENGTH = 600;

    /**
     * @throws AuthorizationException
     */
    public function handle(Review $review, ?string $reply, User $actor): Review
    {
        if (! $actor->can('moderate', Review::class)) {
            throw new AuthorizationException('Only admins can reply to reviews.');
        }

        $reply = trim((string) $reply);
        $reply = $reply === '' ? null : mb_substr($reply, 0, self::MAX_LENGTH);

        return DB::transaction(function () use ($review, $reply, $actor): Review {
            $locked = Review::query()->whereKey($review->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->reply === $reply) {
                return $review;
            }

            $locked->reply = $reply;
            $locked->replied_by = $reply === null ? null : $actor->id;
            $locked->replied_at = $reply === null ? null : now()->toImmutable();
            $locked->save();

            activity('reviews')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event($reply === null ? 'reply_removed' : 'replied')
                ->log('Review #'.$locked->id.': reply '.($reply === null ? 'removed' : 'saved'));

            $review->setRawAttributes($locked->getAttributes(), true);

            return $review;
        });
    }
}
