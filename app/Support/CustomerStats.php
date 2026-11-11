<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\Leads\LeadReport;
use App\Enums\AccountCreditReason;
use App\Enums\BookingStatus;
use App\Enums\LeadSource;
use App\Enums\VoucherLedgerReason;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Per-customer figures for the admin Customers list and its export
 * (prompt 14), added as SQL columns so the table can sort and filter:
 *
 * - bookings_count: bookings that weren't cancelled
 * - last_booked_at: when the customer last booked (newest booking created,
 *   cancelled ones left out)
 * - total_paid_minor: revenue of their paid orders, the OrderRevenue rule
 *   (money received net of refunds + gift voucher spent net of what went back)
 * - lead_source: the source of their first enquiry (a lead linked to the
 *   account or sent from the same email), else "referral" when they came
 *   through a referral link, else null
 */
final class CustomerStats
{
    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public static function apply(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('users.*');
        }

        $notCancelled = fn (Builder $q) => $q->where('status', '!=', BookingStatus::Cancelled);

        return $query
            ->withCount(['bookings' => $notCancelled])
            ->withMax(['bookings as last_booked_at' => $notCancelled], 'created_at')
            ->selectRaw('('.self::totalPaidSql().') as total_paid_minor', self::paidStatusBindings(3))
            ->selectRaw('('.self::leadSourceSql().') as lead_source');
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public static function whereLeadSource(Builder $query, LeadSource $source): Builder
    {
        return $query->whereRaw('('.self::leadSourceSql().') = ?', [$source->value]);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public static function whereBooked(Builder $query, bool $booked): Builder
    {
        $notCancelled = fn (Builder $q) => $q->where('status', '!=', BookingStatus::Cancelled);

        return $booked ? $query->whereHas('bookings', $notCancelled) : $query->whereDoesntHave('bookings', $notCancelled);
    }

    /** Three sums over the customer's paid orders; each takes the paid statuses as bindings. */
    private static function totalPaidSql(): string
    {
        $paid = 'o.user_id = users.id and o.paid_at is not null and o.status in ('.self::placeholders().')';
        $spent = "'".VoucherLedgerReason::Redeemed->value."', '".VoucherLedgerReason::Restored->value."'";
        $returned = "'".AccountCreditReason::VoucherReturned->value."'";

        return 'coalesce((select sum(greatest(o.amount_minor - o.amount_refunded_minor, 0)) from orders o where '.$paid.'), 0)'
            .' + coalesce((select -sum(v.delta_minor) from voucher_ledger v join orders o on o.id = v.order_id where '.$paid.' and v.reason in ('.$spent.')), 0)'
            .' - coalesce((select sum(a.delta_minor) from account_credit_ledger a join orders o on o.id = a.order_id where '.$paid.' and a.reason = '.$returned.'), 0)';
    }

    private static function leadSourceSql(): string
    {
        return 'coalesce('
            .'(select l.source from leads l where l.user_id = users.id or (l.email is not null and l.email = users.email) order by l.created_at, l.id limit 1), '
            ."(select '".LeadSource::Referral->value."' from referrals r where r.referred_id = users.id limit 1)"
            .')';
    }

    private static function placeholders(): string
    {
        return implode(', ', array_fill(0, count(LeadReport::PAID_STATUSES), '?'));
    }

    /** @return list<string> */
    private static function paidStatusBindings(int $times): array
    {
        $statuses = array_map(fn ($status): string => $status->value, LeadReport::PAID_STATUSES);

        return array_merge(...array_fill(0, $times, $statuses));
    }
}
