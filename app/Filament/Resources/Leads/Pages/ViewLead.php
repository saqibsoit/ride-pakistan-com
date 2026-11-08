<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Pages;

use App\Filament\Resources\Leads\LeadActions;
use App\Filament\Resources\Leads\LeadResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;

class ViewLead extends ViewRecord
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            LeadActions::changeStatus(),
            LeadActions::assign(),
            LeadActions::followUp(),
            LeadActions::addNote(),
            LeadActions::convert(),
            DeleteAction::make(),
        ];
    }
}
