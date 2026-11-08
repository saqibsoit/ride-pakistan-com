<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Actions\Orders\OrderStateMachine;
use App\Enums\LeadStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Lead;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only way a lead's status changes (board, list, view page and the
 * paid-order listener). Validates the move against LeadStatus, needs a
 * reason for "lost", and writes the audit log.
 */
final class ChangeLeadStatus
{
    /**
     * @throws InvalidStatusTransition
     */
    public function handle(Lead $lead, LeadStatus $to, ?User $actor = null, ?string $reason = null, ?Order $order = null): Lead
    {
        $reason = OrderStateMachine::cleanReason($reason);

        if ($to->requiresReason() && $reason === null) {
            throw InvalidStatusTransition::reasonRequired('Lead #'.$lead->id, $to->value);
        }

        return DB::transaction(function () use ($lead, $to, $actor, $reason, $order): Lead {
            $locked = Lead::query()->whereKey($lead->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw InvalidStatusTransition::between('Lead #'.$locked->id, $from->value, $to->value);
            }

            $locked->status = $to;
            $locked->lost_reason = $to === LeadStatus::Lost ? $reason : null;

            if ($to === LeadStatus::Booked) {
                $locked->booked_at = now()->toImmutable();
                $locked->booked_order_id = $order?->id;
                $locked->next_follow_up_at = null;
            }

            $locked->save();

            activity('leads')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('status_changed')
                ->withProperties(array_filter([
                    'from' => $from->value,
                    'to' => $to->value,
                    'reason' => $reason,
                    'order' => $order?->reference,
                ], fn (mixed $value): bool => $value !== null))
                ->log(sprintf('Lead #%d: %s → %s', $locked->id, $from->label(), $to->label()));

            $lead->setRawAttributes($locked->getAttributes(), true);

            return $lead;
        });
    }
}
