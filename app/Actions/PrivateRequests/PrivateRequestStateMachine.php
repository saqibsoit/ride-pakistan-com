<?php

declare(strict_types=1);

namespace App\Actions\PrivateRequests;

use App\Actions\Orders\OrderStateMachine;
use App\Enums\PrivateRequestStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\PrivateSessionRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The only place a private session request changes status (prompt 15B).
 * Validates the transition, stamps the decision or closing time and writes
 * the audit log `private_requests`. Callers lock the row first when they
 * also change other records (ApprovePrivateRequest, SyncPrivateRequest).
 */
final class PrivateRequestStateMachine
{
    /**
     * @param  array<string, scalar|null>  $context
     *
     * @throws InvalidStatusTransition
     */
    public function transition(PrivateSessionRequest $request, PrivateRequestStatus $to, ?User $actor = null, ?string $reason = null, array $context = []): PrivateSessionRequest
    {
        $reason = OrderStateMachine::cleanReason($reason);

        if ($to->requiresReason() && $reason === null) {
            throw InvalidStatusTransition::reasonRequired('Request '.$request->reference, $to->value);
        }

        return DB::transaction(function () use ($request, $to, $actor, $reason, $context): PrivateSessionRequest {
            $locked = PrivateSessionRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw InvalidStatusTransition::between('Request '.$locked->reference, $from->value, $to->value);
            }

            // Fields the caller prepared on the passed model (slot, order, pay_by, trainer, suggested times).
            foreach (['slot_id', 'order_id', 'pay_by', 'trainer_id', 'suggested_times'] as $field) {
                if ($request->isDirty($field)) {
                    $locked->setAttribute($field, $request->getAttribute($field));
                }
            }

            $now = CarbonImmutable::now();
            $locked->status = $to;

            if (in_array($to, [PrivateRequestStatus::Approved, PrivateRequestStatus::Declined], true)) {
                $locked->decided_by = $actor?->id;
                $locked->decided_at = $now;
            }

            if ($to === PrivateRequestStatus::Declined) {
                $locked->decline_reason = $reason;
            }

            if (! $to->isOpen()) {
                $locked->closed_at = $now;
            }

            $locked->save();

            activity('private_requests')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('status_changed')
                ->withProperties([...$context, 'from' => $from->value, 'to' => $to->value, 'reason' => $reason])
                ->log(sprintf('Private request %s: %s → %s', $locked->reference, $from->label(), $to->label()));

            $request->setRawAttributes($locked->getAttributes(), true);

            return $request;
        });
    }
}
