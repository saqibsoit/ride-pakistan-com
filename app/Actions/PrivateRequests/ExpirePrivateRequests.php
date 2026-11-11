<?php

declare(strict_types=1);

namespace App\Actions\PrivateRequests;

use App\Enums\PrivateRequestStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\PrivateSessionRequest;
use App\Notifications\PrivateRequestExpired;
use App\Support\Notifier;
use Carbon\CarbonImmutable;

/**
 * `private-requests:expire` (every minute, prompt 15B): requests nobody
 * approved or declined by their answer time (private.answer_hours after
 * they were sent) expire, and the customer is told once. Unpaid approved
 * requests lapse through their order (holds:release → SyncPrivateRequest).
 */
final class ExpirePrivateRequests
{
    public function __construct(
        private readonly PrivateRequestStateMachine $states,
        private readonly Notifier $notifier,
    ) {}

    /** @return int how many expired */
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $expired = 0;

        PrivateSessionRequest::query()
            ->where('status', PrivateRequestStatus::Requested)
            ->where('answer_by', '<=', $now)
            ->with('user')
            ->orderBy('id')
            ->each(function (PrivateSessionRequest $request) use (&$expired): void {
                try {
                    $this->states->transition($request, PrivateRequestStatus::Expired, null, 'No answer in time');
                } catch (InvalidStatusTransition) {
                    return; // answered in the meantime
                }

                $this->notifier->send($request->user, new PrivateRequestExpired($request), Notifier::key(PrivateRequestExpired::type(), $request, $request->user));
                $expired++;
            });

        return $expired;
    }
}
