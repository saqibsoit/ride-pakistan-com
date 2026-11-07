<?php

declare(strict_types=1);

namespace App\Filament\Resources\Slots\Pages;

use App\Actions\Slots\GenerateSlots;
use App\Filament\Resources\Slots\SlotResource;
use App\Models\Slot;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListSlots extends ListRecords
{
    protected static string $resource = SlotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('calendar')
                ->label('Week view')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->url(SlotResource::getUrl('calendar')),
            Action::make('generate')
                ->label('Generate slots now')
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (): bool => self::user()?->can('generate', Slot::class) === true)
                ->requiresConfirmation()
                ->modalDescription('Creates missing slots from the schedule rules. Slots with bookings or holds, and closed or cancelled slots, are never changed.')
                ->action(function (GenerateSlots $generate): void {
                    abort_unless(self::user()?->can('generate', Slot::class) === true, 403);

                    $result = $generate->handle();

                    activity('slots')->causedBy(self::user())->event('generated')
                        ->withProperties(['created' => $result->created, 'updated' => $result->updated, 'deleted' => $result->deleted])
                        ->log('Slots generated: '.$result->summary());

                    Notification::make()->success()->title('Slots up to date')->body(ucfirst($result->summary()).'.')->send();
                }),
        ];
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
