<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** File type of an admin export (milestone 10b). */
enum ExportFormat: string implements HasLabel
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';

    public function getLabel(): string
    {
        return match ($this) {
            self::Csv => 'CSV',
            self::Xlsx => 'Excel (.xlsx)',
        };
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv; charset=UTF-8',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }
}
