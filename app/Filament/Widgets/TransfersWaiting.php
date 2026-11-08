<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\BankTransferStatus;
use App\Filament\Pages\VerifyTransfers;
use App\Models\BankTransfer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Dashboard: bank transfers waiting for review and how long the oldest has waited. */
class TransfersWaiting extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('review', BankTransfer::class);
    }

    /** @return array{count: int, oldest: CarbonImmutable|null} */
    public static function summary(): array
    {
        $waiting = BankTransfer::query()
            ->where('status', BankTransferStatus::Submitted)
            ->whereHas('order', fn ($q) => $q->where('status', 'awaiting_verification'));

        $oldest = (clone $waiting)->min('submitted_at');

        return [
            'count' => $waiting->count(),
            'oldest' => $oldest === null ? null : CarbonImmutable::parse((string) $oldest, 'UTC'),
        ];
    }

    protected function getStats(): array
    {
        ['count' => $count, 'oldest' => $oldest] = self::summary();

        return [
            Stat::make('Transfers to verify', (string) $count)
                ->icon(Heroicon::OutlinedBuildingLibrary)
                ->color($count > 0 ? 'warning' : 'gray')
                ->url(VerifyTransfers::getUrl()),
            Stat::make('Oldest waiting', $oldest === null ? '—' : $oldest->diffForHumans(syntax: CarbonImmutable::DIFF_ABSOLUTE, parts: 2))
                ->icon(Heroicon::OutlinedClock)
                ->description($oldest === null ? 'Nothing waiting' : 'since upload')
                ->url(VerifyTransfers::getUrl()),
        ];
    }
}
