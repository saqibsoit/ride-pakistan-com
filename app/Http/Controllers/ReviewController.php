<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Reviews\SubmitReview;
use App\Enums\BookingStatus;
use App\Enums\SettingKey;
use App\Exceptions\BookingRejected;
use App\Http\Requests\SubmitReviewRequest;
use App\Models\Booking;
use App\Models\Review;
use App\Support\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * /reviews/{ticket} (milestone 10d): the review form behind the signed
 * link from the thank-you email (no sign-in needed; the signature is the
 * permission). Afterwards it thanks the customer and offers the Google
 * review link when contact.google_review_url is set.
 */
final class ReviewController extends Controller
{
    public function create(Request $request, string $ticket, Settings $settings): View
    {
        $booking = $this->booking($ticket);

        $review = Review::query()->where('user_id', $booking->user_id)->where('slot_id', $booking->slot_id)->first();

        return view('reviews.create', [
            'booking' => $booking,
            'review' => $review,
            'attended' => $booking->status === BookingStatus::Attended,
            'saved' => session('status') === 'review-saved',
            'googleUrl' => $settings->string(SettingKey::GoogleReviewUrl),
            'action' => $request->fullUrl(),
        ]);
    }

    public function store(SubmitReviewRequest $request, string $ticket, SubmitReview $submit): RedirectResponse
    {
        $booking = $this->booking($ticket);

        if (! $request->isSpam()) {
            try {
                $submit->handle($booking, $request->integer('rating'), (string) $request->validated('body'));
            } catch (BookingRejected $e) {
                return back()->withInput()->withErrors(['body' => $e->getMessage()]);
            }
        }

        return redirect()->to($request->fullUrl())->with('status', 'review-saved');
    }

    private function booking(string $ticket): Booking
    {
        return Booking::query()->where('ticket_code', $ticket)->with(['slot', 'rider', 'user'])->firstOrFail();
    }
}
