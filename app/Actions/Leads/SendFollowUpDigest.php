<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Enums\LeadStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\FollowUpDigest;
use App\Support\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The 09:00 follow-up digest (milestone 10c, `leads:digest`): every admin
 * and staff member gets one email listing the open leads whose follow-up
 * is due today or overdue (the leads list's "Follow-up due" rule), their
 * own first. Nothing due: no email. Once per person per Pakistan day.
 */
final class SendFollowUpDigest
{
    public function __construct(private readonly Notifier $notifier) {}

    /** @return int emails queued */
    public function handle(?CarbonImmutable $now = null): int
    {
        $today = ($now ?? CarbonImmutable::now())->setTimezone((string) config('app.display_timezone'))->startOfDay();
        $due = $this->due($today);

        if ($due->isEmpty()) {
            return 0;
        }

        $sent = 0;

        User::query()
            ->whereIn('role', [UserRole::Admin->value, UserRole::Staff->value])
            ->orderBy('id')
            ->each(function (User $user) use ($due, $today, &$sent): void {
                $lines = $due
                    ->sortBy(fn (Lead $lead): string => ($lead->assigned_to === $user->id ? '0' : '1').$lead->next_follow_up_at?->format('Ymd').str_pad((string) $lead->id, 10, '0', STR_PAD_LEFT))
                    ->map(fn (Lead $lead): array => self::line($lead, $today, $user))
                    ->values()
                    ->all();
                $lines = array_values($lines);

                $key = 'follow_up_digest:'.$today->toDateString().':'.$user->id;

                if ($this->notifier->send($user, new FollowUpDigest($today->toDateString(), $lines), $key)) {
                    $sent++;
                }
            });

        return $sent;
    }

    /** @return Collection<int, Lead> */
    public function due(CarbonImmutable $today): Collection
    {
        return Lead::query()
            ->with('assignee')
            ->whereIn('status', [LeadStatus::New, LeadStatus::Contacted, LeadStatus::Qualified])
            ->whereDate('next_follow_up_at', '<=', $today->toDateString())
            ->orderBy('next_follow_up_at')
            ->orderBy('id')
            ->get();
    }

    /** @return array{name: string, contact: string, status: string, due: string, overdue: bool, assignee: string, mine: bool, url: string} */
    private static function line(Lead $lead, CarbonImmutable $today, User $user): array
    {
        $dueOn = $lead->next_follow_up_at;

        return [
            'name' => $lead->name,
            'contact' => $lead->contactLine(),
            'status' => $lead->status->label(),
            'due' => $dueOn === null ? '' : $dueOn->format('D j M'),
            'overdue' => $dueOn !== null && $dueOn->toDateString() < $today->toDateString(),
            'assignee' => $lead->assignee?->displayName() ?? 'Nobody',
            'mine' => $lead->assigned_to === $user->id,
            'url' => LeadResource::getUrl('view', ['record' => $lead], panel: 'admin'),
        ];
    }
}
