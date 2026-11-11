<?php

declare(strict_types=1);

namespace App\Actions\PrivateRequests;

use App\Enums\PrivateRequestStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Exceptions\PrivateRequestRejected;
use App\Models\PrivateSessionRequest;
use App\Models\User;

/**
 * The customer withdraws their own request while it waits for an answer
 * (prompt 15B). After approval they simply don't pay; the slot is released
 * when the payment window ends.
 */
final class WithdrawPrivateRequest
{
    public function __construct(private readonly PrivateRequestStateMachine $states) {}

    /**
     * @throws PrivateRequestRejected
     */
    public function handle(PrivateSessionRequest $request, User $customer): PrivateSessionRequest
    {
        if ($request->user_id !== $customer->id) {
            throw PrivateRequestRejected::because('This isn’t your request.');
        }

        try {
            return $this->states->transition($request, PrivateRequestStatus::Withdrawn, $customer, 'Withdrawn by the customer');
        } catch (InvalidStatusTransition) {
            throw PrivateRequestRejected::because('Only a request still waiting for an answer can be withdrawn.');
        }
    }
}
