<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Pipeline housekeeping on a lead: assignee, next follow-up date and
 * notes. Each change is logged. (Status changes: ChangeLeadStatus.)
 */
final class UpdateLeadPipeline
{
    /**
     * @throws ValidationException
     */
    public function assign(Lead $lead, ?User $assignee, User $actor): Lead
    {
        if ($assignee !== null && ! $assignee->isTeamMember()) {
            throw ValidationException::withMessages(['assigned_to' => 'Leads can only be assigned to staff or admins.']);
        }

        $lead->assigned_to = $assignee?->id;
        $lead->save();

        activity('leads')
            ->performedOn($lead)
            ->causedBy($actor)
            ->event('assigned')
            ->withProperties(['assigned_to' => $assignee?->id])
            ->log(sprintf('Lead #%d assigned to %s', $lead->id, $assignee === null ? 'nobody' : ($assignee->name ?? $assignee->email)));

        return $lead;
    }

    public function followUp(Lead $lead, ?CarbonImmutable $date, User $actor): Lead
    {
        $lead->next_follow_up_at = $date?->startOfDay();
        $lead->save();

        activity('leads')
            ->performedOn($lead)
            ->causedBy($actor)
            ->event('follow_up')
            ->withProperties(['next_follow_up_at' => $date?->toDateString()])
            ->log(sprintf('Lead #%d follow-up %s', $lead->id, $date === null ? 'cleared' : 'set to '.$date->toDateString()));

        return $lead;
    }

    /**
     * @throws ValidationException
     */
    public function addNote(Lead $lead, string $body, User $author): LeadNote
    {
        $body = trim($body);

        if ($body === '' || mb_strlen($body) > 5000) {
            throw ValidationException::withMessages(['body' => 'Write a note of up to 5,000 characters.']);
        }

        $note = new LeadNote(['body' => $body]);
        $note->lead()->associate($lead);
        $note->author()->associate($author);
        $note->save();

        return $note;
    }
}
