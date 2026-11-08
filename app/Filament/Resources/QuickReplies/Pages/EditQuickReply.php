<?php

declare(strict_types=1);

namespace App\Filament\Resources\QuickReplies\Pages;

use App\Filament\Resources\QuickReplies\QuickReplyResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditQuickReply extends EditRecord
{
    protected static string $resource = QuickReplyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
