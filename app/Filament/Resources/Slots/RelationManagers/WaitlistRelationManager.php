<?php

declare(strict_types=1);

namespace App\Filament\Resources\Slots\RelationManagers;

use App\Actions\Waitlist\LeaveWaitlist;
use App\Actions\Waitlist\OfferWaitlistSeats;
use App\Exceptions\BookingRejected;
use App\Models\Slot;
use App\Models\User;
use App\Models\WaitlistEntry;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The slot's waitlist (milestone 10d), first in line first. Staff and
 * admins see it; admins can send offers now (the same rules as the
 * every-minute run) and remove someone with a reason.
 */
class WaitlistRelationManager extends RelationManager
{
    protected static string $relationship = 'waitlistEntries';

    protected static ?string $title = 'Waitlist';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('viewAny', WaitlistEntry::class) === true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('id')
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('user.name')->label('Customer')->placeholder('—')->description(fn (WaitlistEntry $record): string => (string) $record->user->email),
                TextColumn::make('seats')->label('Riders'),
                TextColumn::make('status')->badge(),
                TextColumn::make('offer_expires_at')->label('Offer held until')->dateTime('D j M, H:i', (string) config('app.display_timezone'))->placeholder('—'),
                TextColumn::make('closed_reason')->label('Note')->placeholder('—')->wrap(),
                TextColumn::make('created_at')->label('Joined')->dateTime('j M, H:i', (string) config('app.display_timezone')),
            ])
            ->headerActions([$this->offerNow()])
            ->recordActions([$this->remove()])
            ->emptyStateHeading('Nobody is waiting')
            ->emptyStateDescription('Customers can join the waitlist on the schedule when this session is full.');
    }

    private function offerNow(): Action
    {
        return Action::make('offer_now')
            ->label('Offer free seats now')
            ->icon(Heroicon::OutlinedEnvelope)
            ->visible(fn (): bool => self::admin() !== null)
            ->requiresConfirmation()
            ->modalDescription('Emails the first people in line whose group fits the free seats and holds the seats for them. This also runs by itself every minute.')
            ->action(function (): void {
                /** @var Slot $slot */
                $slot = $this->getOwnerRecord();
                $made = app(OfferWaitlistSeats::class)->handle($slot->id, self::admin());

                Notification::make()->success()->title($made === 0 ? 'No offers to make' : $made.' '.($made === 1 ? 'offer' : 'offers').' sent')->send();
            });
    }

    private function remove(): Action
    {
        return Action::make('remove')
            ->label('Remove')
            ->icon(Heroicon::OutlinedXMark)
            ->color('danger')
            ->visible(fn (WaitlistEntry $record): bool => self::admin() !== null && $record->status->isActive())
            ->schema([
                Textarea::make('reason')->label('Reason')->required()->maxLength(255)->rows(2),
            ])
            ->action(function (WaitlistEntry $record, array $data): void {
                $admin = self::admin();

                if ($admin === null) {
                    return;
                }

                try {
                    app(LeaveWaitlist::class)->handle($record, $admin, (string) $data['reason']);
                } catch (BookingRejected $e) {
                    Notification::make()->danger()->title('Not removed')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Removed from the waitlist')->send();
            });
    }

    private static function admin(): ?User
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('manage', WaitlistEntry::class) ? $user : null;
    }
}
