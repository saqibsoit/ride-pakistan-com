<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\BookingStatus;
use App\Enums\ReviewStatus;
use App\Exceptions\BookingRejected;
use App\Models\Booking;
use App\Models\Review;
use Illuminate\Support\Facades\DB;

/**
 * A customer reviews a session they attended (milestone 10d), from the
 * signed link in the thank-you email or their past bookings. One review
 * per customer per session: writing again edits it while it still waits
 * for an admin, and is refused once it was approved or hidden. The review
 * belongs to the product that paid for the session (a credit session
 * counts towards its package); a no-charge session has none and shows
 * only on the homepage.
 */
final class SubmitReview
{
    /**
     * @throws BookingRejected
     */
    public function handle(Booking $booking, int $rating, string $body): Review
    {
        $body = trim(preg_replace('/[ \t]+/', ' ', $body) ?? '');

        if ($rating < 1 || $rating > 5 || $body === '') {
            throw BookingRejected::notAllowed('Choose 1 to 5 stars and write a few words.');
        }

        if ($booking->status !== BookingStatus::Attended) {
            throw BookingRejected::notAllowed('You can review a session once the rider has attended it.');
        }

        return DB::transaction(function () use ($booking, $rating, $body): Review {
            $existing = Review::query()
                ->where('user_id', $booking->user_id)
                ->where('slot_id', $booking->slot_id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->status !== ReviewStatus::Pending) {
                throw BookingRejected::notAllowed('Thanks, we already have your review for this session.');
            }

            $review = $existing ?? new Review([
                'user_id' => $booking->user_id,
                'slot_id' => $booking->slot_id,
                'booking_id' => $booking->id,
                'product_id' => self::productFor($booking),
                'display_name' => Review::displayNameFor($booking->user->name),
            ]);

            $review->rating = $rating;
            $review->body = mb_substr($body, 0, Review::BODY_MAX_LENGTH);
            $review->save();

            activity('reviews')
                ->performedOn($review)
                ->causedBy($booking->user)
                ->event($existing === null ? 'submitted' : 'edited')
                ->withProperties(['rating' => $rating, 'slot_id' => $booking->slot_id])
                ->log('Review '.($existing === null ? 'submitted' : 'edited').' for '.$booking->slot->label());

            return $review;
        });
    }

    public static function productFor(Booking $booking): ?int
    {
        $booking->loadMissing(['order', 'creditPack.order']);

        return $booking->order->product_id ?? $booking->creditPack->order->product_id ?? null;
    }
}
