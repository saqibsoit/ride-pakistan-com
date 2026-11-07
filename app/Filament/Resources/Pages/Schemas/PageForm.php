<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pages\Schemas;

use App\Filament\Support\ContentFields;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Page')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')->required()->maxLength(150),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(150)
                            ->alphaDash()
                            ->unique(ignoreRecord: true)
                            ->helperText('Fixed pages: home, experiences, schedule, prices, faq, guides, contact, trainer, expeditions, privacy, terms, refund-policy.'),
                        Textarea::make('answer')
                            ->label('Opening answer')
                            ->rows(3)
                            ->maxLength(600)
                            ->helperText('2–3 factual sentences shown under the heading (what, where, when, who it is for). Content pages only.')
                            ->columnSpanFull(),
                        Toggle::make('is_published')->label('Published'),
                        Toggle::make('is_indexable')->label('Show in search engines and the sitemap')->default(true),
                    ]),
                Section::make('Search and sharing')
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        TextInput::make('meta_title')->maxLength(60)->helperText('Leave empty to use the page title.'),
                        Textarea::make('meta_description')->rows(2)->maxLength(160),
                        ContentFields::image('og_image', 'Social sharing image'),
                    ]),
                ContentFields::blocks(),
            ]);
    }
}
