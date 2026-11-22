<?php

declare(strict_types=1);

namespace App\Filament\Resources\RideEvents\Pages;

use App\Actions\Events\ApplyLessonClosure;
use App\Enums\RideEventStatus;
use App\Filament\Resources\RideEvents\RideEventResource;
use App\Filament\Resources\RideEvents\Tables\RideEventsTable;
use App\Models\RideEvent;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

class EditRideEvent extends EditRecord
{
    protected static string $resource = RideEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('view')->label('View page')->icon(Heroicon::OutlinedEye)->color('gray')
                ->url(fn (RideEvent $record): string => route('events.show', $record->slug), shouldOpenInNewTab: true)
                ->visible(fn (RideEvent $record): bool => $record->isPublished()),
            RideEventsTable::statusAction(RideEventStatus::Published, 'Publish', Heroicon::OutlinedRocketLaunch, 'It appears on /events, the home page, /experiences and the sitemap, and its dates close the lesson slots they are set to close.'),
            RideEventsTable::statusAction(RideEventStatus::Draft, 'Unpublish', Heroicon::OutlinedEyeSlash, 'It leaves the site; bookings already made stay. Lesson slots it closed reopen.'),
            RideEventsTable::statusAction(RideEventStatus::Archived, 'Archive', Heroicon::OutlinedArchiveBox, 'It leaves the site and this list for good. Bookings, tickets and payments keep working. Lesson slots it closed reopen.'),
            RideEventsTable::duplicateAction(),
            RideEventsTable::deleteAction()->after(fn () => $this->redirect(RideEventResource::getUrl('index'))),
        ];
    }

    /** Lesson bookings on days this event closed, for the admin to move (never cancelled). */
    public function getFooter(): ?View
    {
        $event = $this->getRecord();

        if (! $event instanceof RideEvent) {
            return null;
        }

        $clashes = [];

        foreach ($event->occurrences()->where('starts_at', '>', now())->get() as $occurrence) {
            foreach (ApplyLessonClosure::clashesFor($occurrence) as $booking) {
                $clashes[] = $booking;
            }
        }

        return view('filament.events.lesson-clashes', ['clashes' => $clashes]);
    }
}
