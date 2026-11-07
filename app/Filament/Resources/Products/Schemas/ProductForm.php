<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\ProductCategory;
use App\Enums\ProductType;
use App\Filament\Support\ContentFields;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Product')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Product and price')
                            ->columns(2)
                            ->schema([
                                TextInput::make('name')->required()->maxLength(120),
                                TextInput::make('slug')->required()->maxLength(120)->alphaDash()->unique(ignoreRecord: true)
                                    ->helperText('The page address: /sessions/{slug}. Changing it breaks old links.'),
                                Select::make('type')->options(ProductType::class)->required()->live(),
                                Select::make('category')->options(ProductCategory::class)->required(),
                                // Stored in paisa; edited in whole rupees.
                                TextInput::make('price_minor')
                                    ->label('Price (PKR)')
                                    ->required()
                                    ->integer()
                                    ->minValue(1)
                                    ->prefix('PKR')
                                    ->formatStateUsing(fn (?int $state): ?int => $state === null ? null : intdiv($state, 100))
                                    ->dehydrateStateUsing(fn (mixed $state): int => (int) $state * 100)
                                    ->helperText('Per rider for sessions; for the whole package for packages.'),
                                TextInput::make('sessions_count')->label('Sessions')->required()->integer()->minValue(1)->default(1),
                                TextInput::make('validity_days')
                                    ->label('Validity (days from confirmation)')
                                    ->integer()
                                    ->minValue(1)
                                    ->required(fn (Get $get): bool => $get('type') === ProductType::Package || $get('type') === ProductType::Package->value)
                                    ->visible(fn (Get $get): bool => $get('type') === ProductType::Package || $get('type') === ProductType::Package->value),
                                TextInput::make('duration_minutes')->label('Session length (minutes)')->required()->integer()->minValue(15)->default(120),
                                TextInput::make('sort')->integer()->minValue(0)->default(0),
                                Toggle::make('is_active')->label('On sale')->default(true),
                                Toggle::make('is_featured')->label('Featured'),
                            ]),
                        Tab::make('Content')
                            ->schema([
                                Textarea::make('short_description')->required()->rows(2)->maxLength(300)
                                    ->helperText('One or two sentences for cards and search results.'),
                                MarkdownEditor::make('long_description')->label('About this session'),
                                Textarea::make('who_for')->label('Who it’s for')->rows(2)->maxLength(500),
                                TextInput::make('group_size')->maxLength(100)->placeholder('Up to 6 riders'),
                                ContentFields::list('what_to_bring', 'What to bring'),
                                ContentFields::list('whats_included', 'Your booking includes'),
                                ContentFields::list('good_to_know', 'Good to know')
                                    ->helperText('Booking policies and the minimum age from Settings are added automatically.'),
                            ]),
                        Tab::make('Media and SEO')
                            ->columns(2)
                            ->schema([
                                ContentFields::image('cover_image', 'Cover photo'),
                                TextInput::make('cover_image_alt')->label('Cover alt text')->maxLength(200),
                                TextInput::make('video_url')->label('Video link')->url()->maxLength(255),
                                TextInput::make('meta_title')->maxLength(60),
                                Textarea::make('meta_description')->rows(2)->maxLength(160),
                            ]),
                    ]),
            ]);
    }
}
