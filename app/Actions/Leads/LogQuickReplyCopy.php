<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Models\Lead;
use App\Models\QuickReply;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Records on the lead's timeline which quick reply was copied for it
 * (milestone 10c): who, when, which template, and whether the links were
 * tracked. Never the text, which may name the lead.
 */
final class LogQuickReplyCopy
{
    public function handle(Lead $lead, QuickReply $reply, User $actor, bool $tracked): void
    {
        if (! $actor->can('update', $lead) || ! $actor->can('view', $reply) || ! $reply->is_active) {
            throw new AuthorizationException('You can\'t use this reply for this lead.');
        }

        activity('leads')
            ->performedOn($lead)
            ->causedBy($actor)
            ->event('quick_reply_copied')
            ->withProperties(['quick_reply_id' => $reply->id, 'title' => $reply->title, 'tracked' => $tracked])
            ->log('Quick reply copied: '.$reply->title);
    }
}
