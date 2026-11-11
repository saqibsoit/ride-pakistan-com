<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** What the bulk schedule editor does to the matching dates or slots (prompt 15A). */
enum BulkScheduleOperation: string implements HasLabel
{
    case Create = 'create';
    case Update = 'update';
    case Close = 'close';
    case Cancel = 'cancel';

    public function getLabel(): string
    {
        return match ($this) {
            self::Create => 'Create slots',
            self::Update => 'Change matching slots (time, capacity, trainer)',
            self::Close => 'Close matching slots',
            self::Cancel => 'Cancel matching slots',
        };
    }
}
