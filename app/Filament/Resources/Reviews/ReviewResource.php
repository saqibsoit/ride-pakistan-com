<?php

declare(strict_types=1);

namespace App\Filament\Resources\Reviews;

use App\Actions\Reviews\ChangeReviewStatus;
use App\Actions\Reviews\ReplyToReview;
use App\Enums\ReviewStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Filament\Resources\Reviews\Pages\ListReviews;
use App\Models\Review;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Customer reviews (milestone 10d). New ones wait here until an admin
 * approves them; approved reviews appear on the homepage and their product
 * page. Admins can hide (with a reason) and reply publicly. The customer's
 * words are never edited. Staff can read.
 */
class ReviewResource extends Resource
{
    protected static ?string $model = Review::class;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static ?string $navigationLabel = 'Reviews';

    protected static ?int $navigationSort = 60;

    public static function getNavigationBadge(): ?string
    {
        $pending = Review::query()->where('status', ReviewStatus::Pending)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('rating')->label('Stars')->formatStateUsing(fn (int $state): string => $state.' / 5'),
                TextColumn::make('body')->label('Review')->wrap()->limit(200),
                TextColumn::make('display_name')->label('Shown as')->description(fn (Review $record): string => (string) $record->user->email),
                TextColumn::make('product.name')->label('Product')->placeholder('No product (homepage only)'),
                TextColumn::make('slot.starts_at')->label('Session')->dateTime('j M Y', (string) config('app.display_timezone')),
                TextColumn::make('status')->badge(),
                TextColumn::make('reply')->label('Our reply')->limit(80)->placeholder('—')->wrap(),
            ])
            ->filters([
                SelectFilter::make('status')->options(ReviewStatus::class)->default(ReviewStatus::Pending->value),
            ])
            ->recordActions([
                self::approve(),
                self::hide(),
                self::reply(),
            ]);
    }

    public static function approve(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheck)
            ->color('success')
            ->visible(fn (Review $record): bool => self::admin() !== null && $record->status->canTransitionTo(ReviewStatus::Approved))
            ->requiresConfirmation()
            ->modalDescription('It appears on the homepage and its product page with the first name and initial.')
            ->action(fn (Review $record) => self::move($record, ReviewStatus::Approved));
    }

    public static function hide(): Action
    {
        return Action::make('hide')
            ->label('Hide')
            ->icon(Heroicon::OutlinedEyeSlash)
            ->color('danger')
            ->visible(fn (Review $record): bool => self::admin() !== null && $record->status->canTransitionTo(ReviewStatus::Hidden))
            ->schema([
                Textarea::make('reason')->label('Why hide it?')->required()->maxLength(255)->rows(2)
                    ->helperText('For the team only, e.g. "Personal details in the text" or "Not about a session".'),
            ])
            ->action(fn (Review $record, array $data) => self::move($record, ReviewStatus::Hidden, (string) $data['reason']));
    }

    public static function reply(): Action
    {
        return Action::make('reply')
            ->label(fn (Review $record): string => $record->reply === null ? 'Reply' : 'Edit reply')
            ->icon(Heroicon::OutlinedChatBubbleLeft)
            ->visible(fn (): bool => self::admin() !== null)
            ->fillForm(fn (Review $record): array => ['reply' => $record->reply])
            ->schema([
                Textarea::make('reply')->label('Public reply')->maxLength(ReplyToReview::MAX_LENGTH)->rows(4)
                    ->helperText('Shown under the review on the site once it is approved. Leave empty to remove it.'),
            ])
            ->action(function (Review $record, array $data): void {
                $admin = self::admin();

                if ($admin === null) {
                    return;
                }

                app(ReplyToReview::class)->handle($record, isset($data['reply']) ? (string) $data['reply'] : null, $admin);
                Notification::make()->success()->title('Reply saved')->send();
            });
    }

    private static function move(Review $record, ReviewStatus $to, ?string $reason = null): void
    {
        $admin = self::admin();

        if ($admin === null) {
            return;
        }

        try {
            app(ChangeReviewStatus::class)->handle($record, $to, $admin, $reason);
        } catch (InvalidStatusTransition $e) {
            Notification::make()->danger()->title('Not changed')->body($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('Review '.mb_strtolower($to->label()))->send();
    }

    private static function admin(): ?User
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('moderate', Review::class) ? $user : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReviews::route('/'),
        ];
    }
}
