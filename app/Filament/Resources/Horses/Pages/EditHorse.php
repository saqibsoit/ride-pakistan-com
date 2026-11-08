<?php

declare(strict_types=1);

namespace App\Filament\Resources\Horses\Pages;

use App\Filament\Resources\Horses\HorseResource;
use App\Filament\Resources\Horses\Tables\HorsesTable;
use Filament\Resources\Pages\EditRecord;

class EditHorse extends EditRecord
{
    protected static string $resource = HorseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HorsesTable::retireAction()->after(fn () => $this->redirect(HorseResource::getUrl('index'))),
            HorsesTable::deleteAction()->after(fn () => $this->redirect(HorseResource::getUrl('index'))),
        ];
    }
}
