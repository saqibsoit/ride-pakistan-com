<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QuickReplyCategory;
use App\Models\Concerns\LogsContentActivity;
use Database\Factories\QuickReplyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A reply template for Instagram DMs (milestone 10c). The body holds
 * tokens filled at copy time by RenderQuickReply, so prices, times and
 * links always match the live data.
 *
 * @property int $id
 * @property string|null $key shipped templates only; seeded once
 * @property string $title
 * @property string $body
 * @property QuickReplyCategory $category
 * @property int $sort
 * @property bool $is_active
 */
class QuickReply extends Model
{
    /** @use HasFactory<QuickReplyFactory> */
    use HasFactory, LogsContentActivity;

    /** @var list<string> */
    protected $fillable = [
        'title',
        'body',
        'category',
        'sort',
        'is_active',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'sort' => 0,
        'is_active' => true,
    ];

    /**
     * @param  Builder<QuickReply>  $query
     * @return Builder<QuickReply>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => QuickReplyCategory::class,
            'sort' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
