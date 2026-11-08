<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AuditsAdminChanges;
use Database\Factories\LeadNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $lead_id
 * @property int|null $author_id
 * @property string $body
 * @property-read Lead $lead
 */
class LeadNote extends Model
{
    /** @use HasFactory<LeadNoteFactory> */
    use AuditsAdminChanges, HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'body',
    ];

    /** Notes are written and deleted in the panel (no Action). */
    public function auditedEvents(): array
    {
        return ['created', 'updated', 'deleted'];
    }

    public function auditLogName(): string
    {
        return 'leads';
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
