<?php

declare(strict_types=1);

namespace App\Filament\Resources\Faqs\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class FaqForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('question')->required()->maxLength(300)->columnSpanFull(),
                Textarea::make('answer')
                    ->required()
                    ->rows(5)
                    ->maxLength(2000)
                    ->helperText('Plain text. Tokens filled from live data: {schedule}, {location}, {trainer}, {cutoff}, {price:slug}, {per_session:slug}, {validity:slug}.')
                    ->columnSpanFull(),
                TextInput::make('topic')
                    ->maxLength(100)
                    ->datalist(['Getting started', 'Booking and prices', 'On the day'])
                    ->helperText('Groups questions on /faq.'),
                TextInput::make('sort')->numeric()->minValue(0)->default(0),
                Select::make('page_id')->label('Also show on page')->relationship('page', 'title')->searchable()->preload(),
                Select::make('product_id')->label('Show on product (instead of /faq)')->relationship('product', 'name')->preload(),
                Toggle::make('is_published')->label('Published')->default(true),
            ]);
    }
}
