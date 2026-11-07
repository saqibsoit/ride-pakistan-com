<?php

declare(strict_types=1);

namespace App\Filament\Resources\Posts\Schemas;

use App\Enums\SettingKey;
use App\Filament\Support\ContentFields;
use App\Support\Settings;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PostForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Guide')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')->required()->maxLength(150),
                        TextInput::make('slug')->required()->maxLength(150)->alphaDash()->unique(ignoreRecord: true),
                        Textarea::make('excerpt')
                            ->label('Short answer')
                            ->rows(3)
                            ->maxLength(300)
                            ->helperText('Shown under the title and in search results: answer the question in 2–3 sentences.')
                            ->columnSpanFull(),
                        TextInput::make('author_name')->label('Author')->required()->maxLength(120)
                            ->default(fn (): string => app(Settings::class)->string(SettingKey::TrainerName)),
                        DateTimePicker::make('published_at')
                            ->label('Publish at')
                            ->timezone((string) config('app.display_timezone'))
                            ->helperText('Leave empty to keep as a draft.'),
                    ]),
                Section::make('Media and SEO')
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        ContentFields::image('cover_image', 'Cover photo'),
                        TextInput::make('cover_image_alt')->label('Cover alt text')->maxLength(200),
                        TextInput::make('meta_title')->maxLength(60),
                        Textarea::make('meta_description')->rows(2)->maxLength(160),
                    ]),
                ContentFields::blocks(),
            ]);
    }
}
