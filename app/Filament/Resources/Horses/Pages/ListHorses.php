<?php

declare(strict_types=1);

namespace App\Filament\Resources\Horses\Pages;

use App\Filament\Resources\Horses\HorseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHorses extends ListRecords
{
    protected static string $resource = HorseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
