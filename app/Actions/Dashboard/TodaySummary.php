<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use App\Actions\Leads\LeadReport;
use App\Enums\LeadStatus;
use App\Enums\SlotStatus;
use App\Models\CreditPack;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Slot;
use App\Support\OrderRevenue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * The figures on the "Today" dashboard (milestone 10b). Read-only. Days,
 * weeks and months are Pakistan calendar periods converted to UTC.
 *
 * - Sessions: today's and this weekend's slots that aren't cancelled, with
 *   riders booked (confirmed, attended, no-show) against capacity. "This
 *   weekend" is the coming Saturday and Sunday, or the current one on a
 *   Saturday or Sunday.
 * - Follow-ups due: open leads whose follow-up date is today or earlier
 *   (the leads list's "Follow-up due" filter).
 * - New leads this week: received since Monday 00:00.
 * - Revenue: orders paid in the month, minus what was refunded on them
 *   (the lead reports' rule), so a refund lowers the month the order was
 *   paid in.
 * - Packs expiring: active packs with credits left that expire within
 *   the next 14 days.
 */
final class TodaySummary
{
    public const EXPIRING_DAYS = 14;

    private readonly CarbonImmutable $now;

    private readonly string $tz;

    public function __construct(?CarbonImmutable $now = null)
    {
        $this->tz = (string) config('app.display_timezone');
        $this->now = ($now ?? CarbonImmutable::now())->setTimezone($this->tz);
    }

    public function today(): CarbonImmutable
    {
        return $this->now->startOfDay();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} local Saturday and the Monday after */
    public function weekend(): array
    {
        $today = $this->today();
        $saturday = match ($today->dayOfWeek) {
            CarbonImmutable::SATURDAY => $today,
            CarbonImmutable::SUNDAY => $today->subDay(),
            default => $today->next(CarbonImmutable::SATURDAY),
        };

        return [$saturday, $saturday->addDays(2)];
    }

    /** @return Collection<int, Slot> */
    public function sessionsToday(): Collection
    {
        return $this->sessionsBetween($this->today(), $this->today()->addDay());
    }

    /** @return Collection<int, Slot> */
    public function sessionsThisWeekend(): Collection
    {
        [$from, $until] = $this->weekend();

        return $this->sessionsBetween($from, $until);
    }

    /**
     * @param  Collection<int, Slot>  $slots
     * @return array{sessions: int, riders: int, capacity: int}
     */
    public static function occupancy(Collection $slots): array
    {
        return [
            'sessions' => $slots->count(),
            'riders' => (int) $slots->sum(fn (Slot $slot): int => (int) $slot->seats_booked),
            'capacity' => (int) $slots->sum('capacity'),
        ];
    }

    public function followUpsDue(): int
    {
        return Lead::query()
            ->whereIn('status', [LeadStatus::New, LeadStatus::Contacted, LeadStatus::Qualified])
            ->whereDate('next_follow_up_at', '<=', $this->today()->toDateString())
            ->count();
    }

    public function weekStart(): CarbonImmutable
    {
        return $this->today()->startOfWeek(CarbonImmutable::MONDAY);
    }

    public function newLeadsThisWeek(): int
    {
        return Lead::query()->where('created_at', '>=', $this->weekStart()->utc())->count();
    }

    public function monthStart(int $monthsBack = 0): CarbonImmutable
    {
        return $this->today()->startOfMonth()->subMonthsNoOverflow($monthsBack);
    }

    /** Net revenue (minor units) of orders paid in the Pakistan month: money net of refunds plus gift voucher balance spent (OrderRevenue). */
    public function revenue(int $monthsBack = 0): int
    {
        $from = $this->monthStart($monthsBack);

        return OrderRevenue::total(Order::query()
            ->whereIn('status', LeadReport::PAID_STATUSES)
            ->where('paid_at', '>=', $from->utc())
            ->where('paid_at', '<', $from->addMonthNoOverflow()->utc()));
    }

    /** Change from last month in whole percent; null when last month had none. */
    public function revenueChangePercent(): ?int
    {
        $last = $this->revenue(1);

        return $last === 0 ? null : (int) round(($this->revenue() - $last) * 100 / $last);
    }

    /** @return array{packs: int, credits: int} */
    public function packsExpiringSoon(): array
    {
        $packs = CreditPack::query()->expiringWithin(self::EXPIRING_DAYS, $this->now)
            ->get(['credits_total', 'credits_used']);

        return [
            'packs' => $packs->count(),
            'credits' => (int) $packs->sum(fn (CreditPack $pack): int => $pack->credits_total - $pack->credits_used),
        ];
    }

    /** @return Collection<int, Slot> */
    private function sessionsBetween(CarbonImmutable $from, CarbonImmutable $until): Collection
    {
        return Slot::query()
            ->withSeatCounts()
            ->where('status', '!=', SlotStatus::Cancelled)
            ->where('starts_at', '>=', $from->utc())
            ->where('starts_at', '<', $until->utc())
            ->orderBy('starts_at')
            ->get();
    }
}
