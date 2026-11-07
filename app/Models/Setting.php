<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\PublicContentObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

/**
 * One stored setting. Read through App\Support\Settings, write through
 * App\Actions\Settings\UpdateSettings.
 *
 * @property int $id
 * @property string $key
 * @property mixed $value
 */
#[ObservedBy(PublicContentObserver::class)]
class Setting extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
