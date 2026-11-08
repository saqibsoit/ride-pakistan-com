<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Leads\ChangeLeadStatus;
use App\Enums\LeadStatus;
use App\Events\OrderPaid;
use App\Models\Lead;
use App\Models\Order;

/**
 * A paid order books the customer's leads (PLAN milestone 9): every lead
 * linked to the account, plus unlinked leads with the account's email
 * (which get linked), moves to "booked" with the order recorded. Leads
 * already booked stay as they are. Lost leads count too: they came back.
 *
 * Milestone 10c: an order placed after a tracked DM link carries the lead
 * (`meta.lead_id`, signed link). That lead is linked to the customer too,
 * unless it already belongs to another customer: then it is left alone
 * and the mismatch is logged for the team to look at.
 */
final class MarkLeadsBooked
{
    public function __construct(private readonly ChangeLeadStatus $change) {}

    public function handle(OrderPaid $event): void
    {
        $order = $event->order->loadMissing('user');
        $user = $order->user;
        $email = mb_strtolower($user->email);

        $this->linkTrackedLead($order);

        Lead::query()
            ->whereNull('user_id')
            ->whereNotNull('email')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->update(['user_id' => $user->id]);

        Lead::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', LeadStatus::Booked)
            ->orderBy('id')
            ->get()
            ->each(fn (Lead $lead) => $this->change->handle($lead, LeadStatus::Booked, order: $order));
    }

    private function linkTrackedLead(Order $order): void
    {
        $leadId = $order->meta['lead_id'] ?? null;

        if (! is_int($leadId)) {
            return;
        }

        $lead = Lead::query()->find($leadId);

        if ($lead === null || $lead->user_id === $order->user_id) {
            return;
        }

        if ($lead->user_id !== null) {
            activity('leads')
                ->performedOn($lead)
                ->event('lead_ref_mismatch')
                ->withProperties(['order' => $order->reference])
                ->log(sprintf('Order %s came from this lead\'s DM link but was paid by another customer; lead left as it was', $order->reference));

            return;
        }

        $lead->user_id = $order->user_id;
        $lead->save();

        activity('leads')
            ->performedOn($lead)
            ->event('linked_from_dm_link')
            ->withProperties(['order' => $order->reference])
            ->log('Linked to the customer who booked from the DM link (order '.$order->reference.')');
    }
}
