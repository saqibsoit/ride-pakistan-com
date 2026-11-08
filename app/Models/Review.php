<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReviewStatus;
use App\Observers\PublicContentObserver;
use Carbon\CarbonImmutable;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's review of an attended session (milestone 10d). Written by
 * SubmitReview from the signed link in the thank-you email; public only
 * once an admin approves it (ChangeReviewStatus). Changes clear the page
 * cache, because approved reviews show on the homepage and product pages.
 *
 * @property int $id
 * @property int $user_id
 * @property int $slot_id
 * @property int $booking_id
 * @property int|null $product_id the product that paid for the session (package for credits)
 * @property int $rating 1 to 5
 * @property string $body
 * @property string $display_name first name and last initial, set when written
 * @property ReviewStatus $status
 * @property string|null $hidden_reason
 * @property string|null $reply public answer from the team
 * @property int|null $replied_by
 * @property CarbonImmutable|null $replied_at
 * @property int|null $moderated_by
 * @property CarbonImmutable|null $moderated_at
 * @property CarbonImmutable $created_at
 * @property-read User $user
 * @property-read Slot $slot
 * @property-read Booking $booking
 * @property-read Product|null $product
 */
#[ObservedBy(PublicContentObserver::class)]
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    public const BODY_MAX_LENGTH = 600;

    /** Google shows review stars only from this many reviews; we follow the same line. */
    public const AGGREGATE_MINIMUM = 5;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'slot_id',
        'booking_id',
        'product_id',
        'rating',
        'body',
        'display_name',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Slot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(Slot::class);
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @param  Builder<Review>  $query
     * @return Builder<Review>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', ReviewStatus::Approved);
    }

    /** "Ayesha K." from the account name; "A rider" when there is none. */
    public static function displayNameFor(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return 'A rider';
        }

        $first = mb_substr($parts[0], 0, 40);

        return count($parts) > 1 ? $first.' '.mb_strtoupper(mb_substr((string) end($parts), 0, 1)).'.' : $first;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'status' => ReviewStatus::class,
            'replied_at' => 'immutable_datetime',
            'moderated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
