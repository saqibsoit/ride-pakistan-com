<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Actions\Orders\OrderStateMachine;
use App\Enums\ReviewStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Review;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The only way a review's status changes (milestone 10d): admins approve
 * or hide (with a reason). Validated against ReviewStatus, logged
 * ("reviews"), and the page cache is cleared by the model observer.
 */
final class ChangeReviewStatus
{
    /**
     * @throws InvalidStatusTransition|AuthorizationException
     */
    public function handle(Review $review, ReviewStatus $to, User $actor, ?string $reason = null): Review
    {
        if (! $actor->can('moderate', Review::class)) {
            throw new AuthorizationException('Only admins can moderate reviews.');
        }

        $reason = OrderStateMachine::cleanReason($reason);

        if ($to->requiresReason() && $reason === null) {
            throw InvalidStatusTransition::reasonRequired('Review #'.$review->id, $to->value);
        }

        return DB::transaction(function () use ($review, $to, $actor, $reason): Review {
            $locked = Review::query()->whereKey($review->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw InvalidStatusTransition::between('Review #'.$locked->id, $from->value, $to->value);
            }

            $locked->status = $to;
            $locked->hidden_reason = $to === ReviewStatus::Hidden ? mb_substr((string) $reason, 0, 255) : null;
            $locked->moderated_by = $actor->id;
            $locked->moderated_at = now()->toImmutable();
            $locked->save();

            activity('reviews')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('status_changed')
                ->withProperties(array_filter(['from' => $from->value, 'to' => $to->value, 'reason' => $reason], fn (mixed $v): bool => $v !== null))
                ->log(sprintf('Review #%d: %s → %s', $locked->id, $from->label(), $to->label()));

            $review->setRawAttributes($locked->getAttributes(), true);

            return $review;
        });
    }
}
