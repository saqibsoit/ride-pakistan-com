<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Leads\ChangeLeadStatus;
use App\Enums\LeadStatus;
use App\Events\OrderPaid;
use App\Models\Lead;

/**
 * A paid order books the customer's leads (PLAN milestone 9): every lead
 * linked to the account, plus unlinked leads with the account's email
 * (which get linked), moves to "booked" with the order recorded. Leads
 * already booked stay as they are. Lost leads count too: they came back.
 */
final class MarkLeadsBooked
{
    public function __construct(private readonly ChangeLeadStatus $change) {}

    public function handle(OrderPaid $event): void
    {
        $order = $event->order->loadMissing('user');
        $user = $order->user;
        $email = mb_strtolower($user->email);

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
}
