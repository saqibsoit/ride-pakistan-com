<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgeBracket;
use App\Enums\ExperienceLevel;
use Database\Factories\RiderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property AgeBracket|null $age_bracket
 * @property ExperienceLevel|null $experience_level
 * @property string|null $emergency_contact_name
 * @property string|null $emergency_contact_phone
 * @property string|null $notes
 * @property-read User $user
 */
class Rider extends Model
{
    /** @use HasFactory<RiderFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'age_bracket',
        'experience_level',
        'emergency_contact_name',
        'emergency_contact_phone',
        'notes',
    ];

    /** @var list<string> */
    protected $hidden = [
        'emergency_contact_name',
        'emergency_contact_phone',
        'notes',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'age_bracket' => AgeBracket::class,
            'experience_level' => ExperienceLevel::class,
            'emergency_contact_name' => 'encrypted',
            'emergency_contact_phone' => 'encrypted',
            'notes' => 'encrypted',
        ];
    }
}
