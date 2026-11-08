<?php

declare(strict_types=1);

namespace App\Filament\Resources\Skills\Pages;

use App\Filament\Resources\Skills\SkillResource;
use App\Models\Skill;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSkill extends EditRecord
{
    protected static string $resource = SkillResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->visible(fn (Skill $record): bool => auth()->user()?->can('delete', $record) === true),
        ];
    }
}
