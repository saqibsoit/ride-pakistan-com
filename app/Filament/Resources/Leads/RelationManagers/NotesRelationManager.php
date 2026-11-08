<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\RelationManagers;

use App\Actions\Leads\UpdateLeadPipeline;
use App\Models\Lead;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** A lead's notes, newest first. Added through UpdateLeadPipeline. */
class NotesRelationManager extends RelationManager
{
    protected static string $relationship = 'notes';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $tz = (string) config('app.display_timezone');

        return $table
            ->recordTitleAttribute('body')
            ->modifyQueryUsing(fn ($query) => $query->with('author'))
            ->columns([
                TextColumn::make('body')->label('Note')->wrap(),
                TextColumn::make('author.name')->label('By')->placeholder('—'),
                TextColumn::make('created_at')->label('When')->dateTime('j M Y H:i', timezone: $tz),
            ])
            ->headerActions([
                Action::make('addNote')
                    ->label('Add note')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->schema([
                        Textarea::make('body')->label('Note')->required()->rows(4)->maxLength(5000),
                    ])
                    ->action(function (array $data): void {
                        $user = auth()->user();
                        $lead = $this->getOwnerRecord();

                        if ($user instanceof User && $lead instanceof Lead && $user->can('update', $lead)) {
                            app(UpdateLeadPipeline::class)->addNote($lead, (string) $data['body'], $user);
                        }
                    }),
            ])
            ->recordActions([
                DeleteAction::make(),
            ]);
    }
}
