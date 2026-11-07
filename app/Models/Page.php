<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\LogsContentActivity;
use App\Observers\PublicContentObserver;
use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A CMS page. Content pages (trainer, legal, expeditions) render their body
 * blocks; templated pages (home, prices ...) use the record for SEO overrides
 * and the opening answer block.
 *
 * Body blocks: list of ['type' => 'text'|'image'|'callout'|'quote', 'data' => [...]].
 *
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property string|null $og_image
 * @property string|null $answer
 * @property list<array{type: string, data: array<string, mixed>}>|null $body
 * @property bool $is_published
 * @property bool $is_indexable
 * @property Carbon|null $updated_at
 */
#[ObservedBy(PublicContentObserver::class)]
class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory, LogsContentActivity;

    /** @var list<string> */
    protected $fillable = [
        'slug',
        'title',
        'meta_title',
        'meta_description',
        'og_image',
        'answer',
        'body',
        'is_published',
        'is_indexable',
    ];

    /**
     * @return HasMany<Faq, $this>
     */
    public function faqs(): HasMany
    {
        return $this->hasMany(Faq::class);
    }

    /**
     * @param  Builder<Page>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true);
    }

    /** Published and allowed in search results and the sitemap. */
    public function isIndexable(): bool
    {
        return $this->is_published && $this->is_indexable;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'body' => 'array',
            'is_published' => 'boolean',
            'is_indexable' => 'boolean',
        ];
    }
}
