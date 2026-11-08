<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\ProductCategory;
use App\Enums\ProductType;
use App\Filament\Support\ContentFields;
use App\Models\Product;
use App\Support\Money;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
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
    private const PRICE_HELP = 'Changes apply to new orders only. Existing orders and credit packs keep the price, sessions and validity they were bought with.';

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
                                Select::make('type')->options(ProductType::class)->required()->live()
                                    // Fixed once sold: it decides how existing orders fulfil.
                                    ->disabled(fn (?Product $record): bool => $record?->hasOrders() ?? false)
                                    ->helperText(fn (?Product $record): ?string => $record?->hasOrders() ? 'Fixed: this product has orders. Archive it and create a new one to sell something different.' : null),
                                Select::make('category')->options(ProductCategory::class)->required(),
                                // Stored in paisa; edited in whole rupees.
                                TextInput::make('price_minor')
                                    ->label('Price (PKR)')
                                    ->required()
                                    ->integer()
                                    ->minValue(1)
                                    ->prefix('PKR')
                                    ->live(onBlur: true)
                                    ->formatStateUsing(fn (?int $state): ?int => $state === null ? null : intdiv($state, 100))
                                    ->dehydrateStateUsing(fn (mixed $state): int => (int) $state * 100)
                                    ->helperText(self::PRICE_HELP),
                                TextInput::make('sessions_count')->label('Sessions')->required()->integer()->minValue(1)->default(1)
                                    ->live(onBlur: true),
                                Placeholder::make('per_session')
                                    ->label('Per session (shown on the site)')
                                    ->content(fn (Get $get): string => self::perSession($get('price_minor'), $get('sessions_count')))
                                    ->visible(fn (Get $get): bool => self::isPackage($get('type'))),
                                TextInput::make('validity_days')
                                    ->label('Validity (days from confirmation)')
                                    ->integer()
                                    ->minValue(1)
                                    ->required(fn (Get $get): bool => self::isPackage($get('type')))
                                    ->visible(fn (Get $get): bool => self::isPackage($get('type')))
                                    ->helperText(self::PRICE_HELP),
                                TextInput::make('duration_minutes')->label('Session length (minutes)')->required()->integer()->minValue(15)->default(120),
                                TextInput::make('sort')->integer()->minValue(0)->default(0),
                                Toggle::make('is_active')->label('On sale')->default(true)
                                    ->helperText('Off hides the product from the site and the booking flow. Existing orders are not affected.'),
                                Toggle::make('is_featured')->label('Featured'),
                            ]),
                        Tab::make('Content')
                            ->schema([
                                Textarea::make('short_description')->required()->rows(2)->maxLength(300)
                                    ->helperText('One or two sentences for cards and search results.'),
                                MarkdownEditor::make('long_description')->label('About this session'),
                                Textarea::make('who_for')->label('Who it’s for')->rows(2)->maxLength(500),
                                TextInput::make('group_size')->maxLength(100)->placeholder('Leave empty to use riders per slot'),
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
                                Repeater::make('gallery')
                                    ->label('Gallery')
                                    ->helperText('Up to 8 photos shown on the product page, in this order.')
                                    ->schema([
                                        ContentFields::image('image', 'Photo')->required(),
                                        TextInput::make('alt')->label('Alt text')->required()->maxLength(200)
                                            ->helperText('Describe the photo for people who cannot see it.'),
                                    ])
                                    ->columns(2)
                                    ->maxItems(8)
                                    ->reorderable()
                                    ->defaultItems(0)
                                    ->columnSpanFull(),
                                TextInput::make('meta_title')->maxLength(60),
                                Textarea::make('meta_description')->rows(2)->maxLength(160),
                            ]),
                    ]),
            ]);
    }

    private static function isPackage(mixed $type): bool
    {
        return $type === ProductType::Package || $type === ProductType::Package->value;
    }

    /** "PKR 7,500" from the form's rupee price and session count. */
    private static function perSession(mixed $rupees, mixed $sessions): string
    {
        $rupees = is_numeric($rupees) ? (int) $rupees : 0;
        $sessions = is_numeric($sessions) ? max(1, (int) $sessions) : 1;

        return $rupees > 0 ? Money::format(intdiv($rupees * 100, $sessions)) : '—';
    }
}
