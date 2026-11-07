<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScheduleRules\Pages;

use App\Filament\Resources\ScheduleRules\ScheduleRuleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateScheduleRule extends CreateRecord
{
    protected static string $resource = ScheduleRuleResource::class;
}
