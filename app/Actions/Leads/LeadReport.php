<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Enums\LeadSource;
use App\Enums\OrderStatus;
use App\Models\Lead;
use App\Models\Order;
use App\Support\OrderRevenue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Lead reporting (admin dashboard), over the last N Pakistan weeks
 * (Monday to Sunday):
 *  - leads by source per week;
 *  - conversion: a lead converted when its linked customer has a paid
 *    order placed on or after the lead arrived;
 *  - revenue by source: each paid order (net of refunds) counts once,
 *    for the customer's first lead before it (first touch).
 */
final class LeadReport
{
    public const PAID_STATUSES = [OrderStatus::Paid, OrderStatus::PartiallyRefunded, OrderStatus::Refunded];

    public function __construct(private readonly int $weeks = 12) {}

    public function since(?CarbonImmutable $now = null): CarbonImmutable
    {
        $tz = (string) config('app.display_timezone');
        $now = ($now ?? CarbonImmutable::now())->setTimezone($tz);

        return $now->startOfWeek(CarbonImmutable::MONDAY)->subWeeks($this->weeks - 1)->utc();
    }

    /**
     * @return array{weeks: list<string>, series: array<string, list<int>>}
     */
    public function weeklyBySource(?CarbonImmutable $now = null): array
    {
        $tz = (string) config('app.display_timezone');
        $since = $this->since($now);
        $weeks = [];

        for ($i = 0; $i < $this->weeks; $i++) {
            $weeks[] = $since->setTimezone($tz)->addWeeks($i)->toDateString();
        }

        $series = [];

        foreach ($this->leads($since) as $lead) {
            $week = $lead->created_at->setTimezone($tz)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
            $index = array_search($week, $weeks, true);

            if ($index === false) {
                continue;
            }

            $source = $lead->source->value;
            $series[$source] ??= array_fill(0, $this->weeks, 0);
            $series[$source][$index]++;
        }

        ksort($series);

        return ['weeks' => $weeks, 'series' => $series];
    }

    /**
     * @return list<array{source: LeadSource, leads: int, converted: int, rate: float, revenue_minor: int}>
     */
    public function bySource(?CarbonImmutable $now = null): array
    {
        $leads = $this->leads($this->since($now));
        $orders = $this->paidOrders($leads);

        /** @var array<string, int> $counts */
        $counts = [];
        /** @var array<string, int> $converted */
        $converted = [];
        /** @var array<string, int> $revenue */
        $revenue = [];

        foreach ($leads as $lead) {
            $key = $lead->source->value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $converted[$key] ??= 0;
            $revenue[$key] ??= 0;

            foreach ($orders[$lead->user_id ?? 0] ?? [] as $order) {
                if ($order->created_at->gte($lead->created_at)) {
                    $converted[$key]++;
                    break;
                }
            }
        }

        // Revenue: each order once, for the customer's first lead before it.
        // Money net of refunds plus gift voucher spent (OrderRevenue, milestone 10e Part B).
        $vouchers = OrderRevenue::voucherByOrder(array_map(fn (Order $o): int => $o->id, array_merge([], ...array_values($orders))));

        foreach ($orders as $userId => $userOrders) {
            $userLeads = $leads->where('user_id', $userId)->sortBy('created_at');

            foreach ($userOrders as $order) {
                $first = $userLeads->first(fn (Lead $lead): bool => $lead->created_at->lte($order->created_at));

                if ($first instanceof Lead) {
                    $revenue[$first->source->value] += OrderRevenue::cash($order) + ($vouchers[$order->id] ?? 0);
                }
            }
        }

        $rows = [];

        foreach ($counts as $key => $count) {
            $rows[] = [
                'source' => LeadSource::from($key),
                'leads' => $count,
                'converted' => $converted[$key],
                'rate' => round($converted[$key] / $count * 100, 1),
                'revenue_minor' => $revenue[$key],
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['leads'] <=> $a['leads'] ?: strcmp($a['source']->value, $b['source']->value));

        return $rows;
    }

    /** @return Collection<int, Lead> */
    private function leads(CarbonImmutable $since): Collection
    {
        return Lead::query()
            ->where('created_at', '>=', $since)
            ->get(['id', 'source', 'user_id', 'created_at']);
    }

    /**
     * Paid orders of the leads' customers, by user id.
     *
     * @param  Collection<int, Lead>  $leads
     * @return array<int, list<Order>>
     */
    private function paidOrders(Collection $leads): array
    {
        $userIds = $leads->pluck('user_id')->filter()->unique()->values()->all();

        if ($userIds === []) {
            return [];
        }

        $byUser = [];

        $orders = Order::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('status', self::PAID_STATUSES)
            ->whereNotNull('paid_at')
            ->orderBy('created_at')
            ->get(['id', 'user_id', 'amount_minor', 'amount_refunded_minor', 'created_at']);

        foreach ($orders as $order) {
            $byUser[$order->user_id][] = $order;
        }

        return $byUser;
    }
}
