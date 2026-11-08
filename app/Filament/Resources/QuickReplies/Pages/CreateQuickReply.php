<?php

declare(strict_types=1);

namespace App\Filament\Resources\QuickReplies\Pages;

use App\Filament\Resources\QuickReplies\QuickReplyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateQuickReply extends CreateRecord
{
    protected static string $resource = QuickReplyResource::class;
}
