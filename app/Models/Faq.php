<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\LogsContentActivity;
use App\Observers\PublicContentObserver;
use Database\Factories\FaqFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question and answer. Shown on /faq (grouped by topic) and, when linked,
 * on a page or product. Answers are plain text.
 *
 * @property int $id
 * @property string $question
 * @property string $answer
 * @property string|null $topic
 * @property int $sort
 * @property int|null $page_id
 * @property int|null $product_id
 * @property bool $is_published
 */
#[ObservedBy(PublicContentObserver::class)]
class Faq extends Model
{
    /** @use HasFactory<FaqFactory> */
    use HasFactory, LogsContentActivity;

    /** @var list<string> */
    protected $fillable = [
        'question',
        'answer',
        'topic',
        'sort',
        'page_id',
        'product_id',
        'is_published',
    ];

    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @param  Builder<Faq>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true);
    }

    /**
     * @param  Builder<Faq>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'is_published' => 'boolean',
        ];
    }
}
