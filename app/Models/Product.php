<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductCategory;
use App\Enums\ProductType;
use App\Models\Concerns\LogsContentActivity;
use App\Observers\PublicContentObserver;
use Carbon\CarbonImmutable;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A bookable product (single, private, package) with its public content.
 *
 * @property int $id
 * @property string $slug
 * @property ProductType $type
 * @property ProductCategory $category
 * @property string $name
 * @property int $price_minor
 * @property int $sessions_count
 * @property int|null $validity_days
 * @property bool $is_active
 * @property bool $is_featured
 * @property CarbonImmutable|null $archived_at off sale for good; kept because orders point at it
 * @property int $sort
 * @property string $short_description
 * @property string|null $long_description
 * @property string|null $who_for
 * @property list<string>|null $what_to_bring
 * @property list<string>|null $whats_included
 * @property list<string>|null $good_to_know
 * @property int $duration_minutes
 * @property string|null $group_size
 * @property string|null $cover_image
 * @property string|null $cover_image_alt
 * @property list<array{image: string, alt: string}>|null $gallery
 * @property string|null $video_url
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property Carbon|null $updated_at
 */
#[ObservedBy(PublicContentObserver::class)]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, LogsContentActivity;

    /** @var list<string> */
    protected $fillable = [
        'slug',
        'type',
        'category',
        'name',
        'price_minor',
        'sessions_count',
        'validity_days',
        'is_active',
        'is_featured',
        'sort',
        'short_description',
        'long_description',
        'who_for',
        'what_to_bring',
        'whats_included',
        'good_to_know',
        'duration_minutes',
        'group_size',
        'cover_image',
        'cover_image_alt',
        'gallery',
        'video_url',
        'meta_title',
        'meta_description',
    ];

    /**
     * The type decides how orders fulfil (group seats, a whole private
     * slot, a credit pack), so it is fixed once the product has orders.
     */
    protected static function booted(): void
    {
        // An empty gallery is stored as null and as a plain list otherwise,
        // so saving a form never logs a false "gallery changed".
        static::saving(function (Product $product): void {
            // The form sends UUID-keyed items; read the raw value, not the typed property.
            $gallery = $product->getAttribute('gallery');
            $product->setAttribute('gallery', is_array($gallery) && $gallery !== [] ? array_values($gallery) : null);
        });

        static::updating(function (Product $product): void {
            if ($product->isDirty('type') && $product->orders()->exists()) {
                throw new LogicException('The type of a product with orders cannot change. Archive it and create a new product instead.');
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return HasMany<Faq, $this>
     */
    public function faqs(): HasMany
    {
        return $this->hasMany(Faq::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @param  Builder<Product>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->whereNull('archived_at');
    }

    /**
     * @param  Builder<Product>  $query
     */
    public function scopeNotArchived(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** Bookable and shown on the public site. */
    public function isOnSale(): bool
    {
        return $this->is_active && $this->archived_at === null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function hasOrders(): bool
    {
        return $this->orders()->exists();
    }

    /**
     * @param  Builder<Product>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('id');
    }

    public function isPackage(): bool
    {
        return $this->type === ProductType::Package;
    }

    /** Price of one session, in minor units (rounded down to the paisa). */
    public function perSessionMinor(): int
    {
        return intdiv($this->price_minor, max(1, $this->sessions_count));
    }

    /** "per rider" for sessions, "8 sessions" for packages. */
    public function priceUnit(): string
    {
        return $this->isPackage() ? $this->sessions_count.' sessions' : 'per rider';
    }

    /** Validity in whole weeks (packages only). */
    public function validityWeeks(): ?int
    {
        return $this->validity_days === null ? null : intdiv($this->validity_days, 7);
    }

    /** "2 hours" from duration_minutes. */
    public function durationLabel(): string
    {
        $hours = intdiv($this->duration_minutes, 60);
        $minutes = $this->duration_minutes % 60;

        $parts = [];

        if ($hours > 0) {
            $parts[] = $hours.' '.($hours === 1 ? 'hour' : 'hours');
        }

        if ($minutes > 0) {
            $parts[] = $minutes.' min';
        }

        return implode(' ', $parts);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'category' => ProductCategory::class,
            'price_minor' => 'integer',
            'sessions_count' => 'integer',
            'validity_days' => 'integer',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'archived_at' => 'immutable_datetime',
            'sort' => 'integer',
            'what_to_bring' => 'array',
            'whats_included' => 'array',
            'good_to_know' => 'array',
            'duration_minutes' => 'integer',
            'gallery' => 'array',
        ];
    }
}
