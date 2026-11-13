<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Schemas;

use App\Models\Order;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Spatie\Activitylog\Models\Activity;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $tz = (string) config('app.display_timezone');

        return $schema->components([
            Section::make('Order')
                ->columns(3)
                ->schema([
                    TextEntry::make('reference')->copyable(),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('payment_method')->label('Method')->placeholder('Not chosen'),
                    TextEntry::make('user.name')->label('Customer'),
                    TextEntry::make('user.email')->label('Email')->copyable(),
                    TextEntry::make('user.whatsapp_phone')->label('WhatsApp')->placeholder('Not given yet')->copyable(),
                    // Guest checkout: what was typed for this booking (an existing account's profile isn't changed).
                    TextEntry::make('contact_name')->label('Booked as (guest)')
                        ->visible(fn (Order $record): bool => $record->placed_as_guest),
                    TextEntry::make('contact_whatsapp')->label('WhatsApp for this booking')->copyable()
                        ->visible(fn (Order $record): bool => $record->placed_as_guest && $record->contact_whatsapp !== null),
                    TextEntry::make('product_name')->label('Product (as bought)'),
                    TextEntry::make('amount_minor')->label('Charged (after discounts)')
                        ->formatStateUsing(fn (Order $record): string => Money::format($record->amount_minor, $record->currency)),
                    TextEntry::make('amount_refunded_minor')->label('Refunded')
                        ->formatStateUsing(fn (Order $record): string => Money::format($record->amount_refunded_minor, $record->currency)),
                    TextEntry::make('created_at')->label('Created')->dateTime('j M Y H:i', timezone: $tz),
                    TextEntry::make('expires_at')->label('Hold expires')->dateTime('j M Y H:i', timezone: $tz)->placeholder('—'),
                    TextEntry::make('paid_at')->label('Paid')->dateTime('j M Y H:i', timezone: $tz)->placeholder('—'),
                    TextEntry::make('stripe_checkout_session_id')->label('Stripe session')->placeholder('—'),
                    TextEntry::make('stripe_payment_intent_id')->label('Stripe payment')->placeholder('—')->copyable(),
                    TextEntry::make('meta.needs_review')->label('Needs review')
                        ->color('danger')
                        ->icon(Heroicon::OutlinedExclamationTriangle)
                        ->columnSpanFull()
                        ->visible(fn (Order $record): bool => isset($record->meta['needs_review'])),
                ]),
            // Milestone 10e Part B: subtotal and discount lines.
            Section::make('Discounts')
                ->visible(fn (Order $record): bool => $record->discounts()->exists())
                ->schema([
                    TextEntry::make('subtotal_minor')->label('Subtotal')
                        ->formatStateUsing(fn (Order $record): string => Money::format($record->subtotal_minor, $record->currency)),
                    TextEntry::make('discount_lines')
                        ->label('Discounts')
                        ->state(fn (Order $record): array => $record->discounts()->get()
                            ->map(fn ($line): string => $line->label.': − '.Money::format($line->amount_minor, $record->currency))
                            ->all())
                        ->listWithLineBreaks()
                        ->bulleted(),
                ]),
            Section::make('Status history')
                ->schema([
                    TextEntry::make('history')
                        ->hiddenLabel()
                        ->state(fn (Order $record): array => self::history($record, $tz))
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->placeholder('No status changes yet.'),
                ]),
        ]);
    }

    /**
     * @return list<string>
     */
    private static function history(Order $order, string $tz): array
    {
        $lines = [];

        $activities = Activity::query()
            ->with('causer')
            ->where('log_name', 'orders')
            ->where('subject_type', $order->getMorphClass())
            ->where('subject_id', $order->id)
            ->orderBy('id')
            ->get();

        foreach ($activities as $activity) {
            $reason = $activity->properties?->get('reason');
            $causer = $activity->causer?->getAttribute('name');

            $lines[] = sprintf(
                '%s: %s%s%s',
                $activity->created_at?->setTimezone($tz)->format('j M Y H:i'),
                $activity->description,
                is_string($reason) && $reason !== '' ? ' ('.$reason.')' : '',
                is_string($causer) ? ', by '.$causer : '',
            );
        }

        return $lines;
    }
}
