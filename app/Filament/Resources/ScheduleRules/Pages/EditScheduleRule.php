<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScheduleRules\Pages;

use App\Filament\Resources\ScheduleRules\ScheduleRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditScheduleRule extends EditRecord
{
    protected static string $resource = ScheduleRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
