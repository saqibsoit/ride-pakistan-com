<?php

declare(strict_types=1);

namespace App\Filament\Resources\Horses\Pages;

use App\Filament\Resources\Horses\HorseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateHorse extends CreateRecord
{
    protected static string $resource = HorseResource::class;
}
