<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

/**
 * Form fields shared by the content resources.
 */
final class ContentFields
{
    /** Editorial image on the "site" disk (public/images/uploads). Variants are generated on save. */
    public static function image(string $name, string $label = 'Image'): FileUpload
    {
        return FileUpload::make($name)
            ->label($label)
            ->disk('site')
            ->directory('uploads')
            ->visibility('public')
            ->image()
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->maxSize(5120)
            ->imageEditor();
    }

    /** CMS body blocks; the format App\Models\Page::$body documents. */
    public static function blocks(string $name = 'body'): Builder
    {
        return Builder::make($name)
            ->label('Content')
            ->blocks([
                Block::make('text')
                    ->label('Text section')
                    ->schema([
                        TextInput::make('heading')->maxLength(150),
                        MarkdownEditor::make('content')
                            ->label('Text')
                            ->helperText('Markdown. Raw HTML is removed when shown.')
                            ->toolbarButtons([['bold', 'italic', 'link'], ['heading'], ['bulletList', 'orderedList'], ['undo', 'redo']]),
                    ]),
                Block::make('image')
                    ->schema([
                        self::image('image')->required(),
                        TextInput::make('alt')->label('Alt text')->required()->maxLength(200)
                            ->helperText('Describe the photo for people who cannot see it.'),
                        TextInput::make('caption')->maxLength(200),
                    ]),
                Block::make('quote')
                    ->label('Pull quote')
                    ->schema([
                        Textarea::make('text')->required()->rows(2)->maxLength(300),
                    ]),
                Block::make('callout')
                    ->schema([
                        TextInput::make('title')->maxLength(150),
                        MarkdownEditor::make('content')->required(),
                    ]),
                Block::make('cta')
                    ->label('Call to action')
                    ->schema([
                        TextInput::make('label')->required()->maxLength(60),
                        TextInput::make('url')->label('Link')->required()->maxLength(500)
                            ->helperText('A path on this site (/book) or a full https:// address.')
                            ->rule('regex:/^(\/[^\s]*|https:\/\/[^\s]+)$/'),
                        TextInput::make('text')->label('Text above the button')->maxLength(200),
                    ]),
            ])
            ->collapsible()
            ->reorderable()
            ->columnSpanFull();
    }

    /** A plain list of short strings (kit list, inclusions, policies). */
    public static function list(string $name, string $label): Repeater
    {
        return Repeater::make($name)
            ->label($label)
            ->simple(TextInput::make('item')->required()->maxLength(250))
            ->reorderable()
            ->defaultItems(0)
            ->columnSpanFull();
    }
}
