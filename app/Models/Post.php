<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\LogsContentActivity;
use App\Observers\PublicContentObserver;
use Carbon\CarbonImmutable;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A guide. Drafts have no published_at; scheduled posts go live when
 * published_at passes. Body blocks share the Page block format.
 *
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property string|null $excerpt
 * @property list<array{type: string, data: array<string, mixed>}>|null $body
 * @property string|null $cover_image
 * @property string|null $cover_image_alt
 * @property string $author_name
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $updated_at
 */
#[ObservedBy(PublicContentObserver::class)]
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory, LogsContentActivity;

    /** @var list<string> */
    protected $fillable = [
        'slug',
        'title',
        'excerpt',
        'body',
        'cover_image',
        'cover_image_alt',
        'author_name',
        'meta_title',
        'meta_description',
        'published_at',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @param  Builder<Post>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null && $this->published_at->isPast();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'body' => 'array',
            'published_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
