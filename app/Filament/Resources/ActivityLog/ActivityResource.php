<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivityLog;

use App\Enums\UserRole;
use App\Filament\Resources\ActivityLog\Pages\ListActivities;
use App\Filament\Resources\ActivityLog\Pages\ViewActivity;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

/**
 * The audit trail (PLAN §7): admin actions, content edits, order and slot
 * transitions. Read-only; ActivityPolicy forbids changes.
 */
class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static ?string $slug = 'activity-log';

    protected static ?string $modelLabel = 'activity';

    protected static ?string $pluralModelLabel = 'activity log';

    protected static ?string $navigationLabel = 'Activity log';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 110;

    public static function table(Table $table): Table
    {
        $tz = (string) config('app.display_timezone');

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('causer'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('j M Y H:i:s', timezone: $tz)->sortable(),
                TextColumn::make('log_name')->label('Area')->badge(),
                TextColumn::make('event')->placeholder('—'),
                TextColumn::make('description')->wrap()->searchable(),
                TextColumn::make('subject_type')->label('Record')
                    ->formatStateUsing(fn (Activity $record): string => self::subject($record))
                    ->placeholder('—'),
                TextColumn::make('causer.name')->label('By')->placeholder('System'),
            ])
            ->filters([
                SelectFilter::make('log_name')->label('Area')
                    ->options(fn (): array => DB::table('activity_log')->distinct()->orderBy('log_name')->pluck('log_name', 'log_name')->filter()->all()),
                SelectFilter::make('event')
                    ->options(fn (): array => DB::table('activity_log')->distinct()->orderBy('event')->pluck('event', 'event')->filter()->all()),
                SelectFilter::make('causer_id')->label('By')
                    ->options(fn (): array => User::query()
                        ->whereIn('role', [UserRole::Admin->value, UserRole::Staff->value])
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $tz = (string) config('app.display_timezone');

        return $schema->components([
            Section::make()
                ->columns(3)
                ->schema([
                    TextEntry::make('created_at')->label('When')->dateTime('j M Y H:i:s', timezone: $tz),
                    TextEntry::make('log_name')->label('Area')->badge(),
                    TextEntry::make('event')->placeholder('—'),
                    TextEntry::make('description')->columnSpan(2),
                    TextEntry::make('causer.name')->label('By')->placeholder('System'),
                    TextEntry::make('subject_type')->label('Record')
                        ->formatStateUsing(fn (Activity $record): string => self::subject($record))
                        ->placeholder('—'),
                ]),
            Section::make('Details')
                ->schema([
                    KeyValueEntry::make('details')
                        ->hiddenLabel()
                        ->state(fn (Activity $record): array => self::flatten($record->properties?->all() ?? []))
                        ->keyLabel('Field')
                        ->valueLabel('Value'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
            'view' => ViewActivity::route('/{record}'),
        ];
    }

    private static function subject(Activity $activity): string
    {
        if ($activity->subject_type === null) {
            return '';
        }

        return class_basename($activity->subject_type).' #'.$activity->subject_id;
    }

    /**
     * "attributes.price_minor" => "1250000"
     *
     * @param  array<mixed>  $properties
     * @return array<string, string>
     */
    private static function flatten(array $properties, string $prefix = ''): array
    {
        $flat = [];

        foreach ($properties as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $flat += self::flatten($value, $name);

                continue;
            }

            $flat[$name] = match (true) {
                $value === null => '—',
                is_bool($value) => $value ? 'yes' : 'no',
                is_scalar($value) => (string) $value,
                default => (string) json_encode($value),
            };
        }

        return $flat;
    }
}
