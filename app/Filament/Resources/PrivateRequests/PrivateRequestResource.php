<?php

declare(strict_types=1);

namespace App\Filament\Resources\PrivateRequests;

use App\Enums\PrivateRequestStatus;
use App\Filament\Resources\PrivateRequests\Pages\ListPrivateRequests;
use App\Filament\Resources\PrivateRequests\Pages\ViewPrivateRequest;
use App\Models\PrivateSessionRequest;
use App\Models\Slot;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Private session requests (prompt 15B): the queue of customers asking
 * for their own date and time. Admins approve (private-only slot, held,
 * pay within the window) or decline with a reason; staff read. Open
 * requests first, soonest deadline first.
 */
class PrivateRequestResource extends Resource
{
    protected static ?string $model = PrivateSessionRequest::class;

    protected static string|UnitEnum|null $navigationGroup = 'Bookings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $navigationLabel = 'Private requests';

    protected static ?string $modelLabel = 'private request';

    protected static ?int $navigationSort = 22;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getNavigationBadge(): ?string
    {
        $open = PrivateSessionRequest::query()->where('status', PrivateRequestStatus::Requested)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['user', 'order'])
                ->orderByRaw("case when status = 'requested' then 0 when status = 'approved' then 1 else 2 end")
                ->orderByRaw('coalesce(pay_by, answer_by) asc'))
            ->columns([
                TextColumn::make('reference')->searchable(),
                TextColumn::make('user.email')->label('Customer')->searchable()->description(fn (PrivateSessionRequest $r): string => $r->user->displayName()),
                TextColumn::make('user.whatsapp_phone')->label('WhatsApp')->placeholder('—'),
                TextColumn::make('starts_at')->label('Requested time')->state(fn (PrivateSessionRequest $r): string => $r->label()),
                TextColumn::make('riders')->numeric(),
                TextColumn::make('status')->badge(),
                TextColumn::make('deadline')->label('Deadline')
                    ->state(fn (PrivateSessionRequest $r): ?string => match ($r->status) {
                        PrivateRequestStatus::Requested => 'Answer by '.PrivateSessionRequest::localTime($r->answer_by),
                        PrivateRequestStatus::Approved => 'Pay by '.PrivateSessionRequest::localTime($r->pay_by),
                        default => null,
                    })
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options(PrivateRequestStatus::class),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading('No private session requests yet');
    }

    public static function infolist(Schema $schema): Schema
    {
        $tz = Slot::displayTimezone();

        return $schema->components([
            Section::make('Request')->columns(3)->schema([
                TextEntry::make('reference'),
                TextEntry::make('status')->badge(),
                TextEntry::make('when')->label('Requested time (Pakistan)')->state(fn (PrivateSessionRequest $r): string => $r->label()),
                TextEntry::make('riders'),
                TextEntry::make('answer_by')->label('Answer by')->dateTime('D j M, H:i', $tz),
                TextEntry::make('pay_by')->label('Pay by')->dateTime('D j M, H:i', $tz)->placeholder('—'),
                TextEntry::make('note')->columnSpanFull()->placeholder('No note'),
            ]),
            Section::make('Customer')->columns(3)->schema([
                TextEntry::make('user.name')->label('Name')->placeholder('—'),
                TextEntry::make('user.email')->label('Email'),
                TextEntry::make('user.whatsapp_phone')->label('WhatsApp')->placeholder('—'),
            ]),
            Section::make('Outcome')->columns(3)->schema([
                TextEntry::make('decidedBy.email')->label('Decided by')->placeholder('—'),
                TextEntry::make('decided_at')->label('Decided at')->dateTime('D j M, H:i', $tz)->placeholder('—'),
                TextEntry::make('trainer.name')->label('Trainer')->placeholder('—'),
                TextEntry::make('order.reference')->label('Order')->placeholder('—'),
                TextEntry::make('order.status')->label('Order status')->badge()->placeholder('—'),
                TextEntry::make('decline_reason')->label('Decline reason')->placeholder('—'),
                TextEntry::make('suggested_times')->label('Suggested times')->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrivateRequests::route('/'),
            'view' => ViewPrivateRequest::route('/{record}'),
        ];
    }
}
