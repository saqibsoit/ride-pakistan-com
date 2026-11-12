<?php

declare(strict_types=1);

namespace App\Actions\PrivateRequests;

use App\Enums\PrivateRequestStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Exceptions\PrivateRequestRejected;
use App\Models\PrivateSessionRequest;
use App\Models\User;
use App\Notifications\PrivateRequestDeclined;
use App\Support\Notifier;

/**
 * An admin declines a private session request (prompt 15B): a reason is
 * required (the customer sees it) and alternative times may be suggested.
 * The customer is emailed.
 */
final class DeclinePrivateRequest
{
    public const SUGGESTIONS_MAX_LENGTH = 500;

    public function __construct(
        private readonly PrivateRequestStateMachine $states,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @throws PrivateRequestRejected
     */
    public function handle(PrivateSessionRequest $request, User $admin, string $reason, ?string $suggestedTimes = null): PrivateSessionRequest
    {
        if (! $admin->isAdmin()) {
            throw PrivateRequestRejected::because('Only admins can decline private requests.');
        }

        if (trim($reason) === '') {
            throw PrivateRequestRejected::because('Give a reason. The customer sees it.');
        }

        $suggested = trim((string) $suggestedTimes);
        $request->suggested_times = $suggested === '' ? null : mb_substr($suggested, 0, self::SUGGESTIONS_MAX_LENGTH);

        try {
            $this->states->transition($request, PrivateRequestStatus::Declined, $admin, $reason);
        } catch (InvalidStatusTransition) {
            throw PrivateRequestRejected::notOpen();
        }

        $request->loadMissing('user');
        $this->notifier->send($request->user, new PrivateRequestDeclined($request), Notifier::key(PrivateRequestDeclined::type(), $request, $request->user));

        return $request;
    }
}
