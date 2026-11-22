<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BalanceDue;
use App\Enums\EventPaymentMode;
use App\Enums\EventQuestionScope;
use App\Enums\RideEventStatus;
use App\Enums\SlotStatus;
use App\Models\Concerns\LogsContentActivity;
use App\Observers\PublicContentObserver;
use App\Support\Events\EventMoney;
use Carbon\CarbonImmutable;
use Database\Factories\RideEventFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bookable event built in the admin panel (decided 2026-11-22): a camp,
 * workshop, trip or ride. Its occurrences are slots with ride_event_id
 * set, so seats, holds, orders and fulfilment are the lesson machinery.
 *
 * The status is not fillable: it changes only through
 * App\Actions\Events\ChangeRideEventStatus. "Sold out" and "past" are
 * worked out from the occurrences, never stored.
 *
 * Content blocks ($blocks) are a sortable list of {type, data}:
 * programme {items: [{time, activity}]}, included {included: [], not_included: []},
 * bring {items: []}, dress_code {text, items: []}, requirements {level, age, fitness},
 * faq {items: [{question, answer}]}, location {text}, photos {images: [{image, alt}]}.
 *
 * Questions ($questions): [{key, label, type, options: [], required, scope}].
 *
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property int|null $ride_event_category_id
 * @property RideEventStatus $status
 * @property string|null $summary
 * @property string|null $description
 * @property string|null $location_name
 * @property string|null $cover_image
 * @property string|null $cover_image_alt
 * @property list<array{image: string, alt: string}>|null $gallery
 * @property string|null $video_url
 * @property list<array<string, mixed>>|null $blocks
 * @property list<array<string, mixed>>|null $questions
 * @property EventPaymentMode $payment_mode
 * @property int|null $deposit_percent
 * @property int $non_refundable_percent
 * @property int $cancellation_cutoff_days
 * @property BalanceDue $balance_due
 * @property int $balance_deadline_hours
 * @property int $min_per_booking
 * @property int|null $max_per_booking
 * @property CarbonImmutable|null $booking_opens_at
 * @property int $booking_closes_hours
 * @property bool $waitlist_enabled
 * @property string|null $acknowledgement_text
 * @property bool $is_featured
 * @property int $sort
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read RideEventCategory|null $category
 * @property-read Collection<int, RideEventTier> $tiers
 * @property-read Collection<int, Slot> $occurrences
 */
#[ObservedBy(PublicContentObserver::class)]
class RideEvent extends Model
{
    /** @use HasFactory<RideEventFactory> */
    use HasFactory, LogsContentActivity;

    /** Most people one booking can take when the event sets no maximum. */
    public const MAX_PER_BOOKING = 20;

    /** @var list<string> */
    protected $fillable = [
        'slug',
        'title',
        'ride_event_category_id',
        'summary',
        'description',
        'location_name',
        'cover_image',
        'cover_image_alt',
        'gallery',
        'video_url',
        'blocks',
        'questions',
        'payment_mode',
        'deposit_percent',
        'non_refundable_percent',
        'cancellation_cutoff_days',
        'balance_due',
        'balance_deadline_hours',
        'min_per_booking',
        'max_per_booking',
        'booking_opens_at',
        'booking_closes_hours',
        'waitlist_enabled',
        'acknowledgement_text',
        'is_featured',
        'sort',
        'meta_title',
        'meta_description',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'payment_mode' => 'full',
        'non_refundable_percent' => 0,
        'cancellation_cutoff_days' => 7,
        'balance_due' => 'on_the_day',
        'balance_deadline_hours' => 0,
        'min_per_booking' => 1,
        'booking_closes_hours' => 24,
        'waitlist_enabled' => false,
        'is_featured' => false,
        'sort' => 0,
    ];

    protected static function booted(): void
    {
        // Lists are stored as plain lists (Filament sends UUID keys); empty as null.
        static::saving(function (RideEvent $event): void {
            foreach (['gallery', 'blocks', 'questions'] as $attribute) {
                $value = $event->getAttribute($attribute);
                $event->setAttribute($attribute, is_array($value) && $value !== [] ? array_values($value) : null);
            }

            if ($event->payment_mode === EventPaymentMode::Full) {
                $event->deposit_percent = null;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsTo<RideEventCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(RideEventCategory::class, 'ride_event_category_id');
    }

    /**
     * @return HasMany<RideEventTier, $this>
     */
    public function tiers(): HasMany
    {
        return $this->hasMany(RideEventTier::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * The event's dates: slots carrying ride_event_id.
     *
     * @return HasMany<Slot, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(Slot::class)->orderBy('starts_at');
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function hasOrders(): bool
    {
        return $this->orders()->exists();
    }

    /**
     * @param  Builder<RideEvent>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', RideEventStatus::Published);
    }

    /**
     * Published with an occurrence that hasn't started and isn't cancelled
     * (the /events list; past events are hidden).
     *
     * @param  Builder<RideEvent>  $query
     */
    public function scopeListed(Builder $query): void
    {
        $query->published()->whereHas('occurrences', fn (Builder $q) => $q
            ->where('status', '!=', SlotStatus::Cancelled)
            ->where('starts_at', '>', now()));
    }

    /**
     * @param  Builder<RideEvent>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderByDesc('is_featured')->orderBy('sort')->orderBy('id');
    }

    public function isPublished(): bool
    {
        return $this->status === RideEventStatus::Published;
    }

    /** Deposit percentage charged at booking, or null for full payment. */
    public function depositPercent(): ?int
    {
        return $this->payment_mode === EventPaymentMode::Deposit && $this->deposit_percent !== null
            ? max(1, min(99, $this->deposit_percent))
            : null;
    }

    public function takesDeposit(): bool
    {
        return $this->depositPercent() !== null;
    }

    /** The lowest tier price (the "From PKR X" box). */
    public function fromPriceMinor(): int
    {
        return (int) ($this->tiers->min('price_minor') ?? 0);
    }

    /** The deposit on one person at the lowest price, for "PKR X deposit". */
    public function fromDepositMinor(): ?int
    {
        $percent = $this->depositPercent();

        return $percent === null ? null : EventMoney::share($this->fromPriceMinor(), $percent);
    }

    public function maxPerBooking(): int
    {
        return max($this->min_per_booking, $this->max_per_booking ?? self::MAX_PER_BOOKING);
    }

    /** Bookings for $occurrence close this long before its start. */
    public function bookingClosesAt(Slot $occurrence): CarbonImmutable
    {
        return $occurrence->starts_at->subHours(max(0, $this->booking_closes_hours));
    }

    /** Bookings are open for $occurrence right now (the opens/closes rule). */
    public function isBookingOpen(Slot $occurrence, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        if ($this->booking_opens_at !== null && $now->isBefore($this->booking_opens_at)) {
            return false;
        }

        return $now->isBefore($this->bookingClosesAt($occurrence));
    }

    /** Customer cancellations before this moment may get money back (event setting, default 7 days). */
    public function cancellationCutoff(Slot $occurrence): CarbonImmutable
    {
        return $occurrence->starts_at->subDays(max(0, $this->cancellation_cutoff_days));
    }

    /** The balance can be paid online until this moment. */
    public function balanceOnlineUntil(Slot $occurrence): CarbonImmutable
    {
        return $this->balance_due === BalanceDue::ByDeadline
            ? $occurrence->starts_at->subHours(max(0, $this->balance_deadline_hours))
            : $occurrence->starts_at;
    }

    /**
     * Content blocks of one type, in admin order.
     *
     * @return list<array<string, mixed>>
     */
    public function blocksOf(string $type): array
    {
        $found = [];

        foreach ($this->blocks ?? [] as $block) {
            if (($block['type'] ?? null) === $type && is_array($block['data'] ?? null)) {
                $found[] = $block['data'];
            }
        }

        return $found;
    }

    /**
     * The questions asked for $scope.
     *
     * @return list<array{key: string, label: string, type: string, options: list<string>, required: bool, scope: string}>
     */
    public function questionsFor(EventQuestionScope $scope): array
    {
        $questions = [];

        foreach ($this->questions ?? [] as $question) {
            if (($question['scope'] ?? EventQuestionScope::Attendee->value) !== $scope->value || ! isset($question['key'], $question['label'], $question['type'])) {
                continue;
            }

            $questions[] = [
                'key' => (string) $question['key'],
                'label' => (string) $question['label'],
                'type' => (string) $question['type'],
                'options' => array_values(array_map('strval', (array) ($question['options'] ?? []))),
                'required' => (bool) ($question['required'] ?? false),
                'scope' => $scope->value,
            ];
        }

        return $questions;
    }

    /**
     * Upcoming occurrences that aren't cancelled, with seat counts.
     *
     * @return Collection<int, Slot>
     */
    public function upcomingOccurrences(): Collection
    {
        return $this->occurrences()
            ->withSeatCounts()
            ->where('slots.status', '!=', SlotStatus::Cancelled)
            ->where('slots.starts_at', '>', now())
            ->get();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RideEventStatus::class,
            'payment_mode' => EventPaymentMode::class,
            'balance_due' => BalanceDue::class,
            'gallery' => 'array',
            'blocks' => 'array',
            'questions' => 'array',
            'deposit_percent' => 'integer',
            'non_refundable_percent' => 'integer',
            'cancellation_cutoff_days' => 'integer',
            'balance_deadline_hours' => 'integer',
            'min_per_booking' => 'integer',
            'max_per_booking' => 'integer',
            'booking_opens_at' => 'immutable_datetime',
            'booking_closes_hours' => 'integer',
            'waitlist_enabled' => 'boolean',
            'is_featured' => 'boolean',
            'sort' => 'integer',
            'archived_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
